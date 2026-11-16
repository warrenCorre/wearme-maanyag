<?php

namespace App\Services;

use DateTimeImmutable;

class AssistantVerifiedFallback
{
    /**
     * @param  array<string, mixed>  $decision
     * @param  array<string, mixed>  $context  The fresh, intent-scoped data used for the provider request.
     * @param  array<int, array{type: string, label: string, as_of: string}>  $allowedSources
     * @return array{status: 'answered', message: string, sources: array<int, array{type: string, label: string, as_of: string}>, answer_type: 'fact', confidence: 'high', visit_context: array{intent: string}}|null
     */
    public function answer(array $decision, array $context, array $allowedSources): ?array
    {
        if (count($allowedSources) !== 1) {
            return null;
        }

        $intent = $decision['intent'] ?? '';
        $message = match ($intent) {
            'sales_summary' => $this->salesMessage($decision, $context['sales'] ?? []),
            'inventory_summary' => $this->inventoryMessage($context['inventory']['stats'] ?? null),
            'unpaid_summary' => $this->unpaidMessage($context['unpaid_summary'] ?? null),
            'low_stock' => $this->listMessage($intent, $context['inventory']['low_stock_products'] ?? null),
            'best_sellers', 'collection_dates' => $this->listMessage($intent, $context[$intent] ?? null),
            default => null,
        };

        if ($message === null) {
            return null;
        }

        return [
            'status' => 'answered', 'message' => $message, 'sources' => $allowedSources,
            'answer_type' => 'fact', 'confidence' => 'high', 'visit_context' => ['intent' => $intent],
        ];
    }

    /** @param array<string, mixed> $decision */
    private function salesMessage(array $decision, mixed $sales): ?string
    {
        $summary = $sales['summary'] ?? null;
        $period = $decision['period'] ?? null;
        $date = $decision['date'] ?? null;
        $count = $summary['transaction_count'] ?? null;
        $total = $summary['total_sales'] ?? null;

        if (! in_array($period, ['daily', 'weekly', 'monthly', 'yearly'], true)
            || ($sales['period'] ?? null) !== $period || ($sales['date'] ?? null) !== $date
            || ! is_string($date) || ! $this->isCount($count) || ! $this->isMoney($total)) {
            return null;
        }

        return 'Recorded '.$period.' sales for '.$date.': '.$count.' '.($count === 1 ? 'transaction' : 'transactions').' totaling '.$this->money($total).'.';
    }

    private function inventoryMessage(mixed $stats): ?string
    {
        $products = $stats['product_count'] ?? null;
        $units = $stats['total_units'] ?? null;
        $value = $stats['stock_value'] ?? null;

        if (! $this->isCount($products) || ! $this->isCount($units) || ! $this->isMoney($value)) {
            return null;
        }

        return sprintf(
            'Recorded inventory: %d active %s, %d %s, stock value %s.',
            $products, $products === 1 ? 'product' : 'products', $units, $units === 1 ? 'unit' : 'units', $this->money($value),
        );
    }

    private function unpaidMessage(mixed $summary): ?string
    {
        $count = $summary['pending_transaction_count'] ?? null;
        $balance = $summary['outstanding_balance'] ?? null;

        if (! $this->isCount($count) || ! $this->isMoney($balance)) {
            return null;
        }

        return 'Recorded aggregate unpaid balances: '.$count.' pending '.($count === 1 ? 'sale' : 'sales').' with '.$this->money($balance).' outstanding.';
    }

    private function listMessage(string $intent, mixed $rows): ?string
    {
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > ($intent === 'best_sellers' ? 5 : 10)) {
            return null;
        }

        if ($rows === []) {
            return match ($intent) {
                'low_stock' => 'No active products are at or below their low-stock threshold.',
                'best_sellers' => 'No best-selling products can be ranked from recorded paid sales yet.',
                'collection_dates' => 'No active products have a recorded collection date.',
            };
        }

        $entries = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                return null;
            }

            $name = $row['product_name'] ?? null;
            if (! is_string($name) || trim($name) === '' || preg_match('/\p{C}/u', $name) !== 0) {
                return null;
            }

            $detail = match ($intent) {
                'low_stock' => $this->lowStockDetail($row),
                'best_sellers' => $this->bestSellerDetail($row),
                'collection_dates' => $this->collectionDateDetail($row['collection_date'] ?? null),
            };
            if ($detail === null) {
                return null;
            }

            $entries[] = trim($name).' ('.$detail.')';
        }

        $heading = match ($intent) {
            'low_stock' => 'Products at or below their stock threshold',
            'best_sellers' => 'Best sellers by units from all recorded paid sales',
            'collection_dates' => 'Most recently collected active products with recorded dates',
        };

        return $heading.': '.implode(', ', $entries).'.';
    }

    /** @param array<string, mixed> $row */
    private function lowStockDetail(array $row): ?string
    {
        $quantity = $row['quantity'] ?? null;
        $threshold = $row['low_stock_threshold'] ?? null;

        return $this->isCount($quantity) && $this->isCount($threshold) && $quantity <= $threshold
            ? $quantity.($quantity === 1 ? ' unit' : ' units').' left' : null;
    }

    /** @param array<string, mixed> $row */
    private function bestSellerDetail(array $row): ?string
    {
        $units = $row['units_sold'] ?? null;

        return is_int($units) && $units > 0 ? $units.($units === 1 ? ' paid unit' : ' paid units') : null;
    }

    private function collectionDateDetail(mixed $date): ?string
    {
        if (! is_string($date) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) !== 1) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date ? $date : null;
    }

    private function isCount(mixed $value): bool
    {
        return is_int($value) && $value >= 0;
    }

    private function isMoney(mixed $value): bool
    {
        return is_float($value) && is_finite($value) && $value >= 0;
    }

    private function money(float $value): string
    {
        return 'PHP '.number_format($value, 2, '.', ',');
    }
}
