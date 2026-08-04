<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ActivityLogger
{
    /**
     * @param  array<string, mixed>  $changes
     */
    public static function record(
        ?User $actor,
        string $action,
        Model $subject,
        string $description,
        ?int $teamId = null,
        array $changes = [],
    ): ActivityLog {
        return ActivityLog::create([
            'user_id' => $actor?->id,
            'team_id' => $teamId,
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'description' => $description,
            'changes' => $changes ?: null,
        ]);
    }
}
