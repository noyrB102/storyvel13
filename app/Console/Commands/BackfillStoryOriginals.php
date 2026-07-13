<?php

namespace App\Console\Commands;

use App\Models\Story;
use App\Models\StoryOriginal;
use Illuminate\Console\Command;

class BackfillStoryOriginals extends Command
{
    protected $signature = 'backfill:story-originals';

    protected $description = 'Copy existing completed stories into story_originals as immutable backups';

    public function handle(): int
    {
        $stories = Story::whereNotNull('content')
            ->where('status', 'completed')
            ->whereDoesntHave('original')
            ->get();

        if ($stories->isEmpty()) {
            $this->info('No stories need backfilling — all done!');
            return self::SUCCESS;
        }

        $this->info("Backfilling {$stories->count()} stories...");
        $bar = $this->output->createProgressBar($stories->count());
        $bar->start();

        foreach ($stories as $story) {
            StoryOriginal::firstOrCreate(
                ['story_id' => $story->id],
                [
                    'user_id' => $story->user_id,
                    'title'   => $story->title,
                    'content' => $story->content,
                    'format'  => $story->format,
                ]
            );
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Done! All originals saved.');

        return self::SUCCESS;
    }
}
