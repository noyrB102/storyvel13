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
        $title = $this->story->title ?? 'a captivating story';
        $genre = $this->story->genre ?? 'fiction';

        $prompt = "A cinematic book cover illustration for a {$genre} story titled '{$title}'. "
            . 'Dramatic lighting, rich colors, professional book cover art, highly detailed.';

        $image = Image::of($prompt)
            ->portrait()
            ->quality('high')
            ->generate();

        $path = $image->storePubliclyAs(
            'covers/' . $this->story->id . '.png',
            disk: 'public'
        );

        $this->story->update(['cover_image_path' => $path]);
    }
}
