<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class VoiceTranscriptionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'audio' => 'required|file|mimes:wav,mp3,mp4,m4a,webm,ogg,flac,mpga|max:10240',
        ]);

        $apiKey = config('ai.providers.openai.key');
        if (blank($apiKey)) {
            Log::warning('Voice transcription requested without OpenAI API key.');
            return response()->json(['error' => 'Voice transcription is not configured.'], 503);
        }

        $file = $request->file('audio');
        $path = $file->store('voice-tmp', 'local');
        $fullPath = Storage::disk('local')->path($path);

        $url = rtrim(config('ai.providers.openai.url'), '/').'/audio/transcriptions';

        try {
            $response = Http::withToken($apiKey)
                ->timeout(120)
                ->asMultipart()
                ->attach('file', fopen($fullPath, 'r'), 'audio.wav')
                ->post($url, [
                    'model' => 'whisper-1',
                    'language' => 'en',
                    'response_format' => 'json',
                ]);
        } catch (\Throwable $e) {
            Log::warning('Voice transcription request failed.', ['exception' => $e::class]);
            Storage::disk('local')->delete($path);
            return response()->json(['error' => 'Could not reach transcription service.'], 503);
        }

        Storage::disk('local')->delete($path);

        if ($response->failed()) {
            Log::warning('Voice transcription provider returned an error.', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return response()->json(['error' => 'Transcription failed.'], 500);
        }

        return response()->json(['text' => $response->json('text')]);
    }
}
