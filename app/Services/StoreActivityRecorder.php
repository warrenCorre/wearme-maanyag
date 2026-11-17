<?php

namespace App\Services;

use App\Models\StoreActivity;

class StoreActivityRecorder
{
    public function record(string $eventType, string $subjectType, int $subjectId, int $actorId): void
    {
        StoreActivity::query()->create([
            'event_type' => $eventType,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'actor_id' => $actorId,
        ]);
    }
}
