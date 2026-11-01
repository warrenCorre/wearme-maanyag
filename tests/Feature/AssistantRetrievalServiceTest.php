<?php

namespace Tests\Feature;

use App\Services\AssistantRetrievalService;
use InvalidArgumentException;
use Tests\TestCase;

class AssistantRetrievalServiceTest extends TestCase
{
    public function test_it_returns_the_top_matching_vetted_records_in_stable_order(): void
    {
        $records = [
            ['source_type' => 'sales_summary', 'source_id' => 2, 'content_text' => 'Synthetic second summary', 'embedding_vector' => [1, 0]],
            ['source_type' => 'inventory_summary', 'source_id' => 4, 'content_text' => 'Synthetic inventory summary', 'embedding_vector' => [0.6, 0.8]],
            ['source_type' => 'sales_summary', 'source_id' => 1, 'content_text' => 'Synthetic first summary', 'embedding_vector' => [1, 0], 'internal_note' => 'Do not forward'],
        ];

        $results = app(AssistantRetrievalService::class)->retrieve([1, 0], $records, 2);

        $this->assertSame([
            ['source_type' => 'sales_summary', 'source_id' => 1, 'content_text' => 'Synthetic first summary', 'score' => 1.0],
            ['source_type' => 'sales_summary', 'source_id' => 2, 'content_text' => 'Synthetic second summary', 'score' => 1.0],
        ], $results);
    }

    public function test_it_skips_unapproved_sources_malformed_vectors_and_nonmatching_records(): void
    {
        $records = [
            ['source_type' => 'sales_transaction', 'source_id' => 2, 'content_text' => 'Synthetic private transaction', 'embedding_vector' => [1, 0]],
            ['source_type' => 'product', 'source_id' => 12, 'content_text' => 'Synthetic unvetted product description', 'embedding_vector' => [1, 0]],
            ['source_type' => 'sales_summary', 'source_id' => 3, 'content_text' => 'Wrong dimension', 'embedding_vector' => [1, 0, 0]],
            ['source_type' => 'sales_summary', 'source_id' => 4, 'content_text' => 'Zero vector', 'embedding_vector' => [0, 0]],
            ['source_type' => 'sales_summary', 'source_id' => 5, 'content_text' => 'Not finite', 'embedding_vector' => [INF, 0]],
            ['source_type' => 'sales_summary', 'source_id' => 6, 'content_text' => 'Invalid value', 'embedding_vector' => ['1', 0]],
            ['source_type' => 'sales_summary', 'source_id' => 7, 'content_text' => 'Not a list', 'embedding_vector' => [1 => 1, 2 => 0]],
            ['source_type' => 'sales_summary', 'source_id' => 8, 'content_text' => '', 'embedding_vector' => [1, 0]],
            ['source_type' => 'sales_summary', 'source_id' => 0, 'content_text' => 'Invalid ID', 'embedding_vector' => [1, 0]],
            ['source_type' => 'sales_summary', 'source_id' => 9, 'content_text' => 'Opposite vector', 'embedding_vector' => [-1, 0]],
            ['source_type' => 'sales_summary', 'source_id' => 10, 'content_text' => 'No match', 'embedding_vector' => [0, 1]],
            ['source_type' => 'unpaid_summary', 'source_id' => 11, 'content_text' => 'Synthetic aggregate count only', 'embedding_vector' => [1, 0]],
        ];

        $results = app(AssistantRetrievalService::class)->retrieve([1, 0], $records);

        $this->assertSame([
            ['source_type' => 'unpaid_summary', 'source_id' => 11, 'content_text' => 'Synthetic aggregate count only', 'score' => 1.0],
        ], $results);
    }

    public function test_it_returns_no_results_for_invalid_query_vectors_or_empty_candidates(): void
    {
        $retriever = app(AssistantRetrievalService::class);
        $records = [
            ['source_type' => 'sales_summary', 'source_id' => 1, 'content_text' => 'Synthetic summary', 'embedding_vector' => [1, 0]],
        ];

        foreach ([[], [0, 0], [INF, 1], [NAN, 1], ['x' => 1, 'y' => 0], [1, '0']] as $invalidVector) {
            $this->assertSame([], $retriever->retrieve($invalidVector, $records));
        }

        $this->assertSame([], $retriever->retrieve([1, 0], []));
    }

    public function test_it_rejects_an_unbounded_result_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(AssistantRetrievalService::class)->retrieve([1, 0], [], 11);
    }

    public function test_it_rejects_a_nonpositive_result_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(AssistantRetrievalService::class)->retrieve([1, 0], [], 0);
    }
}
