<?php

namespace Modules\Recommerce\Services;

use App\Business;
use App\Contact;
use App\Events\StockAdjustmentCreatedOrModified;
use App\Transaction;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Recommerce\Entities\RepairPartUsage;

/**
 * Adapter for the existing Ultimate POS stock-adjustment transaction path.
 * The caller owns the transaction; this adapter never writes qty_available
 * directly and never opens a nested transaction.
 */
class UltimatePosStockAdjustmentWriter
{
    public function __construct(
        protected ProductUtil $productUtil,
        protected TransactionUtil $transactionUtil
    ) {
    }

    public function write(RepairPartUsage $usage, int $actorId, string $reason): array
    {
        if ($usage->consumption_path !== 'INTERNAL' || $usage->status !== 'INSTALLED_PENDING_BILLING') {
            throw new LogicException('Only an installed internal part usage can create a stock adjustment.');
        }

        $business = Business::query()->whereKey($usage->business_id)->first();
        if (! $business) {
            throw new LogicException('Stock adjustment business was not found.');
        }

        // Legacy Ultimate POS schemas require a contact on every transaction,
        // including stock adjustments. Internal Repair work has no customer,
        // so use the business walk-in customer as the accounting placeholder.
        $contactId = $usage->job?->contact_id;
        if (! $contactId) {
            $contactId = Contact::query()
                ->where('business_id', $usage->business_id)
                ->where('name', 'Walk-In Customer')
                ->value('id');
        }
        if (! $contactId) {
            throw new LogicException('A business walk-in customer is required for the internal stock adjustment.');
        }

        $stock = DB::table('variation_location_details')
            ->where('variation_id', $usage->variation_id)
            ->where('product_id', $usage->product_id)
            ->where('location_id', $usage->location_id)
            ->lockForUpdate()
            ->first();
        if (! $stock || (float) $stock->qty_available < (float) $usage->quantity) {
            throw new LogicException('Core stock is insufficient for the internal part adjustment.');
        }

        $transaction = Transaction::create([
            'business_id' => $usage->business_id,
            'location_id' => $usage->location_id,
            'contact_id' => $contactId,
            'type' => 'stock_adjustment',
            'status' => 'received',
            'payment_status' => 'paid',
            'adjustment_type' => 'normal',
            'total_amount_recovered' => 0,
            'transaction_date' => now()->format('Y-m-d H:i:s'),
            'final_total' => 0,
            'additional_notes' => $reason,
            'ref_no' => 'SB-RP-ADJ-'.strtoupper(str_replace('-', '', $usage->usage_uuid)),
            'created_by' => $actorId,
        ]);

        $line = $transaction->stock_adjustment_lines()->create([
            'product_id' => $usage->product_id,
            'variation_id' => $usage->variation_id,
            'quantity' => $usage->quantity,
            'unit_price' => 0,
        ]);

        // This is the reviewed Ultimate POS mutation seam. Do not replace it
        // with a direct variation_location_details update.
        $this->productUtil->decreaseProductQuantity(
            $usage->product_id,
            $usage->variation_id,
            $usage->location_id,
            $usage->quantity
        );

        $this->transactionUtil->mapPurchaseSell(
            array_merge($business->toArray(), [
                'location_id' => $usage->location_id,
                'accounting_method' => $business->accounting_method,
            ]),
            $transaction->stock_adjustment_lines,
            'stock_adjustment'
        );

        $mapped = DB::table('transaction_sell_lines_purchase_lines as map')
            ->join('purchase_lines as purchase', 'purchase.id', '=', 'map.purchase_line_id')
            ->where('map.stock_adjustment_line_id', $line->id)
            ->selectRaw('COALESCE(SUM(map.quantity), 0) as mapped_quantity, COALESCE(SUM(map.quantity * purchase.purchase_price_inc_tax), 0) as actual_cost')
            ->first();

        if (! $mapped || (float) $mapped->mapped_quantity < (float) $usage->quantity) {
            throw new LogicException('POS stock adjustment did not produce a complete purchase-layer mapping.');
        }

        event(new StockAdjustmentCreatedOrModified($transaction, 'added'));
        $this->transactionUtil->activityLog($transaction, 'added', null, [], true, $usage->business_id);

        return [
            'transaction_id' => (int) $transaction->id,
            'line_id' => (int) $line->id,
            'actual_cost' => (float) $mapped->actual_cost,
            'quantity' => (float) $usage->quantity,
        ];
    }
}
