<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
use LogicException;
final class InvoiceAccountingService
{
    public static function assertShopLockForDate(int $shopId, string $date): void
    {
        $lockDate = DB::table('shops')->where('id', $shopId)->value('financial_lock_date');
        if ($lockDate && $date <= $lockDate) {
            throw new LogicException("Financial lock is active through {$lockDate}.");
        }
    }
}
