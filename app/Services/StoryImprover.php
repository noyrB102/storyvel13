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
You are a warm, honest story coach reviewing a personal memoir or short story. Read the story below and assess it across exactly these seven areas. For each area, respond with either "yes" (this change would improve the story) or "no" (the story is already good here), plus one short plain-English sentence (under 15 words) explaining why.

If you recommend the change, also write one short, specific follow-up question for the writer that is clearly about *this* story. Pull in a character, place, object, or moment from the story so the question feels personal and not generic. If you do not recommend the change, use an empty string for the question.

- voice: Does it sound like a real person talking, or is it flat/formal?
- detail: Would one more sensory detail make a moment feel more vivid and real?
- ending: Could the ending feel more personal, meaningful, or emotionally resonant?
- shorter: Is the story overlong or repetitive enough that it could be tightened?
- repetition: Are the same phrases, explanations, pronunciation/translation hints, or asides repeated after the first mention instead of dropped on later mentions?
- relevance: Are there sentences or details that do not meaningfully add value or move the story forward?
- grammar: Are there grammar or punctuation mistakes such as missing hyphens in compound adjectives (e.g., "7-year-old"), adjective/adverb errors (e.g., "real" vs "really"), or "everyone" vs "every one" confusions (e.g., "everyone of them" should be "every one of them")?

Respond ONLY with valid JSON in this exact format, nothing else:
{
  "voice": { "recommend": true/false, "reason": "one short sentence", "question": "specific question about this story or empty string" },
  "detail": { "recommend": true/false, "reason": "one short sentence", "question": "specific question about this story or empty string" },
  "ending": { "recommend": true/false, "reason": "one short sentence", "question": "specific question about this story or empty string" },
  "shorter": { "recommend": true/false, "reason": "one short sentence", "question": "specific question about this story or empty string" },
  "repetition": { "recommend": true/false, "reason": "one short sentence", "question": "specific question about this story or empty string" },
  "relevance": { "recommend": true/false, "reason": "one short sentence", "question": "specific question about this story or empty string" },
  "grammar": { "recommend": true/false, "reason": "one short sentence", "question": "specific question about this story or empty string" }
}

Story to review:

{$content}
PROMPT;

        try {
            $response = (new StoryEditAgent())->prompt($prompt, timeout: 15);
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

        if (! is_array($data) || ! isset($data['voice'], $data['detail'], $data['ending'], $data['shorter'], $data['repetition'], $data['relevance'], $data['grammar'])) {
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
        if (isset($recommendations['repetition'])) {
            $fixes[] = 'drop any repeated pronunciation or translation hints, explanations, or asides after the first mention; say them once and do not repeat them';
        }
        if (isset($recommendations['relevance'])) {
            $fixes[] = 'remove or tighten any sentences or details that do not meaningfully add value or move the story forward';
        }
        if (isset($recommendations['grammar'])) {
            $fixes[] = 'correct grammar and punctuation, including missing hyphens in compound adjectives (e.g., "7-year-old"), adjective/adverb errors (e.g., "real" vs "really"), and "everyone" vs "every one" usage';
        }

        $instruction = "Rewrite the story below while preserving all real facts, people, places, and events exactly as they happened. Avoid unnecessary repetition: say each detail once and do not restate the same phrase, explanation, or aside later in the story. Intentional repetition is fine when it adds something meaningful to the story, but phrase it differently each time.";

        if (! empty($fixes)) {
            $instruction .= "\n\nAlso address these goals:\n- " . implode("\n- ", $fixes);
        }

        if ($extraContext !== null && trim($extraContext) !== '') {
            $instruction .= "\n\nAlso use the following details, corrections, and requests from the writer. Apply any spelling or factual corrections exactly as stated, and weave only the concrete details into the story. Do not copy the questions or the writer's instructions into the final text:\n" . trim($extraContext);
        }

        $prompt = "Story to revise:\n\n" . $content . "\n\n" . $instruction;

        try {
            $response = (new StoryEditAgent())->prompt($prompt, timeout: 25);

            return trim($response->text) ?: $content;
        } catch (ProviderOverloadedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $content;
        }
    }

    public function polish(string $content): string
    {
        $prompt = "Proofread the story below. Correct grammar, punctuation, and spelling, including adding a comma after introductory words like 'So', 'Well', and 'Now'. Also remove unnecessary repetition or redundancy: say each detail once and do not restate the same phrase or explanation. Preserve intentional repetition if it adds meaning, but phrase it differently each time. Do not change facts, people, places, events, or the author's voice. Do not add new content. Return only the corrected story text.\n\nStory:\n\n{$content}";

        try {
            $response = (new StoryEditAgent())->prompt($prompt, timeout: 25);
            $text = trim($response->text);
            $text = preg_replace('/^```(?:\\w+)?\\s*/', '', $text);
            $text = preg_replace('/\\s*```$/', '', $text);

            return trim($text) ?: $content;
        } catch (ProviderOverloadedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $content;
        }
    }
}
