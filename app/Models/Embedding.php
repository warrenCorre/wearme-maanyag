<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Stored embedding metadata.
 *
 * `source_key` is the canonical identity for idempotent upserts. `content_hash`
 * is the SHA-256 of the exact `content_text` used to generate the vector, and
 * `embedding_model` identifies the model that produced it. `reference_date`
 * describes the source context, not whether the embedding is fresh.
 */
class Embedding extends Model
{
    protected $table = 'tbl_ai_embeddings';

    protected $fillable = [
        'source_type',
        'source_id',
        'source_key',
        'reference_date',
        'content_text',
        'embedding_vector',
        'content_hash',
        'embedding_model',
        'embedding_dimensions',
    ];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'reference_date' => 'date',
            'embedding_vector' => 'array',
            'embedding_dimensions' => 'integer',
        ];
    }

    /**
     * Determine whether this embedding was generated from the given canonical content and model.
     */
    public function isFreshFor(string $contentText, string $embeddingModel): bool
    {
        return $this->content_hash === hash('sha256', $contentText)
            && $this->embedding_model === $embeddingModel;
    }
}
