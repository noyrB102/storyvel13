<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Idea extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'content',
        'status',
        'genre',
        'priority',
    ];

    protected $casts = [
        'priority' => 'integer',
    ];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', ['archived']);
    }

    public function scopeByPriority($query)
    {
        return $query->orderByDesc('priority')->orderByDesc('updated_at');
    }

    public function isStarred(): bool
    {
        return $this->priority > 0;
    }

    public function excerpt(int $length = 150): string
    {
        return str($this->content)->limit($length);
    }
}
