<?php

namespace Tests\Feature;

use App\Services\AssistantResponseValidator;
use Tests\TestCase;

class AssistantResponseValidatorTest extends TestCase
{
    private AssistantResponseValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new AssistantResponseValidator;
    }

    public function test_it_accepts_a_grounded_structured_response(): void
    {
        $context = $this->salesContext();

        $result = $this->validator->validate(
            $this->response(),
            $context,
            $this->validator->allowedSourcesFor('sales_summary', $context),
        );

        $this->assertTrue($result['valid']);
        $this->assertNull($result['error']);
        $this->assertSame('Monthly sales for 2026-11-01 totaled PHP 125.00 across 4 transactions.', $result['response']['answer']);
        $this->assertSame('calculation', $result['response']['answer_type']);
        $this->assertSame('high', $result['response']['confidence']);
        $this->assertFalse($result['response']['refusal']);
        $this->assertSame($this->salesSources(), $result['response']['sources']);
    }

    public function test_it_maps_inventory_intents_to_their_expected_source_labels(): void
    {
        $context = $this->salesContext();
        $context['as_of']['inventory'] = '2026-11-02';

        $this->assertSame(
            [['type' => 'inventory', 'label' => 'Inventory summary', 'as_of' => '2026-11-02']],
            $this->validator->allowedSourcesFor('inventory_summary', $context),
        );
        $this->assertSame(
            [['type' => 'inventory', 'label' => 'Low-stock products', 'as_of' => '2026-11-02']],
            $this->validator->allowedSourcesFor('low_stock', $context),
        );
        $this->assertSame([], $this->validator->allowedSourcesFor('unknown_intent', $context));
    }

    public function test_collection_date_answers_require_the_collection_source_and_verified_date(): void
    {
        $context = [
            'collection_dates' => [['product_name' => 'Synthetic Scarf', 'collection_date' => '2026-10-05']],
            'as_of' => ['collection_dates' => '2026-11-02'],
        ];
        $sources = $this->validator->allowedSourcesFor('collection_dates', $context);

        $this->assertSame([
            ['type' => 'collection_dates', 'label' => 'Collection dates', 'as_of' => '2026-11-02'],
        ], $sources);

        $valid = $this->validator->validate($this->response([
            'answer' => 'Synthetic Scarf was collected on 2026-10-05.',
            'answer_type' => 'fact',
            'sources' => $sources,
        ]), $context, $sources);

        $this->assertTrue($valid['valid']);

        $unsupportedSource = $this->validator->validate($this->response([
            'answer' => 'Synthetic Scarf was collected on 2026-10-05.',
            'answer_type' => 'fact',
            'sources' => $this->salesSources(),
        ]), $context, $sources);

        $this->assertFalse($unsupportedSource['valid']);
        $this->assertSame('unsupported_source', $unsupportedSource['error']);

        $ungroundedDate = $this->validator->validate($this->response([
            'answer' => 'Synthetic Scarf was collected on 2026-12-30.',
            'answer_type' => 'fact',
            'sources' => $sources,
        ]), $context, $sources);

        $this->assertFalse($ungroundedDate['valid']);
        $this->assertSame('ungrounded_number', $ungroundedDate['error']);
    }

    public function test_it_rejects_malformed_json(): void
    {
        $result = $this->validator->validate('{', $this->salesContext(), $this->salesSources());

        $this->assertFalse($result['valid']);
        $this->assertSame('malformed_json', $result['error']);
    }

    public function test_it_rejects_missing_and_extra_keys(): void
    {
        $response = json_decode($this->response(), true, flags: JSON_THROW_ON_ERROR);
        unset($response['confidence']);

        $result = $this->validator->validate(
            json_encode($response, JSON_THROW_ON_ERROR),
            $this->salesContext(),
            $this->salesSources(),
        );

        $this->assertFalse($result['valid']);
        $this->assertSame('invalid_shape', $result['error']);

        $response = json_decode($this->response(), true, flags: JSON_THROW_ON_ERROR);
        $response['note'] = 'extra';

        $result = $this->validator->validate(
            json_encode($response, JSON_THROW_ON_ERROR),
            $this->salesContext(),
            $this->salesSources(),
        );

        $this->assertFalse($result['valid']);
        $this->assertSame('invalid_shape', $result['error']);
    }

    public function test_it_rejects_an_empty_answer(): void
    {
        $result = $this->validator->validate(
            $this->response(['answer' => '   ']),
            $this->salesContext(),
            $this->salesSources(),
        );

        $this->assertFalse($result['valid']);
        $this->assertSame('empty_answer', $result['error']);
    }

    public function test_it_rejects_an_invalid_answer_type(): void
    {
        $result = $this->validator->validate(
            $this->response(['answer_type' => 'opinion']),
            $this->salesContext(),
            $this->salesSources(),
        );

        $this->assertFalse($result['valid']);
        $this->assertSame('invalid_answer_type', $result['error']);
    }

    public function test_it_rejects_an_invalid_confidence(): void
    {
        $result = $this->validator->validate(
            $this->response(['confidence' => 'certain']),
            $this->salesContext(),
            $this->salesSources(),
        );

        $this->assertFalse($result['valid']);
        $this->assertSame('invalid_confidence', $result['error']);
    }

    public function test_it_rejects_refusal_mismatches(): void
    {
        $result = $this->validator->validate(
            $this->response(['refusal' => true]),
            $this->salesContext(),
            $this->salesSources(),
        );

        $this->assertFalse($result['valid']);
        $this->assertSame('refusal_mismatch', $result['error']);

        $result = $this->validator->validate(
            $this->response(['answer_type' => 'refusal']),
            $this->salesContext(),
            $this->salesSources(),
        );

        $this->assertFalse($result['valid']);
        $this->assertSame('refusal_mismatch', $result['error']);
    }

    public function test_it_accepts_a_refusal_without_sources(): void
    {
        $result = $this->validator->validate(
            $this->response([
                'answer' => 'The verified context does not contain that information.',
                'answer_type' => 'refusal',
                'sources' => [],
                'refusal' => true,
            ]),
            $this->salesContext(),
            $this->salesSources(),
        );

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['response']['sources']);
    }

    public function test_it_rejects_a_non_refusal_without_a_source(): void
    {
        $result = $this->validator->validate(
            $this->response(['sources' => []]),
            $this->salesContext(),
            $this->salesSources(),
        );

        $this->assertFalse($result['valid']);
        $this->assertSame('missing_sources', $result['error']);
    }

    public function test_it_rejects_a_refusal_with_a_source(): void
    {
        $result = $this->validator->validate(
            $this->response([
                'answer_type' => 'refusal',
                'refusal' => true,
            ]),
            $this->salesContext(),
            $this->salesSources(),
        );

        $this->assertFalse($result['valid']);
        $this->assertSame('unexpected_sources', $result['error']);
    }

    public function test_it_rejects_a_source_that_is_not_the_allowed_source(): void
    {
        $result = $this->validator->validate(
            $this->response([
                'sources' => [['type' => 'sales', 'label' => 'Sales report', 'as_of' => '2026-11-01']],
            ]),
            $this->salesContext(),
            $this->salesSources(),
        );

        $this->assertFalse($result['valid']);
        $this->assertSame('unsupported_source', $result['error']);
    }

    public function test_it_rejects_an_invalid_source_date(): void
    {
        $result = $this->validator->validate(
            $this->response([
                'sources' => [['type' => 'sales', 'label' => 'Sales summary', 'as_of' => '11/01/2026']],
            ]),
            $this->salesContext(),
            $this->salesSources(),
        );

        $this->assertFalse($result['valid']);
        $this->assertSame('invalid_source', $result['error']);
    }

    public function test_it_rejects_numbers_absent_from_the_verified_context(): void
    {
        $result = $this->validator->validate(
            $this->response(['answer' => 'Monthly sales totaled PHP 999.00 across 4 transactions.']),
            $this->salesContext(),
            $this->salesSources(),
        );

        $this->assertFalse($result['valid']);
        $this->assertSame('ungrounded_number', $result['error']);
    }

    public function test_it_rejects_ungrounded_date_components(): void
    {
        $result = $this->validator->validate(
            $this->response(['answer' => 'Monthly sales for 2026-99-99 totaled PHP 125.00 across 4 transactions.']),
            $this->salesContext(),
            $this->salesSources(),
        );

        $this->assertFalse($result['valid']);
        $this->assertSame('ungrounded_number', $result['error']);
    }

    public function test_it_accepts_formatted_numbers_that_match_the_context(): void
    {
        $context = $this->salesContext();
        $context['sales']['summary']['total_sales'] = 1234.5;

        $result = $this->validator->validate(
            $this->response(['answer' => 'Monthly sales totaled PHP 1,234.50 across 4 transactions.']),
            $context,
            $this->salesSources(),
        );

        $this->assertTrue($result['valid']);
    }

    /**
     * @return array<string, mixed>
     */
    private function salesContext(): array
    {
        return [
            'sales' => [
                'period' => 'monthly',
                'date' => '2026-11-01',
                'summary' => [
                    'transaction_count' => 4,
                    'total_sales' => 125.0,
                    'average_sale' => 31.25,
                ],
            ],
            'as_of' => ['sales' => '2026-11-01'],
            'scopes' => [
                'sales' => 'All recorded sales transactions, including paid, unpaid, and partially paid.',
            ],
            'allowed_sources' => $this->salesSources(),
        ];
    }

    /**
     * @return array<int, array{type: string, label: string, as_of: string}>
     */
    private function salesSources(): array
    {
        return [
            ['type' => 'sales', 'label' => 'Sales summary', 'as_of' => '2026-11-01'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function response(array $overrides = []): string
    {
        return json_encode(array_merge([
            'answer' => 'Monthly sales for 2026-11-01 totaled PHP 125.00 across 4 transactions.',
            'answer_type' => 'calculation',
            'sources' => $this->salesSources(),
            'confidence' => 'high',
            'refusal' => false,
        ], $overrides), JSON_THROW_ON_ERROR);
    }
}
