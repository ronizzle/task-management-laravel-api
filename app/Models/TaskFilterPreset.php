<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaskFilterPreset extends Model
{
    /** @use HasFactory<\Database\Factories\TaskFilterPresetFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'filters',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
