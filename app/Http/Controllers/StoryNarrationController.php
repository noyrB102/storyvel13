<?php

namespace App\Http\Controllers;

use App\Models\Story;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class StoryNarrationController extends Controller
{
    public function __invoke(Story $story, Request $request): BinaryFileResponse
    {
        abort_if($story->user_id !== auth()->id(), 403);

        $options = $request->validate([
            'voice' => 'required|in:female,male',
        ]);

        $content = preg_split('/^#+\s*Writing Coach.*$/mi', $story->content ?? '')[0];
        if ($story->title) {
            $content = preg_replace('/^#+\s*'.preg_quote($story->title, '/').'\s*(?:\n|$)/mi', '', $content, 1);
        }

        $content = html_entity_decode(strip_tags((string) Str::markdown(trim($content))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        abort_if($content === '', 422);

        $voices = [
            'female' => 'coral',
            'male' => 'onyx',
        ];
        $instructions = 'Read as a warm, expressive storyteller sharing a treasured personal memory. Use natural phrasing, gentle emotion, varied emphasis, and comfortable pauses. Never sound theatrical or rushed.';

        $input = trim(($story->title ? $story->title.".\n\n" : '').$content);
        $voice = $voices[$options['voice']];
        $speed = 1.0;
        $cacheKey = hash('sha256', implode('|', [
            'gpt-4o-mini-tts',
            $voice,
            (string) $speed,
            $instructions,
            $input,
        ]));
        $path = "story-narrations/{$story->id}/{$cacheKey}.mp3";

        if (! Storage::disk('local')->exists($path)) {
            $apiKey = config('ai.providers.openai.key');
            abort_if(blank($apiKey), 503);

            try {
                $response = Http::withToken($apiKey)
                    ->accept('audio/mpeg')
                    ->timeout(120)
                    ->retry(2, 500, throw: false)
                    ->post(rtrim(config('ai.providers.openai.url'), '/').'/audio/speech', [
                        'model' => 'gpt-4o-mini-tts',
                        'voice' => $voice,
                        'input' => $input,
                        'instructions' => $instructions,
                        'response_format' => 'mp3',
                        'speed' => $speed,
                    ]);
            } catch (Throwable $exception) {
                Log::warning('Story narration request failed.', [
                    'story_id' => $story->id,
                    'exception' => $exception::class,
                ]);
                abort(503);
            }

            if ($response->failed() || ! str_starts_with($response->header('Content-Type', ''), 'audio/')) {
                Log::warning('Story narration provider returned an error.', [
                    'story_id' => $story->id,
                    'status' => $response->status(),
                ]);
                abort(503);
            }

            abort_unless(Storage::disk('local')->put($path, $response->body()), 503);
        }

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'audio/mpeg',
            'Content-Disposition' => 'inline; filename="story-narration.mp3"',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
