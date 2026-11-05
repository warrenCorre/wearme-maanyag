<?php

namespace App\Services;

use DateTimeImmutable;

class AssistantKnowledgeRecordBuilder
{
    /**
     * Build numeric-only records; product names and other free text require separate privacy review.
     * Reference dates describe the underlying context, not embedding freshness.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, array{source_type: string, source_key: string, reference_date: string, content_text: string}>
     */
    public function build(array $context): array
    {
        $records = [];
        $sales = $context['sales'] ?? null;
        $referenceDates = is_array($context['as_of'] ?? null) ? $context['as_of'] : [];

        if (is_array($sales) && is_array($sales['summary'] ?? null)) {
            $summary = $sales['summary'];

            if (in_array($sales['period'] ?? null, ['daily', 'weekly', 'monthly', 'yearly'], true)
                && $this->validDate($sales['date'] ?? null)
                && $this->validDate($referenceDates['sales'] ?? null)
                && $referenceDates['sales'] === $sales['date']
                && $this->validCount($summary['transaction_count'] ?? null)
                && $this->validAmount($summary['total_sales'] ?? null)
                && $this->validAmount($summary['average_sale'] ?? null)
            ) {
                $records[] = [
                    'source_type' => 'sales_summary',
                    'source_key' => $this->salesSourceKey($sales['period'], $sales['date']),
                    'reference_date' => $referenceDates['sales'],
                    'content_text' => sprintf(
                        'Sales summary; period: %s; reference date: %s; transactions: %d; total sales: PHP %s; average sale: PHP %s.',
                        $sales['period'],
                        $sales['date'],
                        $summary['transaction_count'],
                        $this->formatAmount($summary['total_sales']),
                        $this->formatAmount($summary['average_sale']),
                    ),
                ];
            }
        }

        $stats = $context['inventory']['stats'] ?? null;

        if (is_array($stats)
            && $this->validDate($referenceDates['inventory'] ?? null)
            && $this->validCount($stats['product_count'] ?? null)
            && $this->validCount($stats['total_units'] ?? null)
            && $this->validAmount($stats['stock_value'] ?? null)
        ) {
            $records[] = [
                'source_type' => 'inventory_summary',
                'source_key' => 'inventory_summary',
                'reference_date' => $referenceDates['inventory'],
                'content_text' => sprintf(
                    'Inventory summary; active products: %d; units in stock: %d; stock value: PHP %s.',
                    $stats['product_count'],
                    $stats['total_units'],
                    $this->formatAmount($stats['stock_value']),
                ),
            ];
        }

        $unpaid = $context['unpaid_summary'] ?? null;

        if (is_array($unpaid)
            && $this->validDate($referenceDates['unpaid_summary'] ?? null)
            && $this->validCount($unpaid['pending_transaction_count'] ?? null)
            && $this->validAmount($unpaid['outstanding_balance'] ?? null)
        ) {
            $records[] = [
                'source_type' => 'unpaid_summary',
                'source_key' => 'unpaid_summary',
                'reference_date' => $referenceDates['unpaid_summary'],
                'content_text' => sprintf(
                    'Unpaid summary; pending sales: %d; outstanding balance: PHP %s.',
                    $unpaid['pending_transaction_count'],
                    $this->formatAmount($unpaid['outstanding_balance']),
                ),
            ];
        }

        return $records;
    }

    private function salesSourceKey(string $period, string $date): string
    {
        $referenceDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $periodStart = match ($period) {
            'daily' => $date,
            'weekly' => $referenceDate->modify('-'.($referenceDate->format('N') - 1).' days')->format('Y-m-d'),
            'monthly' => $referenceDate->format('Y-m-01'),
            'yearly' => $referenceDate->format('Y-01-01'),
        };

        return "sales_summary:{$period}:{$periodStart}";
    }

    private function validDate(mixed $date): bool
    {
        if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
            return false;
        }

        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsedDate !== false && $parsedDate->format('Y-m-d') === $date;
    }

    private function validCount(mixed $count): bool
    {
        return is_int($count) && $count >= 0;
    }

    private function validAmount(mixed $amount): bool
    {
        return (is_int($amount) || is_float($amount)) && is_finite((float) $amount) && $amount >= 0;
    }

    private function formatAmount(int|float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
