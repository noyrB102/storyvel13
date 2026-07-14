<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Story extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'author_name',
        'prompt',
        'content',
        'status',
        'is_private',
        'cover_image_path',
        'genre',
        'format',
        'attachments',
        'voice_notes',
    ];

    protected $casts = [
        'attachments' => 'array',
        'voice_notes' => 'array',
        'is_private'  => 'boolean',
    ];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StoryMessage::class)->orderBy('created_at');
    }

    public function original(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(StoryOriginal::class);
    }

    public function isGenerating(): bool
    {
        return $this->status === 'generating';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function books(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'book_story')
            ->withPivot('position');
    }
}
