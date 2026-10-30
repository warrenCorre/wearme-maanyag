<?php

namespace Tests\Feature;

use App\Services\AssistantPromptBuilder;
use Tests\TestCase;

class AssistantPromptBuilderTest extends TestCase
{
    public function test_it_builds_a_grounded_read_only_prompt_from_allowlisted_context(): void
    {
        $prompt = app(AssistantPromptBuilder::class)->build(
            'Summarize October sales and low-stock items.',
            [
                'sales' => [
                    'period' => 'monthly',
                    'date' => '2026-10-15',
                    'summary' => [
                        'transaction_count' => 12,
                        'total_sales' => 345.67,
                        'average_sale' => 28.81,
                        'internal_note' => 'Not approved for model context.',
                    ],
                    'customer_name' => 'Synthetic Customer',
                ],
                'inventory' => [
                    'stats' => [
                        'product_count' => 20,
                        'total_units' => 52,
                        'stock_value' => 900.0,
                        'customer_contact' => '0000000000',
                    ],
                    'low_stock_products' => [
                        [
                            'product_name' => 'Synthetic Jacket',
                            'quantity' => 2,
                            'low_stock_threshold' => 3,
                            'customer_name' => 'Synthetic Customer',
                        ],
                    ],
                ],
                'best_sellers' => [
                    [
                        'product_name' => 'Synthetic Shirt',
                        'units_sold' => 8,
                        'sales_total' => 120.0,
                    ],
                ],
                'unpaid_summary' => [
                    'pending_count' => 2,
                    'outstanding_balance' => 75.0,
                    'customer_name' => 'Synthetic Customer',
                    'customer_contact' => '0000000000',
                ],
                'unpaid_records' => [
                    ['customer_name' => 'Synthetic Customer'],
                ],
            ],
        );

        $this->assertStringContainsString('Summarize October sales and low-stock items.', $prompt['input']);
        $this->assertStringContainsString('Verified system context (JSON):', $prompt['input']);
        $this->assertStringContainsString('only using the verified system context', $prompt['system_instruction']);
        $this->assertStringContainsString('Do not claim to create, change, delete, archive, or settle', $prompt['system_instruction']);

        $contextMarker = 'Verified system context (JSON):';
        $contextJson = substr($prompt['input'], strpos($prompt['input'], $contextMarker) + strlen($contextMarker));
        $safeContext = json_decode(trim($contextJson), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(345.67, $safeContext['sales']['summary']['total_sales']);
        $this->assertSame('Synthetic Jacket', $safeContext['inventory']['low_stock_products'][0]['product_name']);
        $this->assertSame(2, $safeContext['unpaid_summary']['pending_count']);
        $this->assertArrayNotHasKey('customer_name', $safeContext['sales']);
        $this->assertArrayNotHasKey('customer_contact', $safeContext['inventory']['stats']);
        $this->assertArrayNotHasKey('customer_name', $safeContext['inventory']['low_stock_products'][0]);
        $this->assertArrayNotHasKey('customer_name', $safeContext['unpaid_summary']);
        $this->assertArrayNotHasKey('unpaid_records', $safeContext);
        $this->assertArrayNotHasKey('internal_note', $safeContext['sales']['summary']);
        $this->assertStringNotContainsString('Synthetic Customer', $contextJson);
    }
}
