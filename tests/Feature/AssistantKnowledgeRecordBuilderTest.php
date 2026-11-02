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
                'pending_transaction_count' => 1,
                'pending_record_count' => 2,
                'outstanding_balance' => 35.25,
                'customer_name' => 'Synthetic Customer',
                'customer_contact' => '0000000000',
            ],
            'as_of' => [
                'sales' => '2026-11-01',
                'inventory' => '2026-11-02',
                'unpaid_summary' => '2026-11-02',
                'customer_contact' => '0000000000',
            ],
            'unpaid_records' => [['customer_name' => 'Synthetic Customer']],
        ];

        $records = app(AssistantKnowledgeRecordBuilder::class)->build($context);

        $this->assertSame([
            [
                'source_type' => 'sales_summary',
                'source_key' => 'sales_summary:monthly:2026-11-01',
                'reference_date' => '2026-11-01',
                'content_text' => 'Sales summary; period: monthly; reference date: 2026-11-01; transactions: 3; total sales: PHP 250.50; average sale: PHP 83.50.',
            ],
            [
                'source_type' => 'inventory_summary',
                'source_key' => 'inventory_summary',
                'reference_date' => '2026-11-02',
                'content_text' => 'Inventory summary; active products: 2; units in stock: 7; stock value: PHP 120.00.',
            ],
            [
                'source_type' => 'unpaid_summary',
                'source_key' => 'unpaid_summary',
                'reference_date' => '2026-11-02',
                'content_text' => 'Unpaid summary; linked pending unpaid transactions: 1; pending unpaid payment records: 2; outstanding balance: PHP 35.25.',
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
            'unpaid_summary' => ['pending_transaction_count' => 1, 'pending_record_count' => 2, 'outstanding_balance' => INF],
            'as_of' => ['sales' => '2026-02-28', 'inventory' => '2026-11-02', 'unpaid_summary' => '2026-11-02'],
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
            'unpaid_summary' => ['pending_transaction_count' => 0, 'pending_record_count' => 0, 'outstanding_balance' => 0.0],
            'as_of' => ['sales' => '2026-11-01', 'unpaid_summary' => '2026-11-02'],
        ];

        $records = app(AssistantKnowledgeRecordBuilder::class)->build($context);

        $this->assertSame([
            [
                'source_type' => 'sales_summary',
                'source_key' => 'sales_summary:daily:2026-11-01',
                'reference_date' => '2026-11-01',
                'content_text' => 'Sales summary; period: daily; reference date: 2026-11-01; transactions: 0; total sales: PHP 0.00; average sale: PHP 0.00.',
            ],
            [
                'source_type' => 'unpaid_summary',
                'source_key' => 'unpaid_summary',
                'reference_date' => '2026-11-02',
                'content_text' => 'Unpaid summary; linked pending unpaid transactions: 0; pending unpaid payment records: 0; outstanding balance: PHP 0.00.',
            ],
        ], $records);
    }

    public function test_it_omits_unpaid_summary_when_linked_transaction_count_exceeds_pending_records(): void
    {
        $records = app(AssistantKnowledgeRecordBuilder::class)->build([
            'unpaid_summary' => [
                'pending_transaction_count' => 3,
                'pending_record_count' => 2,
                'outstanding_balance' => 25.0,
            ],
            'as_of' => ['unpaid_summary' => '2026-11-02'],
        ]);

        $this->assertSame([], $records);
    }

    public function test_it_keeps_snapshot_identities_stable_when_values_and_reference_dates_change(): void
    {
        $builder = app(AssistantKnowledgeRecordBuilder::class);
        $first = $builder->build([
            'inventory' => ['stats' => ['product_count' => 2, 'total_units' => 7, 'stock_value' => 120.0]],
            'unpaid_summary' => ['pending_transaction_count' => 1, 'pending_record_count' => 2, 'outstanding_balance' => 35.25],
            'as_of' => ['inventory' => '2026-11-02', 'unpaid_summary' => '2026-11-02'],
        ]);
        $second = $builder->build([
            'inventory' => ['stats' => ['product_count' => 3, 'total_units' => 8, 'stock_value' => 150.0]],
            'unpaid_summary' => ['pending_transaction_count' => 0, 'pending_record_count' => 0, 'outstanding_balance' => 0.0],
            'as_of' => ['inventory' => '2026-11-03', 'unpaid_summary' => '2026-11-03'],
        ]);

        $this->assertSame(['inventory_summary', 'unpaid_summary'], array_column($first, 'source_key'));
        $this->assertSame(array_column($first, 'source_key'), array_column($second, 'source_key'));
        $this->assertSame(['2026-11-02', '2026-11-02'], array_column($first, 'reference_date'));
        $this->assertSame(['2026-11-03', '2026-11-03'], array_column($second, 'reference_date'));
    }

    public function test_it_distinguishes_sales_periods_and_reference_dates_without_using_totals_as_identity(): void
    {
        $builder = app(AssistantKnowledgeRecordBuilder::class);
        $summary = ['transaction_count' => 3, 'total_sales' => 250.5, 'average_sale' => 83.5];
        $daily = $builder->build([
            'sales' => ['period' => 'daily', 'date' => '2026-11-01', 'summary' => $summary],
            'as_of' => ['sales' => '2026-11-01'],
        ]);
        $monthly = $builder->build([
            'sales' => ['period' => 'monthly', 'date' => '2026-11-01', 'summary' => $summary],
            'as_of' => ['sales' => '2026-11-01'],
        ]);
        $laterThisMonth = $builder->build([
            'sales' => ['period' => 'monthly', 'date' => '2026-11-15', 'summary' => $summary],
            'as_of' => ['sales' => '2026-11-15'],
        ]);
        $nextMonth = $builder->build([
            'sales' => ['period' => 'monthly', 'date' => '2026-12-01', 'summary' => $summary],
            'as_of' => ['sales' => '2026-12-01'],
        ]);

        $this->assertSame('sales_summary:daily:2026-11-01', $daily[0]['source_key']);
        $this->assertSame('sales_summary:monthly:2026-11-01', $monthly[0]['source_key']);
        $this->assertSame($monthly[0]['source_key'], $laterThisMonth[0]['source_key']);
        $this->assertSame('2026-11-15', $laterThisMonth[0]['reference_date']);
        $this->assertSame('sales_summary:monthly:2026-12-01', $nextMonth[0]['source_key']);
    }

    public function test_it_uses_monday_and_january_first_for_weekly_and_yearly_sales_keys(): void
    {
        $builder = app(AssistantKnowledgeRecordBuilder::class);
        $summary = ['transaction_count' => 0, 'total_sales' => 0.0, 'average_sale' => 0.0];
        $weekly = $builder->build([
            'sales' => ['period' => 'weekly', 'date' => '2027-01-01', 'summary' => $summary],
            'as_of' => ['sales' => '2027-01-01'],
        ]);
        $yearly = $builder->build([
            'sales' => ['period' => 'yearly', 'date' => '2026-11-15', 'summary' => $summary],
            'as_of' => ['sales' => '2026-11-15'],
        ]);

        $this->assertSame('sales_summary:weekly:2026-12-28', $weekly[0]['source_key']);
        $this->assertSame('sales_summary:yearly:2026-01-01', $yearly[0]['source_key']);
    }

    public function test_it_omits_summaries_with_missing_invalid_or_conflicting_reference_dates(): void
    {
        $builder = app(AssistantKnowledgeRecordBuilder::class);
        $summary = ['transaction_count' => 3, 'total_sales' => 250.5, 'average_sale' => 83.5];
        $context = [
            'sales' => ['period' => 'monthly', 'date' => '2026-11-01', 'summary' => $summary],
            'inventory' => ['stats' => ['product_count' => 2, 'total_units' => 7, 'stock_value' => 120.0]],
            'unpaid_summary' => ['pending_transaction_count' => 1, 'pending_record_count' => 2, 'outstanding_balance' => 35.25],
        ];

        $this->assertSame([], $builder->build($context));
        $this->assertSame([], $builder->build($context + ['as_of' => '2026-11-02']));

        $records = $builder->build($context + [
            'as_of' => ['sales' => '2026-11-02', 'inventory' => '2026-02-30', 'unpaid_summary' => '2026-11-02'],
        ]);

        $this->assertSame(['unpaid_summary'], array_column($records, 'source_key'));
    }
}
