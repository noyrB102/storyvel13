<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoryPreviousVersion extends Model
{
    protected $fillable = [
        'story_id',
        'user_id',
        'title',
        'author_name',
        'genre',
        'content',
        'is_private',
        'is_edited',
    ];

    protected $casts = [
        'story_id' => 'integer',
        'is_private' => 'boolean',
        'is_edited' => 'boolean',
    ];

    public function story(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Story::class);
    }
}
