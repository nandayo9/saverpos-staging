<?php

namespace Modules\Recommerce\Services;

use App\Events\PurchaseCreatedOrModified;
use App\PurchaseLine;
use App\Transaction;
use App\User;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use Carbon\Carbon;
use LogicException;

/**
 * Source-reviewed adapter for the existing Ultimate POS purchase primitives.
 *
 * This class is deliberately not a controller and does not open its own
 * transaction. TrackedReceivingService owns the transaction so a failed core
 * purchase or failed Device evidence write rolls back as one unit.
 */
class UltimatePosPurchaseWriter
{
    public function __construct(
        protected ProductUtil $productUtil,
        protected TransactionUtil $transactionUtil
    ) {
    }

    /**
     * Create one ordinary received purchase line for the tracked unit batch.
     *
     * The date is intentionally interpreted using the same business-session
     * format as PurchaseController::store(). The caller must invoke this from
     * an authenticated business context with the business session loaded.
     */
    public function write(User $user, array $command): array
    {
        $businessId = (int) $command['business_id'];
        $locationId = (int) $command['location_id'];
        $productId = (int) $command['product_id'];
        $variationId = (int) $command['variation_id'];
        $purchase = $command['purchase'] ?? null;
        $units = $command['units'] ?? null;

        if (! is_array($purchase) || ! is_array($units) || $units === []) {
            throw new LogicException('Core purchase writer requires a normalized receiving command.');
        }

        $quantity = count($units);
        $unitPurchasePrice = (float) $purchase['unit_purchase_price'];
        $unitPurchasePriceIncTax = (float) $purchase['unit_purchase_price_inc_tax'];
        $unitItemTax = (float) $purchase['unit_item_tax'];

        if ($unitPurchasePriceIncTax < $unitPurchasePrice
            || abs(($unitPurchasePriceIncTax - $unitPurchasePrice) - $unitItemTax) > 0.0001) {
            throw new LogicException('Core purchase writer received inconsistent unit tax values.');
        }

        $transactionDate = $this->normalizeTransactionDate((string) $purchase['transaction_date']);
        if (! is_string($transactionDate) || trim($transactionDate) === '') {
            throw new LogicException('Core purchase writer requires the business-session date format.');
        }

        $totalBeforeTax = round($unitPurchasePrice * $quantity, 4);
        $totalTax = round($unitItemTax * $quantity, 4);
        $shippingCharges = (float) ($purchase['shipping_charges'] ?? 0);
        $finalTotal = round(($unitPurchasePriceIncTax * $quantity) + $shippingCharges, 4);

        $currencyDetails = $this->transactionUtil->purchaseCurrencyDetails($businessId);
        $referenceCount = $this->productUtil->setAndGetReferenceCount('purchase', $businessId);
        $referenceNumber = $this->productUtil->generateReferenceNumber(
            'purchase',
            $referenceCount,
            $businessId
        );

        $transaction = Transaction::create([
            'business_id' => $businessId,
            'location_id' => $locationId,
            'type' => 'purchase',
            'status' => 'received',
            'payment_status' => 'due',
            'contact_id' => (int) $purchase['contact_id'],
            'invoice_no' => null,
            'ref_no' => $referenceNumber,
            'transaction_date' => $transactionDate,
            'total_before_tax' => $totalBeforeTax,
            'tax_id' => $purchase['tax_id'] ?? null,
            'tax_amount' => $totalTax,
            'discount_type' => null,
            'discount_amount' => 0,
            'shipping_details' => null,
            'shipping_charges' => $shippingCharges,
            'additional_notes' => $purchase['additional_notes'] ?? null,
            'final_total' => $finalTotal,
            'created_by' => (int) $user->id,
            'exchange_rate' => 1,
            'source' => 'recommerce',
        ]);

        $this->productUtil->createOrUpdatePurchaseLines(
            $transaction,
            [[
                'product_id' => $productId,
                'variation_id' => $variationId,
                'quantity' => $quantity,
                'pp_without_discount' => $unitPurchasePrice,
                'discount_percent' => 0,
                'purchase_price' => $unitPurchasePrice,
                'purchase_price_inc_tax' => $unitPurchasePriceIncTax,
                'item_tax' => $unitItemTax,
                'purchase_line_tax_id' => $purchase['tax_id'] ?? null,
                'lot_number' => null,
                'mfg_date' => null,
                'exp_date' => null,
                'sub_unit_id' => null,
                'purchase_order_line_id' => null,
                'purchase_requisition_line_id' => null,
                'secondary_unit_quantity' => null,
            ]],
            $currencyDetails,
            false
        );

        // No payment is created by this receiving slice; the core utility still
        // owns payment-status calculation and its related module callbacks.
        $this->transactionUtil->createOrUpdatePaymentLines(
            $transaction,
            [],
            $businessId,
            (int) $user->id,
            false
        );
        $this->transactionUtil->updatePaymentStatus($transaction->id, $transaction->final_total);
        $this->productUtil->adjustStockOverSelling($transaction);
        $this->transactionUtil->activityLog($transaction, 'added', null, [], true, $businessId);
        PurchaseCreatedOrModified::dispatch($transaction);

        $purchaseLine = PurchaseLine::query()
            ->where('transaction_id', $transaction->id)
            ->where('product_id', $productId)
            ->where('variation_id', $variationId)
            ->latest('id')
            ->first();

        if (! $purchaseLine) {
            throw new LogicException('Core purchase writer did not persist a purchase line.');
        }

        return [
            'transaction_id' => (int) $transaction->id,
            'purchase_line_id' => (int) $purchaseLine->id,
            'quantity' => (float) $purchaseLine->quantity,
            'business_id' => $businessId,
            'location_id' => $locationId,
            'product_id' => $productId,
            'variation_id' => $variationId,
        ];
    }

