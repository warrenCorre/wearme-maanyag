<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreActivity extends Model
{
    protected $table = 'tbl_store_activity_events';

    protected $fillable = [
        'event_type',
        'subject_type',
        'subject_id',
        'actor_id',
    ];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'actor_id' => 'integer',
        ];
    }
}
