<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
    ];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'reference_date' => 'date',
            'embedding_vector' => 'array',
        ];
    }
}
