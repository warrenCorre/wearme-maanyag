<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIAssistantLog extends Model
{
    protected $table = 'tbl_ai_assistant_logs';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}