<?php

namespace App\Jobs;

use App\Models\Story;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Ai\Image;

class GenerateCoverImage implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function __construct(public Story $story) {}

    public function handle(): void
    {
        $title = $this->story->title ?? 'a personal memory';

        // Use the generated story content for context, falling back to the user's prompt.
        $source = trim($this->story->content ?: $this->story->prompt ?: '');
        $summary = $source !== '' ? substr(str_replace(["\n", "\r"], ' ', $source), 0, 500) : '';

        $prompt = "A warm, light, photorealistic documentary-style photograph for a true personal memoir. "
            . "Soft natural daylight, gentle nostalgic mood, real-world setting, no text, no fantasy, no dark dramatic lighting, no book cover typography. "
            . "Evoke the memory: '{$title}'. ";

        if ($summary !== '') {
            $prompt .= "Story context: {$summary}";
        }

        $image = Image::of($prompt)
            ->landscape()
            ->quality('high')
            ->generate();

        $path = $image->storePubliclyAs(
            'covers/' . $this->story->id . '.png',
            disk: 'public'
        );

        $this->story->update([
            'cover_image_path' => $path,
            'updated_at' => now(),
        ]);
    }
}
