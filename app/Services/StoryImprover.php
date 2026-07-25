<?php

namespace App\Services;

use App\Ai\Agents\StoryEditAgent;
use App\Ai\Agents\StoryReviewAgent;
use Laravel\Ai\Exceptions\ProviderOverloadedException;

class StoryImprover
{
    public function review(string $content): ?array
    {
        $prompt = <<<PROMPT
You are a warm, honest story coach reviewing a personal memoir or short story. Read the story below and assess it across exactly these four areas. For each area, respond with either "yes" (this change would improve the story) or "no" (the story is already good here), plus one short plain-English sentence (under 15 words) explaining why.

If "recommend" is true for any area, also include a short, specific follow-up question under 25 words that names the story's topic, character, place, or moment so the writer knows exactly what "this" refers to. Use "tell me about" rather than "show" when phrasing the question. Do not refer to paragraph, line, or sentence numbers. Do not ask the user to combine, split, or rearrange paragraphs. Ask only for a specific detail or memory they can share. If "recommend" is false, leave "question" empty.

Respond ONLY with valid JSON in this exact format, nothing else:
{
  "voice": { "recommend": true/false, "reason": "one short sentence", "question": "specific question if recommend is true" },
  "detail": { "recommend": true/false, "reason": "one short sentence", "question": "specific question if recommend is true" },
  "ending": { "recommend": true/false, "reason": "one short sentence", "question": "specific question if recommend is true" },
  "shorter": { "recommend": true/false, "reason": "one short sentence", "question": "specific question if recommend is true" }
}

Story to review:

{$content}
PROMPT;

        try {
            $response = (new StoryReviewAgent())->prompt($prompt);
        } catch (ProviderOverloadedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return null;
        }

        $text = trim($response->text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);

        $data = json_decode($text, true);

        if (! is_array($data)) {
            if (preg_match('/(\{.*\})/s', $text, $matches)) {
                $data = json_decode($matches[1], true);
            }
        }

        if (! is_array($data) || ! isset($data['voice'], $data['detail'], $data['ending'], $data['shorter'])) {
            return null;
        }

        return $data;
    }

    public function improve(string $content, ?string $extraContext = null, ?array $review = null): string
    {
        if ($review === null) {
            $review = $this->review($content);
        }

        if ($review === null) {
            return $content;
        }

        $recommendations = array_filter($review, fn ($item) => ($item['recommend'] ?? false) === true);

        if (empty($recommendations) && empty($extraContext)) {
            return $content;
        }

        $fixes = [];
        if (isset($recommendations['voice'])) {
            $fixes[] = 'sound more like a real person talking — less polished and formal, more natural and conversational, like the author is telling it to a friend';
        }
        if (isset($recommendations['detail'])) {
            $fixes[] = 'add more vivid sensory detail — describe what was seen, heard, smelled, felt, or said';
        }
        if (isset($recommendations['ending'])) {
            $fixes[] = 'strengthen the ending so it feels more personal, meaningful, and emotionally resonant';
        }
        if (isset($recommendations['shorter'])) {
            $fixes[] = 'keep the story concise and within 300–750 words by trimming anything that is not essential';
        }

        $instruction = "Rewrite the story below to address these goals while preserving all real facts, people, places, and events exactly as they happened:\n- " . implode("\n- ", $fixes);

        if ($extraContext !== null && trim($extraContext) !== '') {
            $instruction .= "\n\nAlso use the following details, corrections, and requests from the writer. Apply any spelling or factual corrections exactly as stated, and weave only the concrete details into the story. Do not copy the questions or the writer's instructions into the final text:\n" . trim($extraContext);
        }

        $prompt = "Story to revise:\n\n" . $content . "\n\n" . $instruction;

        try {
            $response = (new StoryEditAgent())->prompt($prompt);

            return trim($response->text) ?: $content;
        } catch (ProviderOverloadedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $content;
        }
    }
}
