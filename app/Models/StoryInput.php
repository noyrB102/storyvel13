<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoryInput extends Model
{
    protected $fillable = [
        'story_id',
        'user_id',
        'summary',
        'topic',
        'characters',
        'obstacle',
        'setting',
        'outcome',
        'detail',
        'extra_context',
    ];

    protected $casts = [
        'story_id' => 'integer',
        'user_id'  => 'integer',
    ];

    public function story(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