    protected function normalizeTransactionDate(string $value): ?string
    {
        $value = trim($value);
        $originalValue = $value;

        $dateFormat = session('business.date_format');

        // The Recommerce browser form submits the native ISO date value. In
        // a normal business session it is converted to the configured display
        // format and passed through the shared POS utility below. A local
        // fixture may not have a business date-format session at all; retain
        // the same utility call first, then allow a narrowly scoped local
        // fallback so the isolated browser demo remains usable. Tests and
        // non-local environments still fail closed when the session is absent.
        $isIsoDate = preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $value) === 1;
        if ($isIsoDate && app()->environment('local') && (bool) config('recommerce.enabled')) {
            try {
                return Carbon::parse($value)->format('Y-m-d H:i:s');
            } catch (\Throwable $exception) {
                throw new LogicException('Core purchase writer received an invalid ISO transaction date.', 0, $exception);
            }
        }

        if ($isIsoDate && (! is_string($dateFormat) || $dateFormat === '')) {
            $normalized = $this->productUtil->uf_date($value, true);
            if (is_string($normalized) && trim($normalized) !== '') {
                return $normalized;
            }

            if (app()->environment('local') && (bool) config('recommerce.enabled')) {
                try {
                    return Carbon::parse($value)->format('Y-m-d H:i:s');
                } catch (\Throwable $exception) {
                    throw new LogicException('Core purchase writer received an invalid ISO transaction date.', 0, $exception);
                }
            }

            return $normalized;
        }

        // The receiving UI uses the browser-native ISO date value. Convert it
        // to the business display format before handing it to the shared POS
        // date utility, which also expects a time component.
        if (is_string($dateFormat)
            && $dateFormat !== ''
            && preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2}(?::\d{2})?))?$/', trim($value), $matches) === 1) {
            try {
                $datePart = Carbon::createFromFormat('Y-m-d', $matches[1])->format($dateFormat);
            } catch (\Throwable $exception) {
                throw new LogicException('Core purchase writer received an invalid transaction date.', 0, $exception);
            }

            $value = $datePart.(! empty($matches[2]) ? ' '.$matches[2] : '');
        }

        if (is_string($dateFormat) && $dateFormat !== ''
            && preg_match('/\d{1,2}:\d{2}/', $value) !== 1) {
            $value .= session('business.time_format') == 12 ? ' 12:00 AM' : ' 00:00';
        }

        try {
            return $this->productUtil->uf_date($value, true);
        } catch (\Throwable $exception) {
            if (app()->environment('local') && (bool) config('recommerce.enabled') && $isIsoDate) {
                try {
                    return Carbon::parse($originalValue)->format('Y-m-d H:i:s');
                } catch (\Throwable $fallbackException) {
                    throw new LogicException('Core purchase writer received an invalid ISO transaction date.', 0, $fallbackException);
                }
            }

            throw $exception;
        }
    }
}
