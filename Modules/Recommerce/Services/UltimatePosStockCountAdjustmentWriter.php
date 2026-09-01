<?php

namespace Modules\Recommerce\Services;

use App\Business;
use App\Events\StockAdjustmentCreatedOrModified;
use App\Transaction;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Recommerce\Entities\StockCountItem;
use Modules\Recommerce\Entities\StockCountSession;

/**
 * Narrow adapter over UltimatePOS's native negative stock-adjustment seam.
 * It deliberately cannot create stock: a positive physical variance needs a
 * receiving/return/identity workflow that establishes provenance first.
 */
class UltimatePosStockCountAdjustmentWriter
{
    public function __construct(protected ProductUtil $productUtil, protected TransactionUtil $transactionUtil)
    {
    }

    public function writeNegativeVariance(StockCountSession $session, StockCountItem $item, int $actorId, string $reason): array
    {
        $quantity = round((float) $item->expected_quantity - (float) $item->counted_quantity, 4);
        if ($item->item_kind !== 'NON_SERIALIZED_VARIATION' || $quantity <= 0) {
            throw new LogicException('Only a verified negative non-serialized variance may create a stock adjustment.');
        }

        $stock = DB::table('variation_location_details')
            ->where('product_id', $item->product_id)->where('variation_id', $item->variation_id)
            ->where('location_id', $session->location_id)->lockForUpdate()->first();
        if (! $stock || (float) $stock->qty_available + 0.000001 < $quantity) {
            throw new LogicException('UltimatePOS stock no longer supports this approved count adjustment.');
        }
        $business = Business::query()->find($session->business_id);
        if (! $business) {
            throw new LogicException('Stock-count business was not found.');
        }

        $transaction = Transaction::create([
            'business_id' => $session->business_id,
            'location_id' => $session->location_id,
            'type' => 'stock_adjustment', 'status' => 'received', 'payment_status' => 'paid',
            'adjustment_type' => 'normal', 'total_amount_recovered' => 0, 'final_total' => 0,
            'transaction_date' => now()->format('Y-m-d H:i:s'), 'created_by' => $actorId,
            'ref_no' => 'SB-SC-ADJ-'.strtoupper(str_replace('-', '', $session->session_uuid)).'-'.$item->id,
            'additional_notes' => $reason,
        ]);
        $line = $transaction->stock_adjustment_lines()->create([
            'product_id' => $item->product_id, 'variation_id' => $item->variation_id,
            'quantity' => $quantity, 'unit_price' => 0,
        ]);

        $this->productUtil->decreaseProductQuantity($item->product_id, $item->variation_id, $session->location_id, $quantity);
        $this->transactionUtil->mapPurchaseSell(array_merge($business->toArray(), [
            'location_id' => $session->location_id, 'accounting_method' => $business->accounting_method,
        ]), $transaction->stock_adjustment_lines, 'stock_adjustment');
        event(new StockAdjustmentCreatedOrModified($transaction, 'added'));
        $this->transactionUtil->activityLog($transaction, 'added', null, [], true, $session->business_id);

        return ['transaction_id' => (int) $transaction->id, 'line_id' => (int) $line->id, 'quantity' => $quantity];
    }
}
