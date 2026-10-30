<?php
namespace App\Services;
use App\Models\{User, ExpenseCategory};
use App\Support\{Audit, Money};
use Illuminate\Support\Facades\DB;
class InventoryService
{
    public function move(int $itemId, string $type, string $quantity, string $reason, User $actor): void
    {
        DB::transaction(function () use ($itemId, $type, $quantity, $reason, $actor) {
            $item = DB::table('stock_items')->where('id', $itemId)->lockForUpdate()->first(); abort_unless($item, 404);
            abort_unless(preg_match('/^-?\d+(?:\.\d{1,3})?$/D', $quantity) && bccomp($quantity, '0', 3) !== 0, 422, 'Enter a non-zero quantity with at most three decimal places.');
            $delta = in_array($type, ['issue', 'damage'], true) ? '-'.ltrim($quantity, '-') : $quantity;
            abort_if($type !== 'adjustment' && bccomp($quantity, '0', 3) < 0, 422, 'Use a positive quantity for this movement type.');
            abort_if($type === 'opening' && DB::table('stock_movements')->where('stock_item_id', $itemId)->exists(), 422, 'Opening stock can only be entered before other movements.');
            $balance = bcadd((string) $item->quantity, $delta, 3); abort_if(bccomp($balance, '0', 3) < 0, 422, 'Stock cannot fall below zero.');
            abort_if(bccomp($balance, '99999999999.999', 3) > 0, 422, 'Stock quantity exceeds the supported maximum.');
            DB::table('stock_items')->where('id', $itemId)->update(['quantity' => $balance, 'updated_at' => now()]);
            DB::table('stock_movements')->insert(['stock_item_id' => $itemId, 'type' => $type, 'quantity_delta' => $delta, 'balance' => $balance, 'reason' => $reason, 'user_id' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('stock_movement', null, ['quantity' => $item->quantity], ['item_id' => $itemId, 'delta' => $delta, 'balance' => $balance], $reason, 'Audited stock movement');
        });
    }
    public function receive(int $purchaseId, User $actor): void
    {
        DB::transaction(function () use ($purchaseId, $actor) {
            $purchase = DB::table('purchase_requests')->where('id', $purchaseId)->lockForUpdate()->first();
            abort_unless($purchase && $purchase->status === 'ordered', 422, 'Only an ordered purchase can be received once.');
            $this->move($purchase->stock_item_id, 'receipt', (string) $purchase->quantity, 'Goods received for purchase order '.$purchase->order_no, $actor);
            $supplier = DB::table('suppliers')->find($purchase->supplier_id);
            $expense = app(FinanceService::class)->recordExpense(['expense_category_id' => ExpenseCategory::firstOrCreate(['name' => 'Inventory purchases'])->id, 'expense_date' => today()->toDateString(), 'payee' => $supplier->name, 'amount_minor' => $purchase->total_minor, 'payment_method' => 'bank_transfer', 'description' => 'Goods received: '.$purchase->order_no], $actor);
            DB::table('purchase_requests')->where('id', $purchaseId)->update(['status' => 'received', 'expense_id' => $expense->id, 'updated_at' => now()]);
            Audit::record('purchase_received', $expense, [], ['purchase_id' => $purchaseId], null, 'Goods received with one linked expense approval request');
        });
    }
}

