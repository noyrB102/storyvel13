<?php

namespace App\Jobs;

use App\Ai\Agents\StoryAgent;
use App\Models\Story;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Ai\Files;

class GenerateStoryContent implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function __construct(public Story $story) {}

    public function handle(): void
    {
        $this->story->update(['status' => 'generating']);

        try {
            $attachments = [];

            foreach ($this->story->attachments ?? [] as $attachment) {
                $mime = $attachment['mime'] ?? '';

                if (str_starts_with($mime, 'image/')) {
                    $attachments[] = Files\Image::fromStorage($attachment['path']);
                } else {
                    $attachments[] = Files\Document::fromStorage($attachment['path']);
                }
            }

            $formatInstructions = match ($this->story->format ?? 'explore') {
                'short_story' => "Write a complete, self-contained short story of approximately 600–900 words (1–2 pages). "
                    . "It must have a clear beginning, middle, and end. Write the full prose — do not outline or ask questions. "
                    . "Start writing the story immediately.\n\n",
                'chapter'     => "Write a complete first chapter of approximately 2,000–3,000 words. "
                    . "Include rich scene-setting, character introduction, and end on a hook that makes the reader want more. "
                    . "Write the full prose — do not outline or ask questions.\n\n",
                'outline'     => "Create a detailed chapter-by-chapter outline for a full novel based on this premise. "
                    . "Include: overall story arc, 3-act structure breakdown, a list of 10–15 chapters each with a 2–3 sentence summary, "
                    . "main characters with brief descriptions, and key themes. Do not write prose yet.\n\n",
                'author_voice' => "You are a gentle editor and writing coach, NOT a ghostwriter. "
                    . "The author has given you their own words, ideas, and voice. Your job is to help THEM tell THEIR story.\n\n"
                    . "Rules you must follow:\n"
                    . "1. Preserve the author's own sentences, rhythm, and word choices wherever possible.\n"
                    . "2. Fix spelling and punctuation errors silently — never comment on them.\n"
                    . "3. Stay strictly within the boundaries of what the author has written. The subject, characters, setting, and events are defined by THEIR draft. Do not introduce any character, place, subplot, or event that is not already present or clearly implied in their words.\n"
                    . "4. If the author's draft is thin in places, expand using only details they have already mentioned or that are logically required to connect what they wrote. Ask yourself: 'Did the author give me this detail, or am I inventing it?' If you invented it, remove it.\n"
                    . "5. If the author's voice is casual, keep it casual. If it is lyrical, keep it lyrical.\n"
                    . "6. Write the story as a complete, polished piece in the author's own style.\n"
                    . "7. Do NOT rewrite in a more 'literary' style unless the author's own writing already is.\n"
                    . "8. After the story, add a brief 'Writing Coach Note' section (3–5 bullet points) highlighting what is strong in their writing and one or two gentle suggestions for their next draft. In these suggestions, only reference the story the author actually wrote — do not suggest adding new characters or plot threads they didn't start.\n\n",
                default       => '', // explore: let Claude respond naturally
            };

            $prompt = $this->story->prompt;

            if ($this->story->title) {
                $prompt = "Title: {$this->story->title}\n\n" . $prompt;
            }

            $voiceNotes = $this->story->voice_notes ?? [];
            if (! empty($voiceNotes)) {
                $voiceContext = "\n\n--- Author's Guided Notes ---\n";
                if (! empty($voiceNotes['characters'])) {
                    $voiceContext .= "Characters & their voices: " . $voiceNotes['characters'] . "\n";
                }
                if (! empty($voiceNotes['emotional_core'])) {
                    $voiceContext .= "The central emotional moment: " . $voiceNotes['emotional_core'] . "\n";
                }
                if (! empty($voiceNotes['tone'])) {
                    $voiceContext .= "Tone & style: " . $voiceNotes['tone'] . "\n";
                }
                if (! empty($voiceNotes['pov'])) {
                    $voiceContext .= "Point of view: " . $voiceNotes['pov'] . "\n";
                }
                if (! empty($voiceNotes['ending'])) {
                    $endingInstruction = match ($voiceNotes['ending']) {
                        'full_circle'       => "End the story with a satisfying full-circle moment that ties back to how it began.",
                        'funny'             => "End the story on a light, warm, funny note that brings a smile.",
                        'thought_provoking' => "End the story with a thought-provoking reflection that lingers with the reader.",
                        'moral'             => "End the story with a gentle moral or life lesson, woven in naturally — never preachy.",
                        'simple'            => "End the story simply and naturally, with a quiet, understated close.",
                        default             => '',
                    };
                    if ($endingInstruction !== '') {
                        $voiceContext .= "How to end the story: " . $endingInstruction . "\n";
                    }
                }
                $prompt .= $voiceContext;
            }

            $prompt = $formatInstructions . $prompt;

            $response = (new StoryAgent($this->story))->prompt(
                $prompt,
                attachments: $attachments,
            );

            $title = $this->story->title;

            if (! $title && preg_match('/^#\s+(.+)$/m', $response->text, $matches)) {
                $title = trim($matches[1]);
            }

            $this->story->update([
                'title'   => $title,
                'content' => $response->text,
                'status'  => 'completed',
            ]);

            GenerateCoverImage::dispatch($this->story);
        } catch (\Throwable $e) {
            $this->story->update(['status' => 'failed']);
            throw $e;
        }
    }
}
