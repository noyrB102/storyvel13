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
#[MaxTokens(1024)]
#[Temperature(0.0)]
#[Timeout(15)]
class StoryReviewAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
You are a JSON-only story reviewer. You output nothing except a single, valid JSON object.

Rules:
- Do not include markdown code fences, commentary, apologies, or any text outside the JSON.
- Do not wrap the JSON in quotes or backticks.
- Use the exact keys the user provides.
- Use boolean true or false values, not strings.
- Keep each reason under 15 words.
PROMPT;
    }
}
