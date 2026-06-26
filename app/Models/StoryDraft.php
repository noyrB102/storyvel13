<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoryDraft extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'step',
        'data',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
