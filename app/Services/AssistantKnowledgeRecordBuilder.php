<?php

namespace App\Services;

use DateTimeImmutable;

class AssistantKnowledgeRecordBuilder
{
    /**
     * Build numeric-only records; product names and other free text require separate privacy review.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, array{source_type: string, content_text: string}>
     */
    public function build(array $context): array
    {
        $records = [];
        $sales = $context['sales'] ?? null;

        if (is_array($sales) && is_array($sales['summary'] ?? null)) {
            $summary = $sales['summary'];

            if (in_array($sales['period'] ?? null, ['daily', 'weekly', 'monthly', 'yearly'], true)
                && $this->validDate($sales['date'] ?? null)
                && $this->validCount($summary['transaction_count'] ?? null)
                && $this->validAmount($summary['total_sales'] ?? null)
                && $this->validAmount($summary['average_sale'] ?? null)
            ) {
                $records[] = [
                    'source_type' => 'sales_summary',
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
            && $this->validCount($stats['product_count'] ?? null)
            && $this->validCount($stats['total_units'] ?? null)
            && $this->validAmount($stats['stock_value'] ?? null)
        ) {
            $records[] = [
                'source_type' => 'inventory_summary',
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
            && $this->validCount($unpaid['pending_transaction_count'] ?? null)
            && $this->validCount($unpaid['pending_record_count'] ?? null)
            && $unpaid['pending_transaction_count'] <= $unpaid['pending_record_count']
            && $this->validAmount($unpaid['outstanding_balance'] ?? null)
        ) {
            $records[] = [
                'source_type' => 'unpaid_summary',
                'content_text' => sprintf(
                    'Unpaid summary; linked pending unpaid transactions: %d; pending unpaid payment records: %d; outstanding balance: PHP %s.',
                    $unpaid['pending_transaction_count'],
                    $unpaid['pending_record_count'],
                    $this->formatAmount($unpaid['outstanding_balance']),
                ),
            ];
        }

        return $records;
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
