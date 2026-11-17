<?php

namespace App\Services;

use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\SalesTransaction;
use App\Models\StoreActivity;
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

        $eventCounts = StoreActivity::query()
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->selectRaw('event_type, COUNT(*) as event_count')
            ->groupBy('event_type')
            ->pluck('event_count', 'event_type');
        $trackedChanges = [
            'product edits' => (int) ($eventCounts['product_updated'] ?? 0),
            'product archives' => (int) ($eventCounts['product_archived'] ?? 0),
            'product restores' => (int) ($eventCounts['product_restored'] ?? 0),
            'product removals' => (int) ($eventCounts['product_deleted'] ?? 0),
            'category changes' => (int) ($eventCounts['category_created'] ?? 0)
                + (int) ($eventCounts['category_updated'] ?? 0)
                + (int) ($eventCounts['category_status_changed'] ?? 0),
            'unpaid sale edits' => (int) ($eventCounts['sale_updated'] ?? 0),
            'partial payments' => (int) ($eventCounts['payment_partial'] ?? 0),
            'cashier account changes' => (int) ($eventCounts['cashier_created'] ?? 0)
                + (int) ($eventCounts['cashier_updated'] ?? 0)
                + (int) ($eventCounts['cashier_status_changed'] ?? 0)
                + (int) ($eventCounts['cashier_deleted'] ?? 0),
            'profile updates' => (int) ($eventCounts['profile_updated'] ?? 0),
            'password resets' => (int) ($eventCounts['password_reset'] ?? 0),
        ];
        $recordedChanges = collect($trackedChanges)
            ->filter(fn (int $count): bool => $count > 0)
            ->map(fn (int $count, string $label): string => "{$label}: {$count}")
            ->values()
            ->implode('; ');
        $recentEvents = StoreActivity::query()
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['event_type', 'created_at']);
        $recentActions = $recentEvents
            ->map(fn (StoreActivity $event): string => sprintf(
                '%s (%s Philippine time)',
                $this->eventLabel($event->event_type),
                $event->created_at->setTimezone('Asia/Manila')->format('g:i A'),
            ))
            ->implode('; ');

        return sprintf(
            "Recorded store activity for %s (Philippine time):\nSales recorded: %d (transaction value PHP %s; may include unpaid sales).\nNon-sales stock adjustments: %d.\nProducts added in current records: %d.\nPreviously unpaid sales settled: %d.\nOther tracked changes: %s.\nRecent tracked actions: %s.\nScheduled product collection dates today: %d (a date does not confirm collection happened).\nOlder edits, partial payments, and account changes before activity tracking began cannot be reconstructed. Activity outside this app is not recorded.",
            $today->format('F j, Y'),
            (int) $sales->transaction_count,
            number_format((float) $sales->transaction_value, 2),
            $adjustments,
            $productsAdded,
            $settlements,
            $recordedChanges === '' ? 'none' : $recordedChanges,
            $recentActions === '' ? 'none since tracking began' : $recentActions,
            $collectionsDatedToday,
        );
    }

    private function eventLabel(string $eventType): string
    {
        return match ($eventType) {
            'product_created' => 'Product added',
            'product_updated' => 'Product edited',
            'product_archived' => 'Product archived',
            'product_restored' => 'Product restored',
            'product_deleted' => 'Product permanently removed',
            'category_created' => 'Category added',
            'category_updated' => 'Category edited',
            'category_status_changed' => 'Category status changed',
            'inventory_adjusted' => 'Stock adjusted',
            'sale_created_paid', 'sale_created_unpaid' => 'Sale recorded',
            'sale_updated' => 'Unpaid sale edited',
            'payment_partial' => 'Partial payment recorded',
            'payment_settled' => 'Unpaid sale settled',
            'cashier_created' => 'Cashier account added',
            'cashier_updated' => 'Cashier account edited',
            'cashier_status_changed' => 'Cashier account status changed',
            'cashier_deleted' => 'Cashier account removed',
            'profile_updated' => 'Profile updated',
            'password_reset' => 'Password reset',
            default => 'Store record changed',
        };
    }
}
