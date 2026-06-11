<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoryMessage extends Model
{
    protected $fillable = ['story_id', 'role', 'content', 'accepted_at', 'declined_at'];

    protected $casts = [
        'accepted_at' => 'datetime',
        'declined_at' => 'datetime',
    ];

    public function story(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Story::class);
    }
}
