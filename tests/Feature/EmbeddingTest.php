<?php

namespace Tests\Feature;

use App\Models\Embedding;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmbeddingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_a_summary_key_without_an_underlying_source_row(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:30:00'));
        $content = 'Synthetic sales summary with no customer data.';

        $embedding = Embedding::query()->create([
            'source_type' => 'sales_summary',
            'source_id' => null,
            'source_key' => 'sales_summary:monthly:2026-11-01',
            'reference_date' => '2026-11-01',
            'content_text' => $content,
            'embedding_vector' => [0.1, 0.2],
            'content_hash' => hash('sha256', $content),
            'embedding_model' => 'gemini-embedding-001',
            'embedding_dimensions' => 2,
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
        $this->assertSame(hash('sha256', $content), $embedding->fresh()->content_hash);
        $this->assertSame(2, $embedding->fresh()->embedding_dimensions);
        $this->assertTrue($embedding->fresh()->isFreshFor($content, 'gemini-embedding-001'));
    }

    public function test_it_stores_a_canonical_key_for_a_record_backed_source(): void
    {
        $content = 'Synthetic source record.';

        $embedding = Embedding::query()->create([
            'source_type' => 'product',
            'source_id' => 42,
            'source_key' => 'product:42',
            'content_text' => $content,
            'embedding_vector' => [1, 0],
            'content_hash' => hash('sha256', $content),
            'embedding_model' => 'gemini-embedding-001',
            'embedding_dimensions' => 2,
        ]);

        $this->assertModelExists($embedding);
        $this->assertDatabaseHas('tbl_ai_embeddings', [
            'id' => $embedding->id,
            'source_type' => 'product',
            'source_id' => 42,
            'source_key' => 'product:42',
            'reference_date' => null,
        ]);
        $this->assertSame(42, $embedding->fresh()->source_id);
        $this->assertNull($embedding->fresh()->reference_date);
    }

    public function test_it_can_update_a_vector_without_an_updated_at_column(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:30:00'));
        $originalContent = 'Synthetic old inventory summary.';

        $embedding = Embedding::query()->create([
            'source_type' => 'inventory_summary',
            'source_key' => 'inventory_summary',
            'reference_date' => '2026-11-02',
            'content_text' => $originalContent,
            'embedding_vector' => [1, 0],
            'content_hash' => hash('sha256', $originalContent),
            'embedding_model' => 'gemini-embedding-001',
            'embedding_dimensions' => 2,
        ]);

        $refreshedContent = 'Synthetic refreshed inventory summary.';

        $embedding->update([
            'content_text' => $refreshedContent,
            'embedding_vector' => [0, 1],
        ]);

        $embedding->refresh();

        $this->assertSame($refreshedContent, $embedding->content_text);
        $this->assertSame([0, 1], $embedding->embedding_vector);
        $this->assertSame('2026-11-02 09:30:00', $embedding->created_at->toDateTimeString());
        // The hash still describes the old content, so the changed text is detected as stale.
        $this->assertSame(hash('sha256', $originalContent), $embedding->content_hash);
        $this->assertFalse($embedding->isFreshFor($refreshedContent, 'gemini-embedding-001'));
    }

    public function test_it_detects_stale_content_and_model_changes(): void
    {
        $content = 'Synthetic inventory summary.';

        $embedding = Embedding::query()->create([
            'source_type' => 'inventory_summary',
            'source_key' => 'inventory_summary',
            'reference_date' => '2026-11-02',
            'content_text' => $content,
            'embedding_vector' => [1, 0],
            'content_hash' => hash('sha256', $content),
            'embedding_model' => 'gemini-embedding-001',
            'embedding_dimensions' => 2,
        ]);

        $this->assertTrue($embedding->isFreshFor($content, 'gemini-embedding-001'));
        $this->assertFalse($embedding->isFreshFor('Changed inventory summary.', 'gemini-embedding-001'));
        $this->assertFalse($embedding->isFreshFor($content, 'another-embedding-model'));
    }

    public function test_source_key_is_required(): void
    {
        $this->expectException(QueryException::class);

        Embedding::query()->create([
            'source_type' => 'inventory_summary',
            'content_text' => 'Synthetic inventory summary.',
            'embedding_vector' => [1, 0],
            'content_hash' => hash('sha256', 'Synthetic inventory summary.'),
            'embedding_model' => 'gemini-embedding-001',
            'embedding_dimensions' => 2,
        ]);
    }

    public function test_source_type_and_source_key_are_unique(): void
    {
        $attributes = [
            'source_type' => 'inventory_summary',
            'source_key' => 'inventory_summary',
            'content_text' => 'Synthetic inventory summary.',
            'embedding_vector' => [1, 0],
            'content_hash' => hash('sha256', 'Synthetic inventory summary.'),
            'embedding_model' => 'gemini-embedding-001',
            'embedding_dimensions' => 2,
        ];

        Embedding::query()->create($attributes);

        $this->expectException(QueryException::class);

        Embedding::query()->create($attributes);
    }
}
