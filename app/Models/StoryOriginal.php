<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoryOriginal extends Model
{
    protected $fillable = [
        'story_id',
        'user_id',
        'title',
        'content',
        'format',
    ];

    protected $casts = [
        'story_id' => 'integer',
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
