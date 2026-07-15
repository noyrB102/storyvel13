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

    /**
     * Detect when the AI asked for more details instead of writing a real story.
     * This happens when the user provides minimal input (e.g. one-word answers).
     */
    public function needsMoreDetail(): bool
    {
        $content = $this->content ?? '';
        if ($content === '' || $this->status !== 'completed') {
            return false;
        }

        // Look for telltale phrases the AI uses when it can't write a real story
        $indicators = [
            'share the actual memory',
            'What happened in this memory',
            'key details I need',
            'need you to share',
            'tell me about the true memory',
            'share a little more',
            'haven\'t come through clearly',
            'details appear to be incomplete',
            'fill in the actual details',
            'Could you please share',
            'What memory or experience you want to capture',
        ];

        foreach ($indicators as $phrase) {
            if (stripos($content, $phrase) !== false) {
                return true;
            }
        }

        return false;
    }

    public function books(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'book_story')
            ->withPivot('position');
    }
}
