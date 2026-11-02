<?php

namespace Tests\Feature;

use App\Models\Embedding;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmbeddingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_a_summary_key_without_an_underlying_source_row(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:30:00'));

        $embedding = Embedding::query()->create([
            'source_type' => 'sales_summary',
            'source_id' => null,
            'source_key' => 'sales_summary:monthly:2026-11-01',
            'reference_date' => '2026-11-01',
            'content_text' => 'Synthetic sales summary with no customer data.',
            'embedding_vector' => [0.1, 0.2],
        ]);

        $this->assertModelExists($embedding);
        $this->assertDatabaseHas('tbl_ai_embeddings', [
            'id' => $embedding->id,
            'source_type' => 'sales_summary',
            'source_id' => null,
            'source_key' => 'sales_summary:monthly:2026-11-01',
        ]);
        $this->assertSame([0.1, 0.2], $embedding->fresh()->embedding_vector);
        $this->assertSame('2026-11-01', $embedding->fresh()->reference_date->toDateString());
        $this->assertSame('2026-11-02 09:30:00', $embedding->fresh()->created_at->toDateTimeString());
    }

    public function test_it_still_stores_records_with_integer_source_ids_and_no_new_metadata(): void
    {
        $embedding = Embedding::query()->create([
            'source_type' => 'product',
            'source_id' => 42,
            'content_text' => 'Synthetic source record.',
            'embedding_vector' => [1, 0],
        ]);

        $this->assertModelExists($embedding);
        $this->assertDatabaseHas('tbl_ai_embeddings', [
            'id' => $embedding->id,
            'source_type' => 'product',
            'source_id' => 42,
            'source_key' => null,
            'reference_date' => null,
        ]);
        $this->assertSame(42, $embedding->fresh()->source_id);
        $this->assertNull($embedding->fresh()->reference_date);
    }

    public function test_it_can_update_a_vector_without_an_updated_at_column(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:30:00'));

        $embedding = Embedding::query()->create([
            'source_type' => 'inventory_summary',
            'source_key' => 'inventory_summary',
            'reference_date' => '2026-11-02',
            'content_text' => 'Synthetic old inventory summary.',
            'embedding_vector' => [1, 0],
        ]);

        $embedding->update([
            'content_text' => 'Synthetic refreshed inventory summary.',
            'embedding_vector' => [0, 1],
        ]);

        $this->assertSame('Synthetic refreshed inventory summary.', $embedding->fresh()->content_text);
        $this->assertSame([0, 1], $embedding->fresh()->embedding_vector);
        $this->assertSame('2026-11-02 09:30:00', $embedding->fresh()->created_at->toDateTimeString());
    }
}
