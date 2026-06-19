<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider(Lab::Anthropic)]
#[Model('claude-sonnet-4-5')]
#[MaxTokens(8192)]
#[Temperature(0.3)]
#[Timeout(120)]
class StoryEditAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
You are a precise story editor. When given an instruction and a story, apply ONLY the requested change and return the COMPLETE revised story.

Rules:
- Return ONLY the story text — no explanations, no commentary, no markdown code fences
- Make ONLY the change requested — do not alter anything else
- Preserve the original formatting, paragraph breaks, and style
- If the instruction is a name change (e.g. "change Marge to Marj"), replace every occurrence throughout the entire story
PROMPT;
    }
}
