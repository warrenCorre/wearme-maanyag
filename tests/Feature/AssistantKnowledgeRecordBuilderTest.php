<?php

namespace Tests\Feature;

use App\Services\AssistantKnowledgeRecordBuilder;
use Tests\TestCase;

class AssistantKnowledgeRecordBuilderTest extends TestCase
{
    public function test_it_builds_only_allowlisted_numeric_summary_records(): void
    {
        $context = [
            'sales' => [
                'period' => 'monthly',
                'date' => '2026-11-01',
                'summary' => [
                    'transaction_count' => 3,
                    'total_sales' => 250.5,
                    'average_sale' => 83.5,
                    'customer_name' => 'Synthetic Customer',
                ],
                'transaction_no' => 'SYNTHETIC-SECRET',
            ],
            'inventory' => [
                'stats' => [
                    'product_count' => 2,
                    'total_units' => 7,
                    'stock_value' => 120.0,
                    'customer_contact' => '0000000000',
                ],
                'low_stock_products' => [['product_name' => 'Synthetic Jacket', 'quantity' => 2]],
            ],
            'best_sellers' => [['product_name' => 'Synthetic Shirt', 'units_sold' => 5]],
            'unpaid_summary' => [
                'pending_count' => 2,
                'outstanding_balance' => 35.25,
                'customer_name' => 'Synthetic Customer',
                'customer_contact' => '0000000000',
            ],
            'unpaid_records' => [['customer_name' => 'Synthetic Customer']],
        ];

        $records = app(AssistantKnowledgeRecordBuilder::class)->build($context);

        $this->assertSame([
            [
                'source_type' => 'sales_summary',
                'content_text' => 'Sales summary; period: monthly; reference date: 2026-11-01; transactions: 3; total sales: PHP 250.50; average sale: PHP 83.50.',
            ],
            [
                'source_type' => 'inventory_summary',
                'content_text' => 'Inventory summary; active products: 2; units in stock: 7; stock value: PHP 120.00.',
            ],
            [
                'source_type' => 'unpaid_summary',
                'content_text' => 'Unpaid summary; pending unpaid records: 2; outstanding balance: PHP 35.25.',
            ],
        ], $records);
        $this->assertStringNotContainsString('Synthetic Customer', json_encode($records, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('Synthetic Jacket', json_encode($records, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('SYNTHETIC-SECRET', json_encode($records, JSON_THROW_ON_ERROR));
    }

    public function test_it_omits_invalid_or_incomplete_summaries_instead_of_guessing_values(): void
    {
        $context = [
            'sales' => [
                'period' => 'monthly',
                'date' => '2026-02-30',
                'summary' => ['transaction_count' => 1, 'total_sales' => 10.0, 'average_sale' => 10.0],
            ],
            'inventory' => [
                'stats' => ['product_count' => '2', 'total_units' => 3, 'stock_value' => 20.0],
            ],
            'unpaid_summary' => ['pending_count' => 1, 'outstanding_balance' => INF],
        ];

        $records = app(AssistantKnowledgeRecordBuilder::class)->build($context);

        $this->assertSame([], $records);
    }

    public function test_it_keeps_valid_zero_totals_without_inventing_missing_sections(): void
    {
        $context = [
            'sales' => [
                'period' => 'daily',
                'date' => '2026-11-01',
                'summary' => ['transaction_count' => 0, 'total_sales' => 0.0, 'average_sale' => 0.0],
            ],
            'inventory' => ['stats' => ['product_count' => 3]],
            'unpaid_summary' => ['pending_count' => 0, 'outstanding_balance' => 0.0],
        ];

        $records = app(AssistantKnowledgeRecordBuilder::class)->build($context);

        $this->assertSame([
            [
                'source_type' => 'sales_summary',
                'content_text' => 'Sales summary; period: daily; reference date: 2026-11-01; transactions: 0; total sales: PHP 0.00; average sale: PHP 0.00.',
            ],
            [
                'source_type' => 'unpaid_summary',
                'content_text' => 'Unpaid summary; pending unpaid records: 0; outstanding balance: PHP 0.00.',
            ],
        ], $records);
    }
}
