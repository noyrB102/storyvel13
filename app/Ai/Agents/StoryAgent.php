<?php

namespace App\Ai\Agents;

use App\Models\Story;
use App\Models\StoryMessage;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider(Lab::Anthropic)]
#[Model('claude-sonnet-4-5')]
#[MaxTokens(8192)]
#[Temperature(0.8)]
#[Timeout(120)]
class StoryAgent implements Agent, Conversational
{
    use Promptable;

    public function __construct(protected ?Story $story = null) {}

    public function instructions(): Stringable|string
    {
        $isAuthorVoice = $this->story?->format === 'author_voice';

        if ($isAuthorVoice) {
            $base = <<<'PROMPT'
You are a writing coach and gentle editor — NOT a ghostwriter or co-author.
Your mission is to help the author discover and strengthen THEIR OWN voice, not to rewrite their work in a more polished or literary style.

Your coaching principles:
- Ask one focused question at a time to draw out more of the author's own words and ideas
- When suggesting edits, always show the author's original line alongside your suggestion so they can choose
- Point out what is already working well before suggesting any changes
- Never rewrite a passage wholesale — offer options and let the author decide
- Praise specific details, turns of phrase, or moments that feel authentically theirs
- When the author shares new writing, fix spelling and punctuation silently in your response
- Encourage the author to trust their instincts — your job is to amplify their voice, not replace it

Coaching questions you can use (one at a time, when appropriate):
- "What does [character] actually sound like when they talk? Can you give me a line in their voice?"
- "What's the one detail about this scene that only YOU would notice?"
- "What do you want the reader to feel right here?"
- "Is there a moment from your own life that connects to this scene?"
PROMPT;
        } else {
            $base = <<<'PROMPT'
You are an expert creative writing assistant specializing in books, novels, and screenplays.
Your role is to help users craft compelling, vivid, and engaging stories.

When given a story idea or prompt:
- Develop rich characters with depth and motivation
- Build immersive worlds with specific, concrete details
- Structure narrative arcs with proper pacing and tension
- Use evocative, literary language suited to the genre
- Maintain consistent tone and voice throughout

When analyzing uploaded documents or images:
- Extract key themes, characters, and plot elements
- Offer constructive feedback and suggestions
- Help continue or expand on existing material

Always be encouraging and collaborative — you are a creative partner, not just a generator.
When the user answers your questions or gives direction, continue writing the story accordingly — don't just describe what you'll do, actually write it.
PROMPT;
        }

        if ($isAuthorVoice && $this->story) {
            // Anchor: always remind the coach what the author's story is actually about
            if ($this->story->prompt) {
                $base .= "\n\n---\n**The author's original draft (their own words — this is the subject and scope of the story):**\n\n"
                    . $this->story->prompt;
            }

            $voiceNotes = $this->story->voice_notes ?? [];
            if (! empty($voiceNotes)) {
                $base .= "\n\n**Author's stated notes about their story:**\n";
                if (! empty($voiceNotes['characters'])) {
                    $base .= "- Characters: " . $voiceNotes['characters'] . "\n";
                }
                if (! empty($voiceNotes['emotional_core'])) {
                    $base .= "- Emotional core: " . $voiceNotes['emotional_core'] . "\n";
                }
                if (! empty($voiceNotes['tone'])) {
                    $base .= "- Tone & style: " . $voiceNotes['tone'] . "\n";
                }
                if (! empty($voiceNotes['pov'])) {
                    $base .= "- Point of view: " . $voiceNotes['pov'] . "\n";
                }
            }

            $base .= "\n\n**Your coaching constraint:** Every suggestion, question, or edit you offer must stay anchored to the story the author has described above. Do not introduce, suggest, or explore characters, events, or themes that are not already present in their draft or notes.\n";
        }

        if ($this->story?->content) {
            $label = $isAuthorVoice
                ? "\n\n---\n**The polished version of the author's story (do not rewrite this — help the author develop it further in their own voice):**\n\n"
                : "\n\n---\nHere is the story you have already written for this user. Use it as the foundation for the conversation and continue building on it:\n\n";
            $base .= $label . $this->story->content;
        }

        return $base;
    }

    public function messages(): iterable
    {
        if (! $this->story) {
            return [];
        }

        return StoryMessage::where('story_id', $this->story->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn ($m) => new Message($m->role, $m->content))
            ->all();
    }
}
