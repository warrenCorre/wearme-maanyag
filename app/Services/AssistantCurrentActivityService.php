<?php

namespace App\Services;

use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\SalesTransaction;
use App\Models\UnpaidPayment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class AssistantCurrentActivityService
{
    public function answer(?CarbonInterface $referenceDate = null): string
    {
        $today = CarbonImmutable::instance($referenceDate ?? CarbonImmutable::now('Asia/Manila'))
            ->setTimezone('Asia/Manila')
            ->startOfDay();
        $start = $today->utc()->format('Y-m-d H:i:s');
        $end = $today->addDay()->utc()->format('Y-m-d H:i:s');

        $sales = SalesTransaction::query()
            ->where('transaction_date', '>=', $start)
            ->where('transaction_date', '<', $end)
            ->toBase()
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw('COALESCE(SUM(total_amount), 0) as transaction_value')
            ->first();
        $adjustments = InventoryAdjustment::query()
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->count();
        $productsAdded = Product::withTrashed()
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->count();
        $collectionsDatedToday = Product::query()
            ->whereDate('collection_date', $today->toDateString())
            ->count();
        $settlements = UnpaidPayment::query()
            ->where('status', 'settled')
            ->where('payment_date', '>=', $start)
            ->where('payment_date', '<', $end)
            ->count();

        return sprintf(
            "Recorded store activity for %s (Philippine time):\nSales recorded: %d (transaction value PHP %s; may include unpaid sales).\nNon-sales stock adjustments: %d.\nProducts added: %d.\nProducts dated for collection today: %d.\nPreviously unpaid sales settled: %d.\nThis covers recorded categories only; product edits and partial payments are not fully tracked.",
            $today->format('F j, Y'),
            (int) $sales->transaction_count,
            number_format((float) $sales->transaction_value, 2),
            $adjustments,
            $productsAdded,
            $collectionsDatedToday,
            $settlements,
        );
    }
}
