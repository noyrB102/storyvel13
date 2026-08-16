<?php

use App\Jobs\GenerateCoverImage;
use App\Jobs\GenerateStoryContent;
use App\Models\Story;
use App\Models\StoryDraft;
use App\Models\StoryInput;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;
use App\Ai\Agents\StoryAgent;
use App\Ai\Agents\StoryContextAgent;
use App\Services\StoryImprover;
use Laravel\Ai\Exceptions\ProviderOverloadedException;

new class extends Component
{
    use WithFileUploads;

    // welcome | idea | ai_guided | ai_prompt | details | voice_draft | voice_characters | voice_emotion | voice_tone | voice_title | voice_review | generating | done
    public string $step = 'welcome';

    public string $prompt = '';
    public string $title = '';
    public string $genre = '';
    public string $format = 'explore';
    public bool $isPrivate = false;

    public $draftFile = null;
    public array $uploadedImages = [];
    public array $pendingImages = [];

    public ?int $storyId = null;

    // Author-voice guided fields
    public string $voiceDraft       = '';
    public string $voiceCharacters  = '';
    public string $voiceEmotionCore = '';
    public string $voiceTone        = '';
    public string $voicePov         = 'third';
    public string $endingStyle      = '';

    // UI toggle states
    public bool $showIdeaDetails = false;
    public bool $showFullIdea = false;

    // AI pre-generation review
    public string $aiReview = '';
    public bool $loadingReview = false;

    // AI follow-up wizard
    public string $clarifyQuestion = '';
    public string $clarifyAnswer = '';
    public string $clarifyContext = '';
    public int $clarifyRound = 0;
    public const MAX_CLARIFY_ROUNDS = 2;

    // Manual story paste + re-craft flow
    public string $manualStory = '';
    public string $manualTitle = '';
    public string $manualQuestion = '';
    public string $manualAnswer = '';
    public string $manualContext = '';
    public array $manualQuestions = [];
    public int $manualQuestionIndex = 0;
    public bool $manualLoading = false;
    public bool $guidedReviewStarted = false;
    public string $manualFocusText = '';
    public string $manualFocusHeading = '';
    public string $manualFocusSubtext = '';
    public array $manualCorrections = [];

    // AI Guided Writer (FEATURE_AI_WRITES) — 6 simple fields, with optional follow-up questions
    public string $guidedTopic     = '';
    public string $guidedCharacter = '';
    public string $guidedObstacle  = '';
    public string $guidedSetting   = '';
    public string $guidedChange    = '';
    public string $guidedDetail    = '';
    public string $guidedSummary   = '';
    public ?string $guidedDraftSavedAt = null;
    public string $guidedValidationMessage = '';

    // Kid wizard (under 6) inputs
    public string $kidStep = 'idea';
    public string $kidIdea = '';
    public string $kidWhat = '';
    public string $kidWho = '';
    public string $kidWhere = '';
    public string $kidEnding = '';
    public string $kidDetail = '';
    public string $kidValidationMessage = '';

    public ?int $similarStoryId = null;
    public string $similarStoryTitle = '';
    public bool $proceedWithDuplicate = false;

    public function isKidAuthor(): bool
    {
        return auth()->user()?->age !== null && auth()->user()->age < 6;
    }

    public function mount(): void
    {
        if (request('prompt')) {
            $this->prompt = request('prompt');
            $this->genre  = request('genre', '');
            $this->format = request('format', 'explore');

            // Pre-fill voiceDraft if prompt is substantial (user already wrote their story)
            $wordCount = str_word_count($this->prompt);
            if ($wordCount > 50) {
                $this->voiceDraft = $this->prompt;
            }

            // User arrived with an idea already — skip the inspiration wizard
            $this->step = 'idea';
        } elseif ($this->isKidAuthor()) {
            $this->startKidWizard();
        }
    }

    public function startWriting(): void
    {
        $this->step = 'idea';
    }

    public function useStarter(string $stem): void
    {
        $this->prompt = $stem;
        $this->step   = 'idea';
    }

    protected function rules(): array
    {
        return [
            'prompt'        => 'required|min:10',
            'title'         => 'nullable|string|max:255',
            'genre'         => 'nullable|string|max:100',
            'format'        => 'required|in:explore,memoir,short_story,chapter,outline,author_voice',
            'voiceDraft'    => 'nullable|string',
            'draftFile'     => 'nullable|file|mimes:pdf,txt,md,docx|max:20480',
            'isPrivate'     => 'nullable|boolean',
            'pendingImages' => 'nullable|array',
            'pendingImages.*' => 'nullable|image|max:10240',
        ];
    }

    public function nextStep(): void
    {
        $this->validate(['prompt' => 'required|min:10']);
        $this->step = 'details';
    }

    public function hasSubstantialDraft(): bool
    {
        $text = trim($this->voiceDraft) !== '' ? $this->voiceDraft : $this->prompt;
        return str_word_count($text) > 50;
    }

    public function toVoiceDraft(): void
    {
        $this->validate(['prompt' => 'required|min:10']);
        $this->format = 'author_voice';

        // Always carry their idea forward so they never face an empty box
        if (empty(trim($this->voiceDraft))) {
            $this->voiceDraft = $this->prompt;
        }

        // If user already has a substantial draft, skip ahead
        if ($this->hasSubstantialDraft()) {
            // Also skip title step if title is already set
            if (!empty(trim($this->title))) {
                $this->toVoiceReview();
            } else {
                $this->step = 'voice_title';
            }
        } else {
            $this->step = 'voice_draft';
        }
    }

    public function addDetail(string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        $this->voiceDraft = trim($this->voiceDraft) === ''
            ? $text
            : rtrim($this->voiceDraft) . ' ' . $text;
    }

    public function toAiPrompt(): void
    {
        $this->format = 'short_story';
        $this->step   = 'ai_prompt';
    }

    public function startKidWizard(): void
    {
        $this->kidStep = 'idea';
        $this->kidIdea = '';
        $this->kidWhat = '';
        $this->kidWho = '';
        $this->kidWhere = '';
        $this->kidEnding = '';
        $this->kidDetail = '';
        $this->kidValidationMessage = '';
        $this->format = 'memoir';
        $this->step = 'kid_wizard';
    }

    public function startAiGuided(): void
    {
        if ($this->isKidAuthor()) {
            $this->startKidWizard();
            return;
        }

        $this->guidedSummary   = '';
        $this->guidedTopic     = '';
        $this->guidedCharacter = '';
        $this->guidedObstacle  = '';
        $this->guidedSetting   = '';
        $this->guidedChange    = '';
        $this->guidedDetail    = '';
        $this->guidedDraftSavedAt = null;
        $this->format          = 'memoir';
        $this->step            = 'ai_guided';

        $this->restoreGuidedDraft();
    }

    public function buildGuidedPrompt(): string
    {
        $parts = [];
        if (trim($this->guidedSummary))   $parts[] = 'One-sentence summary: ' . trim($this->guidedSummary);
        if (trim($this->guidedTopic))     $parts[] = 'What this story is about: ' . trim($this->guidedTopic);
        if (trim($this->guidedCharacter)) $parts[] = 'Who is in the story and what they want: ' . trim($this->guidedCharacter);
        if (trim($this->guidedObstacle))  $parts[] = 'What got in the way: ' . trim($this->guidedObstacle);
        if (trim($this->guidedSetting))   $parts[] = 'Place & moment / how this memory came up: ' . trim($this->guidedSetting);
        if (trim($this->guidedChange))    $parts[] = 'How it turned out or what changed: ' . trim($this->guidedChange);
        if (trim($this->guidedDetail))    $parts[] = 'A vivid detail: ' . trim($this->guidedDetail);

        $prompt = implode("\n", $parts);

        if (trim($this->clarifyContext) !== '') {
            $prompt .= "\n\nThe writer also shared these extra details in response to follow-up questions:\n" . trim($this->clarifyContext);
        }

        $prompt .= "\n\nPlease write this story as a warm, first-draft memoir of approximately 450–500 words. Build it around the five essential story elements the writer has shared: a character with a want, something in the way, a clear place and moment, a change by the end, and one vivid detail. If any element is missing, gracefully keep the story loose and focus on what is known. It should feel complete and satisfying, but concise.";

        return $prompt;
    }

    public function aiGuidedGenerate(): void
    {
        if (! $this->hasGuidedInput) {
            return;
        }

        if (! $this->hasEnoughGuidedInput) {
            $this->guidedValidationMessage = 'This sounds like a great memory! To write your true story accurately, could you share a little more? Either fill in a second box, or add more detail to one box — about 40 words is plenty.';
            return;
        }

        $this->guidedValidationMessage = '';
        $this->clearGuidedDraft();

        $this->prompt = $this->buildGuidedPrompt();
        $this->format = 'memoir';

        if (! $this->proceedWithDuplicate) {
            $similar = $this->findSimilarStory();
            if ($similar) {
                $this->similarStoryId = $similar->id;
                $this->similarStoryTitle = $similar->title ?? 'Untitled Story';
                $this->step = 'duplicate';
                return;
            }
        }

        if ($this->clarifyRound < self::MAX_CLARIFY_ROUNDS) {
            try {
                $contextPrompt = "The writer has shared these real memory details:\n\n" . $this->prompt . "\n\nDo you have enough detail to write a warm, complete first draft, or do you need to ask one gentle follow-up question?";
                $response = (new StoryContextAgent())->prompt($contextPrompt);
                $data = json_decode(trim($response->text), true);

                if (is_array($data) && ($data['ready'] ?? true) === false && ! empty($data['question'])) {
                    $this->clarifyQuestion = trim($data['question']);
                    $this->clarifyAnswer = '';
                    $this->clarifyRound++;
                    $this->step = 'clarify';
                    return;
                }
            } catch (\Throwable $e) {
                // If the context check fails, continue to generate rather than blocking the user.
            }
        }

        $story = Story::create([
            'user_id'     => auth()->id(),
            'title'       => $this->title ?: null,
            'author_name' => auth()->user()->name,
            'prompt'      => $this->prompt,
            'genre'       => $this->genre ?: null,
            'format'      => $this->format,
            'is_private'  => $this->isPrivate,
            'status'      => 'pending',
        ]);

        $this->storyId = $story->id;

        StoryInput::create([
            'story_id'      => $story->id,
            'user_id'       => auth()->id(),
            'summary'       => $this->guidedSummary,
            'topic'         => $this->guidedTopic,
            'characters'    => $this->guidedCharacter,
            'obstacle'      => $this->guidedObstacle,
            'setting'       => $this->guidedSetting,
            'outcome'       => $this->guidedChange,
            'detail'        => $this->guidedDetail,
            'extra_context' => $this->clarifyContext,
        ]);

        GenerateStoryContent::dispatch($story, true);
        $this->step = 'generating';
    }

    public function kidNextStep(): void
    {
        $this->kidValidationMessage = '';

        match ($this->kidStep) {
            'idea'    => empty($this->kidIdea)
                ? $this->kidValidationMessage = 'Pick a fun idea first!'
                : $this->kidStep = 'who',
            'who'     => empty($this->kidWho)
                ? $this->kidValidationMessage = 'Pick who was there.'
                : (empty($this->kidWhere)
                    ? $this->kidValidationMessage = 'Pick where you were.'
                    : $this->kidStep = 'ending'),
            'ending'  => empty($this->kidEnding)
                ? $this->kidValidationMessage = 'Pick how it ended.'
                : $this->kidGenerate(),
            default   => $this->kidStep = 'idea',
        };
    }

    public function kidBack(): void
    {
        $this->kidValidationMessage = '';
        match ($this->kidStep) {
            'who'     => $this->kidStep = 'idea',
            'ending'  => $this->kidStep = 'who',
            default   => $this->kidStep = 'idea',
        };
    }

    public function kidGenerate(): void
    {
        $this->guidedSummary = $this->kidWhat;
        $this->guidedTopic   = $this->kidIdea ?: $this->kidWhat;
        $this->guidedCharacter = $this->kidWho;
        $this->guidedSetting   = $this->kidWhere;
        $this->guidedObstacle  = '';
        $this->guidedChange    = $this->kidEnding;
        $this->guidedDetail    = $this->kidDetail;

        $this->prompt = $this->buildGuidedPrompt();
        $this->title  = $this->kidIdea ?: $this->kidWhat;
        $this->format = 'kids';

        $story = Story::create([
            'user_id'     => auth()->id(),
            'title'       => $this->title ?: null,
            'author_name' => auth()->user()->name,
            'prompt'      => $this->prompt,
            'genre'       => $this->genre ?: null,
            'format'      => $this->format,
            'is_private'  => $this->isPrivate,
            'status'      => 'pending',
        ]);

        $this->storyId = $story->id;

        StoryInput::create([
            'story_id'      => $story->id,
            'user_id'       => auth()->id(),
            'summary'       => $this->guidedSummary,
            'topic'         => $this->guidedTopic,
            'characters'    => $this->guidedCharacter,
            'obstacle'      => $this->guidedObstacle,
            'setting'       => $this->guidedSetting,
            'outcome'       => $this->guidedChange,
            'detail'        => $this->guidedDetail,
            'extra_context' => '',
        ]);

        GenerateStoryContent::dispatch($story);
        $this->step = 'generating';
    }

    #[Computed]
    public function hasGuidedInput(): bool
    {
        return trim($this->guidedSummary . $this->guidedTopic . $this->guidedCharacter . $this->guidedObstacle . $this->guidedSetting . $this->guidedChange . $this->guidedDetail) !== '';
    }

    #[Computed]
    public function hasEnoughGuidedInput(): bool
    {
        $fields = [
            $this->guidedSummary,
            $this->guidedTopic,
            $this->guidedCharacter,
            $this->guidedObstacle,
            $this->guidedSetting,
            $this->guidedChange,
            $this->guidedDetail,
        ];

        $nonEmpty = collect($fields)->filter(fn ($value) => trim($value) !== '')->count();

        // Enough if at least two fields have something, or if any one field has substantial detail.
        if ($nonEmpty >= 2) {
            return true;
        }

        $maxWords = collect($fields)->map(fn ($value) => str_word_count(trim($value)))->max();

        return $maxWords >= 40;
    }

    private function guidedWordCount(): int
    {
        return str_word_count(trim($this->guidedSummary))
            + str_word_count(trim($this->guidedTopic))
            + str_word_count(trim($this->guidedCharacter))
            + str_word_count(trim($this->guidedObstacle))
            + str_word_count(trim($this->guidedSetting))
            + str_word_count(trim($this->guidedChange))
            + str_word_count(trim($this->guidedDetail));
    }

    public function saveGuidedDraft(): void
    {
        if (! auth()->check()) {
            return;
        }

        // Don't save a draft if the user hasn't written anything meaningful yet.
        // This prevents one-word entries like "Hey" from reappearing later.
        if ($this->guidedWordCount() < 15) {
            StoryDraft::where('user_id', auth()->id())
                ->where('step', 'ai_guided')
                ->delete();

            $this->guidedDraftSavedAt = null;

            return;
        }

        StoryDraft::updateOrCreate(
            ['user_id' => auth()->id(), 'step' => 'ai_guided'],
            ['data' => [
                'summary'   => $this->guidedSummary,
                'topic'     => $this->guidedTopic,
                'character' => $this->guidedCharacter,
                'obstacle'  => $this->guidedObstacle,
                'setting'   => $this->guidedSetting,
                'change'    => $this->guidedChange,
                'detail'    => $this->guidedDetail,
            ]]
        );

        $this->guidedDraftSavedAt = now()->format('g:i A');
    }

    public function restoreGuidedDraft(): void
    {
        if (! auth()->check()) {
            return;
        }

        $draft = StoryDraft::where('user_id', auth()->id())
            ->where('step', 'ai_guided')
            ->first();

        if (! $draft) {
            return;
        }

        $data = $draft->data ?? [];
        $this->guidedSummary   = $data['summary']   ?? '';
        $this->guidedTopic     = $data['topic']     ?? '';
        $this->guidedCharacter = $data['character'] ?? '';
        $this->guidedObstacle  = $data['obstacle']  ?? '';
        $this->guidedSetting   = $data['setting']   ?? '';
        $this->guidedChange    = $data['change']    ?? '';
        $this->guidedDetail    = $data['detail']    ?? '';

        // If the saved draft is too minimal, clear it and let the user start fresh.
        if ($this->guidedWordCount() < 15) {
            $this->clearGuidedDraft();
        }
    }

    public function clearGuidedDraft(): void
    {
        if (! auth()->check()) {
            return;
        }

        StoryDraft::where('user_id', auth()->id())
            ->where('step', 'ai_guided')
            ->delete();

        $this->guidedDraftSavedAt = null;
    }

    public function submitClarify(): void
    {
        $answer = trim($this->clarifyAnswer);
        if ($answer !== '' && trim($this->clarifyQuestion) !== '') {
            $this->clarifyContext .= "\n\nFollow-up question: " . $this->clarifyQuestion . "\nAnswer: " . $answer;
        }
        $this->clarifyAnswer = '';
        $this->aiGuidedGenerate();
    }

    public function skipClarify(): void
    {
        $this->clarifyRound = self::MAX_CLARIFY_ROUNDS;
        $this->clarifyAnswer = '';
        $this->aiGuidedGenerate();
    }

    public function startOver(): void
    {
        $this->guidedSummary   = '';
        $this->guidedTopic     = '';
        $this->guidedCharacter = '';
        $this->guidedObstacle  = '';
        $this->guidedSetting   = '';
        $this->guidedChange    = '';
        $this->guidedDetail    = '';
        $this->guidedValidationMessage = '';
        $this->clarifyQuestion = '';
        $this->clarifyAnswer   = '';
        $this->clarifyContext  = '';
        $this->clarifyRound    = 0;
        $this->proceedWithDuplicate = false;
        $this->similarStoryId = null;
        $this->similarStoryTitle = '';
        $this->clearGuidedDraft();
    }

    public function findSimilarStory(): ?Story
    {
        $newTopic = trim($this->guidedTopic);
        if ($newTopic === '') {
            return null;
        }

        $newPrompt = $this->buildGuidedPrompt();
        $newTopicLower = strtolower($newTopic);
        $newPromptLower = strtolower($newPrompt);

        $bestMatch = null;
        $bestScore = 0;

        foreach (Story::where('user_id', auth()->id())->select('id', 'title', 'prompt')->with('guidedInput')->get() as $story) {
            $title = strtolower($story->title ?? '');
            $prompt = strtolower($story->prompt ?? '');

            similar_text($newTopicLower, $title, $titlePercent);
            similar_text($newPromptLower, $prompt, $promptPercent);

            $topicInput = strtolower($story->guidedInput?->topic ?? '');
            similar_text($newTopicLower, $topicInput, $inputPercent);

            $score = max($titlePercent, $promptPercent, $inputPercent);

            if ($score > 70 && $score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $story;
            }
        }

        return $bestMatch;
    }

    public function continueWithDuplicate(): void
    {
        $this->proceedWithDuplicate = true;
        $this->aiGuidedGenerate();
    }

    public function trySomethingNew(): void
    {
        $this->proceedWithDuplicate = false;
        $this->similarStoryId = null;
        $this->similarStoryTitle = '';
        $this->startOver();
        $this->step = 'welcome';
    }

    public function updatedGuidedSummary(): void   { $this->guidedValidationMessage = ''; $this->saveGuidedDraft(); }
    public function updatedGuidedTopic(): void     { $this->guidedValidationMessage = ''; $this->saveGuidedDraft(); }
    public function updatedGuidedCharacter(): void { $this->guidedValidationMessage = ''; $this->saveGuidedDraft(); }
    public function updatedGuidedObstacle(): void  { $this->guidedValidationMessage = ''; $this->saveGuidedDraft(); }
    public function updatedGuidedSetting(): void    { $this->guidedValidationMessage = ''; $this->saveGuidedDraft(); }
    public function updatedGuidedChange(): void     { $this->guidedValidationMessage = ''; $this->saveGuidedDraft(); }
    public function updatedGuidedDetail(): void     { $this->guidedValidationMessage = ''; $this->saveGuidedDraft(); }

    public function generateFromPrompt(): void
    {
        $this->validate(['prompt' => 'required|min:10']);

        $story = Story::create([
            'user_id'     => auth()->id(),
            'title'       => $this->title ?: null,
            'author_name' => auth()->user()->name,
            'prompt'      => $this->prompt,
            'genre'       => $this->genre ?: null,
            'format'      => $this->format,
            'is_private'  => $this->isPrivate,
            'status'      => 'pending',
        ]);

        $this->storyId = $story->id;
        GenerateStoryContent::dispatch($story);
        $this->step = 'generating';
    }

    public function toVoiceCharacters(): void
    {
        $this->validate(['voiceDraft' => 'required|min:30']);
        // Always skip characters/emotion/tone — go straight to review
        $this->toVoiceReview();
    }

    public function toVoiceEmotion(): void
    {
        $this->step = 'voice_emotion';
    }

    public function toVoiceTone(): void
    {
        $this->step = 'voice_tone';
    }

    public function toVoiceTitle(): void
    {
        $this->step = 'voice_title';
    }

    public function toVoiceReview(): void
    {
        $this->loadingReview = true;
        $this->step = 'voice_review';
        $this->aiReview = '';

        // Build a concise summary prompt for the AI
        $draft      = $this->voiceDraft;
        $title      = $this->title ?: '(no title given yet)';
        $characters = $this->voiceCharacters ?: '(not specified)';
        $emotion    = $this->voiceEmotionCore ?: '(not specified)';
        $tone       = $this->voiceTone ?: '(not specified)';

        $wordCount = str_word_count($draft);

        // Guard: not enough content — encourage more writing
        if ($wordCount < 50) {
            $encouragement = $wordCount < 20
                ? "That's a great start! But {$wordCount} words is a little short for a full story."
                : "You've written {$wordCount} words — you're off to a good start!";
            $this->aiReview = "📝 {$encouragement} " .
                "The more you share, the better I can help tell YOUR story in YOUR voice. " .
                "Try to write at least 50 words — even a rough, rambling description is perfect. " .
                "Go back and add a few more sentences: What happened? Who was there? How did it feel? " .
                "Don't worry about making it perfect — just keep talking!";
            $this->loadingReview = false;
            return;
        }

        try {
            $reviewPrompt =
                "You are a warm, encouraging writing coach for senior writers. " .
                "Your job is to read the story draft below and do TWO things:\n\n" .
                "1. CHECK ALIGNMENT: Does the story actually tell the story the title and subject promise? " .
                "For example, if the title is 'Marge' and the story is about a friendship with Marge, does the draft actually show that friendship? " .
                "If YES — open with a warm affirmation like '✓ Your story is exactly what it should be!' and briefly say what makes it work (1–2 sentences). " .
                "If NO or PARTIALLY — gently explain what's missing in one simple sentence, then give ONE concrete suggestion, like: " .
                "'Your story mentions Marge but doesn\\'t quite show how the friendship formed — could you add a sentence about the moment you became friends?'\n\n" .
                "2. CONFIRM READY: If the story is on track, end with: 'If this sounds right, tap Finish My Story below!' " .
                "If something needs fixing, end with: 'Would you like to go back and add a little more, or continue as-is?'\n\n" .
                "Rules: Keep the whole response under 100 words. Use plain, warm, simple language — no jargon. " .
                "Never be negative. Always be encouraging. This is for a senior writer sharing a real memory.\n\n" .
                "Title: {$title}\n" .
                "Story draft: {$draft}\n" .
                ($characters !== '(not specified)' ? "Characters: {$characters}\n" : '') .
                ($emotion !== '(not specified)' ? "Emotional core: {$emotion}\n" : '') .
                ($tone !== '(not specified)' ? "Tone: {$tone}\n" : '');

            $response = (new StoryAgent())->prompt($reviewPrompt);
            $this->aiReview = $response->text;
        } catch (\Throwable $e) {
            $this->aiReview = "✓ Your story is ready! Here's what I heard: \"" .
                \Illuminate\Support\Str::limit($draft, 120) . "...\"\n\nIf that sounds right, tap Finish My Story below!";
        }

        $this->loadingReview = false;
    }

    public function togglePrivate(): void
    {
        $this->isPrivate = !$this->isPrivate;
    }

    public function cancelStory(): void
    {
        $this->prompt          = '';
        $this->title           = '';
        $this->voiceDraft      = '';
        $this->voiceCharacters = '';
        $this->voiceEmotionCore = '';
        $this->voiceTone       = '';
        $this->endingStyle     = '';
        $this->aiReview         = '';
        $this->loadingReview    = false;
        $this->showIdeaDetails  = false;
        $this->showFullIdea     = false;
        $this->storyId          = null;
        $this->guidedTopic      = '';
        $this->guidedCharacter  = '';
        $this->guidedObstacle   = '';
        $this->guidedSetting    = '';
        $this->guidedChange     = '';
        $this->guidedDetail     = '';
        $this->clarifyQuestion  = '';
        $this->clarifyAnswer    = '';
        $this->clarifyContext   = '';
        $this->clarifyRound     = 0;
        $this->manualStory      = '';
        $this->manualTitle      = '';
        $this->manualQuestion   = '';
        $this->manualAnswer     = '';
        $this->manualContext    = '';
        $this->manualQuestions  = [];
        $this->manualQuestionIndex = 0;
        $this->manualLoading    = false;
        $this->manualFocusHeading = '';
        $this->manualFocusSubtext = '';
        $this->manualFocusText = '';
        $this->manualCorrections = [];
        $this->guidedReviewStarted = false;
        $this->step             = 'welcome';
    }

    public function startManualEntry(): void
    {
        $this->manualStory         = '';
        $this->manualTitle         = '';
        $this->manualQuestion      = '';
        $this->manualAnswer        = '';
        $this->manualContext       = '';
        $this->manualQuestions     = [];
        $this->manualQuestionIndex = 0;
        $this->manualLoading       = false;
        $this->manualFocusHeading  = '';
        $this->manualFocusSubtext  = '';
        $this->format              = 'memoir';
        $this->step                = 'manual_entry';
    }

    public function startManualReview(): void
    {
        if (trim($this->manualTitle) === '') {
            $this->addError('manualTitle', 'Please add a title for your story.');
            return;
        }

        $text = trim($this->manualStory);
        if (str_word_count($text) < 30) {
            $this->addError('manualStory', 'Please add at least 30 words so the AI has something to review.');
            return;
        }

        $this->manualLoading = true;
        $this->manualQuestion = '';
        $this->manualAnswer = '';
        $this->manualContext = '';
        $this->manualQuestions = [];
        $this->manualQuestionIndex = 0;
        $this->manualFocusText = '';
        $this->manualCorrections = [];

        try {
            $review = (new StoryImprover())->review($text);

            if ($review === null) {
                $this->manualContext = '';
                $this->manualFocusHeading = 'Before we review your story, are there additional specific details you want the AI to focus on?';
                $this->manualFocusSubtext = 'We could not run the full review, but you can still tell us what to focus on below.';
                $this->step = 'manual_no_changes';
                return;
            }

            $questions = [];
            if (($review['voice']['recommend'] ?? false) === true) {
                $questions[] = ['key' => 'voice', 'text' => $this->manualQuestionText($review['voice']['question'] ?? '', 'How would you tell this story out loud to a friend?')];
            }
            if (($review['detail']['recommend'] ?? false) === true) {
                $questions[] = ['key' => 'detail', 'text' => $this->manualQuestionText($review['detail']['question'] ?? '', 'What is one sight, sound, smell, or feeling you remember from this moment?')];
            }
            if (($review['ending']['recommend'] ?? false) === true) {
                $questions[] = ['key' => 'ending', 'text' => $this->manualQuestionText($review['ending']['question'] ?? '', 'How did this moment leave you, or what did you learn from it?')];
            }
            if (($review['shorter']['recommend'] ?? false) === true) {
                $questions[] = ['key' => 'shorter', 'text' => $this->manualQuestionText($review['shorter']['question'] ?? '', 'Is there a part you would be okay leaving out or shortening?')];
            }

            $this->manualQuestions = array_slice($questions, 0, 2);

            if (empty($this->manualQuestions)) {
                $this->manualContext = '';
                $this->manualFocusHeading = 'Before we review your story, are there additional specific details you want the AI to focus on?';
                $this->manualFocusSubtext = '';
                $this->step = 'manual_no_changes';
                return;
            }

            $this->manualQuestionIndex = 0;
            $this->manualQuestion = $this->manualQuestions[0]['text'];
            $this->manualAnswer = '';
            $this->step = 'manual_clarify';
        } catch (ProviderOverloadedException $e) {
            $this->addError('manualStory', 'The writing helper is busy right now. Please try again in a minute.');
        } catch (\Throwable $e) {
            $this->addError('manualStory', 'Something went wrong — please try again.');
        } finally {
            $this->manualLoading = false;
        }
    }

    public function submitManualAnswer(): void
    {
        $answer = trim($this->manualAnswer);
        if ($answer !== '') {
            $this->applySpellingCorrections($answer);

            if (trim($this->manualQuestion) !== '') {
                $this->manualContext .= ($this->manualContext ? "\n\n" : '') . "Follow-up question: " . $this->manualQuestion . "\nAnswer: " . $answer;
            }
        }
        $this->manualAnswer = '';
        $this->manualQuestionIndex++;

        if ($this->manualQuestionIndex < count($this->manualQuestions)) {
            $this->manualQuestion = $this->manualQuestions[$this->manualQuestionIndex]['text'];
        } else {
            $this->manualQuestion = '';
            $this->manualAnswer = '';
            $this->manualFocusHeading = 'Before we review your story, are there additional specific details you want the AI to focus on?';
            $this->manualFocusSubtext = '';
            $this->step = 'manual_no_changes';
        }
    }

    public function skipManualAnswer(): void
    {
        $this->manualAnswer = '';
        $this->manualQuestion = '';
        $this->manualQuestions = [];
        $this->manualContext = '';
        $this->manualFocusHeading = 'Before we review your story, are there additional specific details you want the AI to focus on?';
        $this->manualFocusSubtext = '';
        $this->step = 'manual_no_changes';
    }

    public function completeManualStory(string $content): void
    {
        $this->manualLoading = true;
        try {
            $improved = (new StoryImprover())->improve($content, $this->manualContext);

            foreach ($this->manualCorrections as $old => $new) {
                $improved = str_ireplace($old, $new, $improved);
            }

            $story = Story::find($this->storyId);

            if (! $story) {
                $story = Story::create([
                    'user_id'     => auth()->id(),
                    'title'       => $this->manualTitle ?: null,
                    'author_name' => auth()->user()->name,
                    'prompt'      => $content,
                    'content'     => $improved,
                    'genre'       => $this->genre ?: null,
                    'format'      => $this->format,
                    'is_private'  => $this->isPrivate,
                    'status'      => 'completed',
                ]);
            } else {
                $story->update([
                    'title'   => $this->manualTitle ?: null,
                    'content' => $improved,
                    'status'  => 'completed',
                ]);
            }

            $this->storyId = $story->id;
            $this->step = 'done';

            try {
                GenerateCoverImage::dispatch($story);
            } catch (\Throwable $e) {
                report($e);
            }
        } catch (ProviderOverloadedException $e) {
            $this->addError('manualStory', 'The writing helper is busy right now. Please try again in a minute.');
        } catch (\Throwable $e) {
            $this->addError('manualStory', 'Something went wrong — please try again.');
        } finally {
            $this->manualLoading = false;
        }
    }

    public function saveManualAsIs(): void
    {
        $this->completeManualStory($this->manualStory);
    }

    public function applyManualImprovement(string $focus): void
    {
        $instructions = [
            'ending'   => 'Please give this story a stronger, more emotionally resonant ending.',
            'personal' => 'Make this story feel more personal and emotionally connected.',
            'voice'    => 'Rewrite this so it sounds more like the author speaking naturally, less polished and formal.',
            'raw'      => 'This story sounds too polished. Make it sound more like a real person telling it to a friend.',
        ];

        $focusText = $instructions[$focus] ?? 'Please improve this story while keeping all facts the same.';
        $this->manualContext .= ($this->manualContext ? "\n\n" : '') . "Focus area: " . $focusText;
        $this->completeManualStory($this->manualStory);
    }

    public function applyManualFocusText(): void
    {
        $focus = trim($this->manualFocusText);
        if ($focus !== '') {
            $this->applySpellingCorrections($focus);
            $this->manualContext .= ($this->manualContext ? "\n\n" : '') . "Additional focus: " . $focus;
        }
        $this->completeManualStory($this->manualStory);
    }

    private function manualQuestionText(string $question, string $fallback): string
    {
        $text = trim($question);
        $text = preg_replace('/\bshow me\b/i', 'tell me about', $text);
        $text = preg_replace('/\bshow\b/i', 'tell me about', $text);
        return $text ?: $fallback;
    }

    private function applySpellingCorrections(string $text): void
    {
        $patterns = [
            '/\bchange the spelling of (\S+?) to (\S+?)\b/i',
            '/\b(\S+?) should be spelled (\S+?)\b/i',
            '/\bspell (\S+?) as (\S+?)\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $old = $matches[1];
                $new = $matches[2];
                $this->manualStory = str_ireplace($old, $new, $this->manualStory);
                $this->manualCorrections[$old] = $new;
            }
        }
    }

    public function fixDraft(string $instruction): void
    {
        if (empty(trim($instruction)) || empty($this->voiceDraft)) {
            return;
        }
        try {
            $fixPrompt =
                "You are a helpful writing assistant. The user has written a story draft and wants to make a specific change. " .
                "Apply ONLY the requested change. Keep the author's voice, style, and all other content exactly as-is. " .
                "Return ONLY the updated story text — no commentary, no explanation.\n\n" .
                "Story draft:\n{$this->voiceDraft}\n\n" .
                "Requested change: {$instruction}";
            $response = (new StoryAgent())->prompt($fixPrompt);
            $this->voiceDraft = trim($response->text);
        } catch (\Throwable $e) {
            // silently fail — leave draft unchanged
        }
    }

    public function generate(): void
    {
        // Guard against submitting with too little story content
        if (str_word_count($this->voiceDraft) < 50) {
            $this->addError('voiceDraft', 'Your story needs at least 50 words. Please go back and add a bit more — even a rough draft is perfect!');
            $this->step = 'voice_draft';
            return;
        }

        $this->validate();

        $attachments = [];

        if ($this->draftFile) {
            $path = $this->draftFile->store('story-uploads', 'local');
            $attachments[] = [
                'path' => $path,
                'mime' => $this->draftFile->getMimeType(),
                'name' => $this->draftFile->getClientOriginalName(),
            ];
        }

        foreach ($this->pendingImages as $image) {
            $path = $image->store('story-uploads', 'local');
            $attachments[] = [
                'path' => $path,
                'mime' => $image->getMimeType(),
                'name' => $image->getClientOriginalName(),
            ];
        }

        $voiceNotes = null;
        if ($this->format === 'author_voice') {
            $voiceNotes = array_filter([
                'characters'    => $this->voiceCharacters,
                'emotional_core' => $this->voiceEmotionCore,
                'tone'          => $this->voiceTone,
                'pov'           => $this->voicePov,
                'ending'        => $this->endingStyle,
            ]);
        }

        // For author_voice: the prompt sent to AI is the author's own raw draft
        $finalPrompt = ($this->format === 'author_voice' && $this->voiceDraft)
            ? $this->voiceDraft
            : $this->prompt;

        $story = Story::create([
            'user_id'     => auth()->id(),
            'title'       => $this->title ?: null,
            'author_name' => auth()->user()->name,
            'prompt'      => $finalPrompt,
            'genre'       => $this->genre ?: null,
            'format'      => $this->format,
            'is_private'  => $this->isPrivate,
            'status'      => 'pending',
            'attachments' => $attachments ?: null,
            'voice_notes' => $voiceNotes ?: null,
        ]);

        $this->storyId = $story->id;

        GenerateStoryContent::dispatch($story);

        $this->step = 'generating';
    }

    public function checkStatus(): void
    {
        if (! $this->storyId) {
            return;
        }

        $story = Story::find($this->storyId);

        if ($story && $story->status === 'needs_review' && ! $this->guidedReviewStarted) {
            $this->guidedReviewStarted = true;
            $this->manualStory = $story->content ?? '';
            $this->manualTitle = $story->title ?? '';
            $this->manualContext = '';
            $this->startManualReview();
            return;
        }

        if ($story && $story->isCompleted()) {
            $this->step = 'done';
        } elseif ($story && $story->status === 'failed') {
            $this->step = 'idea';
            $this->addError('prompt', 'Story generation failed. Please try again.');
        }
    }

    public function manualFocusPlaceholder(): string
    {
        $text = trim($this->manualStory);
        $subject = null;

        if ($text !== '') {
            if (preg_match_all('/[A-Z][a-z]{2,}/', $text, $matches)) {
                $skip = ['The','And','But','For','With','From','That','This','His','Her','She','Him','They','Them','Their','Was','Were','Had','Have','Has','Not','You','Are','But','Into','Just','Only','Also','Then','Than','When','Where','What','Would','Could','Should','Will','Said','One','Two','First','Last','About','Over','Before','After','Back','Still','Well','Very','Even','Much','Many','Some','Like','Can','May','Been','Being','Too','Now','New','Old','Long','Little','Big','Own','Other','Right','Left','Here','There','Out','Up','Down','Off','All','Each','Every','Most','More','Made','Make','How','Why','Who','Which','Whose','Whom'];
                $titleWords = array_filter(array_map('strtolower', preg_split('/[^a-zA-Z0-9]+/', $this->manualTitle ?? '', -1, PREG_SPLIT_NO_EMPTY)));
                foreach ($matches[0] as $word) {
                    if (in_array($word, $skip, true) || in_array(strtolower($word), $titleWords, true)) {
                        continue;
                    }
                    $subject = $word;
                    break;
                }
            }
        }

        if (empty($subject)) {
            $subject = 'the main character';
        }

        return "For example: make the ending about {$subject}, keep it under 300 words, mention a small detail that matters...";
    }

};
?>

<div class="w-full max-w-2xl mx-auto">

    @if ($step === 'welcome')
        {{-- Inspiration wizard — helps seniors get started --}}
        <div class="mb-5 text-center px-4">
            <h1 class="mb-2 text-2xl font-bold tracking-tight text-gray-900 dark:text-white">
                Let's Write Your Story
            </h1>
            <p class="text-lg text-gray-600 dark:text-gray-300">
                The hardest part is starting — so let's make it easy. 💛
            </p>
        </div>

        {{-- AI Guided Writer — feature-flagged, shown at top --}}
        @if(config('features.ai_writes'))
        <div class="mb-6 rounded-2xl border-2 border-blue-300 bg-blue-50 dark:border-blue-700 dark:bg-blue-900/20 p-6 shadow-md">
            <div class="flex items-center gap-3 mb-3">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-blue-600">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-5 text-white" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" />
                    </svg>
                </div>
                <div>
                    <p class="text-base font-bold text-blue-900 dark:text-blue-200">Let AI Help You Write Your True Story</p>
                    <p class="text-sm text-blue-700 dark:text-blue-400">Share real details — the AI will write it as it really happened!</p>
                </div>
            </div>
            <button
                wire:click="startAiGuided"
                class="flex w-full items-center justify-center gap-2 rounded-xl bg-blue-600 px-6 py-5 text-xl font-bold text-white shadow-md transition-colors hover:bg-blue-700 active:bg-blue-800"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                </svg>
                Start — AI Will Guide Me
            </button>
        </div>
        <div class="my-6 border-t border-gray-200 dark:border-zinc-700"></div>
        @endif

        {{-- Spark cards: tap one to begin with a gentle sentence starter --}}
        <div class="hidden mb-5">
            <p class="mb-3 px-1 text-base font-semibold text-gray-700 dark:text-gray-300">
                Pick something to write about:
            </p>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                @foreach([
                    ['emoji' => '💝', 'label' => 'Someone special',     'sub' => 'A person who shaped my life', 'stem' => 'This is a story about someone special in my life. Their name is '],
                    ['emoji' => '🏡', 'label' => 'A place I remember',   'sub' => 'A home, town, or spot I loved',  'stem' => 'This is a story about a place I will never forget. It was '],
                    ['emoji' => '😊', 'label' => 'A happy memory',       'sub' => 'A moment that made me smile',    'stem' => 'This is a story about a happy memory. It happened when '],
                    ['emoji' => '😂', 'label' => 'A funny moment',       'sub' => 'Something that still makes me laugh', 'stem' => 'This is a story about a funny thing that happened. '],
                    ['emoji' => '✈️', 'label' => 'An adventure',         'sub' => 'A trip or something brave I did', 'stem' => 'This is a story about an adventure I had. '],
                    ['emoji' => '🌟', 'label' => 'A life lesson',        'sub' => 'Something I learned along the way', 'stem' => 'This is a story about something important I learned in life. '],
                ] as $spark)
                    <button
                        type="button"
                        wire:click="useStarter(@js($spark['stem']))"
                        class="flex items-center gap-3 rounded-2xl border-2 border-gray-200 bg-white p-4 text-left transition-colors hover:border-blue-300 hover:bg-blue-50 active:bg-blue-100 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:border-blue-700 dark:hover:bg-blue-900/20"
                    >
                        <span class="text-3xl shrink-0">{{ $spark['emoji'] }}</span>
                        <span>
                            <span class="block text-lg font-bold text-gray-900 dark:text-white">{{ $spark['label'] }}</span>
                            <span class="block text-sm text-gray-500 dark:text-gray-400">{{ $spark['sub'] }}</span>
                        </span>
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Helpful tips --}}
        <div class="mb-5 rounded-2xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800/40 dark:bg-amber-900/10"
             x-data="{
                speaking: false,
                ttsText: 'Five things that make a story feel complete. ' +
                    'One. A character who wants something — even something small, like a kid trying to win a bet or a grandparent trying to remember a recipe. ' +
                    'Two. Something in the way — a problem, obstacle, or choice that keeps them from getting it easily. ' +
                    'Three. A clear place and moment — a backyard, a bus stop, a rainy Tuesday morning. Grounding the reader helps the story feel real. ' +
                    'Four. A change by the end — the character learns something, feels differently, or the situation shifts, even slightly. ' +
                    'Five. One vivid detail — a smell, a sound, a specific object, or a weird habit that makes the story memorable. ' +
                    'Keep it loose and fun. The best stories start with a small, relatable moment and let the rest unfold.',
                speak() {
                    if (this.speaking) { window.speechSynthesis.cancel(); this.speaking = false; return; }
                    const u = new SpeechSynthesisUtterance(this.ttsText);
                    u.rate = 0.9; u.pitch = 1;
                    u.onend = () => { this.speaking = false; };
                    window.speechSynthesis.speak(u);
                    this.speaking = true;
                }
             }">
            <div class="mb-3 flex items-center justify-between">
                <p class="text-base font-semibold text-amber-800 dark:text-amber-300">✏️ Five things that make a story feel complete:</p>
                <button @click="speak()" class="shrink-0 inline-flex items-center gap-1 rounded-full px-3 py-1.5 text-xs font-medium transition-colors"
                        :class="speaking ? 'bg-red-100 text-red-600 hover:bg-red-200' : 'bg-amber-100 text-amber-700 hover:bg-amber-200 dark:bg-amber-800/40 dark:text-amber-300'"
                        type="button">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.114 5.636a9 9 0 0 1 0 12.728M16.463 8.288a5.25 5.25 0 0 1 0 7.424M6.75 8.25l4.72-4.72a.75.75 0 0 1 1.28.53v15.88a.75.75 0 0 1-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.009 9.009 0 0 1 2.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75Z" />
                    </svg>
                    <span x-text="speaking ? 'Stop' : 'Listen'"></span>
                </button>
            </div>
            <ul class="space-y-2.5 text-base text-gray-700 dark:text-gray-300">
                <li>• <strong>A character who wants something</strong> — even something small, like a kid trying to win a bet or a grandparent trying to remember a recipe.</li>
                <li>• <strong>Something in the way</strong> — a problem, obstacle, or choice that keeps them from getting it easily.</li>
                <li>• <strong>A clear place and moment</strong> — a backyard, a bus stop, a rainy Tuesday morning. Grounding the reader helps the story feel real.</li>
                <li>• <strong>A change by the end</strong> — the character learns something, feels differently, or the situation shifts, even slightly.</li>
                <li>• <strong>One vivid detail</strong> — a smell, a sound, a specific object, or a weird habit that makes the story memorable.</li>
            </ul>
            <p class="mt-3 text-sm text-amber-700 dark:text-amber-400">Keep it loose and fun. The best stories start with a small, relatable moment and let the rest unfold.</p>
        </div>

        {{-- Paste your own completed story --}}
        <div class="mb-6 rounded-2xl border border-green-200 bg-white dark:border-zinc-700 dark:bg-zinc-800 p-4 shadow-sm">
            <div class="flex items-center gap-3 mb-3">
                <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-5 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                </div>
                <div>
                    <p class="text-base font-semibold text-gray-800 dark:text-gray-200">Already wrote your story?</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Paste it here and the AI will review, ask 1–2 quick questions, and polish it for you.</p>
                </div>
            </div>
            <button
                wire:click="startManualEntry"
                class="flex w-full items-center justify-center gap-2 rounded-xl border-2 border-green-600 bg-white px-6 py-3 text-base font-semibold text-green-700 transition-colors hover:bg-green-50 active:bg-green-100 dark:bg-zinc-900 dark:text-green-400 dark:hover:bg-zinc-800"
            >
                I Already Wrote My Story — Refine It
            </button>
        </div>

        {{-- Start from scratch + My Stories --}}
        <div class="pb-8 space-y-3">
            <button
                wire:click="startWriting"
                class="hidden xflex w-full items-center justify-center gap-3 rounded-xl bg-green-600 px-6 py-4 text-xl font-bold text-white shadow-md transition-colors hover:bg-green-700 active:bg-green-800"
            >
                I have my own idea — let's begin
                <svg xmlns="http://www.w3.org/2000/svg" class="size-6 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                </svg>
            </button>
            <div class="flex items-center justify-center">
                <a href="{{ route('books.index') }}" wire:navigate class="text-sm font-medium text-blue-600 py-1 px-3">My Stories</a>
            </div>
        </div>

    @elseif ($step === 'idea')
        {{-- Hero - Elderly-friendly large text --}}
        <div class="mb-4 text-center px-4">
            <h1 class="mb-1 text-2xl font-bold tracking-tight text-gray-900 dark:text-white">
                Create Your Story
            </h1>
        </div>

        {{-- Input Card - Larger touch targets --}}
        <div class="rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800 mb-4">
            <div class="p-4">
                <label class="mb-2 block text-lg font-medium text-gray-800 dark:text-gray-200">
                    What's your story about?
                </label>
                <div class="relative" x-data="{ hasText: @js(strlen($prompt) > 0) }">
                    <textarea
                        wire:model="prompt"
                        rows="5"
                        placeholder="🎤 Tap here first..."
                        class="mic-textarea w-full resize-none rounded-xl p-4 text-lg text-gray-800 dark:text-gray-100"
                        @input="hasText = $el.value.length > 0"
                        @focus="hasText = $el.value.length > 0"
                    ></textarea>
                    <div
                        x-show="!hasText"
                        class="mic-reminder pointer-events-none absolute bottom-3 left-0 right-0 flex justify-center px-4"
                    >
                        <span class="rounded-full bg-blue-600 px-4 py-2 text-sm font-bold text-white shadow-md w-full text-center">
                            🎤 Now tap the microphone key on your keyboard
                        </span>
                    </div>
                </div>
                @error('prompt')
                    <p class="mt-2 text-base text-red-600 font-medium">{{ $message }}</p>
                @enderror
            </div>
        </div>

        {{-- Inline Continue button - appears above keyboard, below textarea --}}
        <div x-data="{ hasText: @js(strlen($prompt) > 0) }"
             @input.window="hasText = document.querySelector('[wire\\:model=\'prompt\']')?.value?.length > 0">
            <div x-show="hasText"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 scale-95"
                 x-transition:enter-end="opacity-100 scale-100"
                 class="mt-4">
                <button
                    wire:click="toVoiceDraft"
                    wire:loading.attr="disabled"
                    class="flex w-full items-center justify-center gap-3 rounded-xl bg-green-600 px-6 py-4 text-xl font-bold text-white shadow-md transition-colors hover:bg-green-700 active:bg-green-800 disabled:opacity-60"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-6 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                    </svg>
                    <span wire:loading.remove wire:target="toVoiceDraft">Continue Your Story →</span>
                    <span wire:loading wire:target="toVoiceDraft">Starting...</span>
                </button>
                <div class="mt-3 flex items-center justify-between">
                    <button wire:click="$set('step', 'welcome')" class="text-sm font-medium text-gray-500 py-1 px-3 hover:text-gray-700 dark:text-gray-400">
                        ← Back to story ideas
                    </button>
                    <a href="{{ route('books.index') }}" wire:navigate class="text-sm font-medium text-blue-600 py-1 px-3">My Stories</a>
                </div>
            </div>
        </div>

        {{-- AI Writes For Me fork — feature-flagged, placed at bottom so user must scroll --}}
        @if(config('features.ai_writes'))
        <div class="mt-16 rounded-2xl border-2 border-dashed border-blue-200 bg-blue-50 dark:border-blue-800/40 dark:bg-blue-900/10 p-4">
            <p class="mb-3 text-center text-xs font-semibold text-blue-500 dark:text-blue-400 uppercase tracking-wide">Prefer not to write yourself?</p>
            <button
                wire:click="toAiPrompt"
                class="flex w-full items-center justify-center gap-3 rounded-xl border-2 border-blue-400 bg-white px-6 py-4 text-base font-bold text-blue-600 dark:bg-zinc-800 dark:text-blue-400 transition-colors hover:bg-blue-50 active:bg-blue-100"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" />
                </svg>
                Let the AI Write It For Me ✨
            </button>
            <p class="mt-2 text-center text-xs text-blue-400">Just give us a quick idea — the AI does the writing!</p>
        </div>
        @endif

    @elseif ($step === 'ai_guided')
        {{-- AI Guided Writer: 5 fields, all on one screen, no AI between questions --}}

        <div class="mb-5 text-center px-4">
            <div class="mb-3 flex size-14 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900/30 mx-auto">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-7 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" />
                </svg>
            </div>
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Tell Me Your True Story</h2>
            <p class="mt-1 text-base text-gray-500 dark:text-gray-400">Answer what you can — skip anything you're not sure about. The AI does the writing!</p>
            <p class="mt-2 text-sm text-blue-600 dark:text-blue-400">✅ Your answers are saved automatically as you type. Take your time.</p>
        </div>

        <div x-data="{
                hasInput: @js(trim($guidedSummary.$guidedTopic.$guidedCharacter.$guidedObstacle.$guidedSetting.$guidedChange.$guidedDetail) !== ''),
                hasEnough: @js($this->hasEnoughGuidedInput),
                checkInputs() {
                    const vals = Array.from($el.querySelectorAll('textarea')).map(t => t.value.trim());
                    this.hasInput = vals.some(v => v !== '');
                    const filled = vals.filter(v => v !== '').length;
                    const maxWords = Math.max(0, ...vals.map(v => v.split(/\s+/).filter(w => w).length));
                    this.hasEnough = filled >= 2 || maxWords >= 40;
                }
             }"
             x-init="$nextTick(() => checkInputs())">
            <div class="space-y-4">

            {{-- 0. One-sentence summary --}}
            <div class="rounded-2xl border border-blue-200 bg-blue-50 dark:border-blue-800/40 dark:bg-blue-900/10 p-4 shadow-sm">
                <label class="mb-1 flex items-center gap-2 text-base font-bold text-gray-800 dark:text-gray-200">
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-blue-600 text-sm font-bold text-white">0</span>
                    In one sentence, what is this story about?
                </label>
                <p class="mb-2 pl-9 text-sm text-gray-500 dark:text-gray-400">This gives the AI a clear direction for the story — like the one-line version you'd tell a friend.</p>
                <textarea
                    wire:model.debounce.1500ms="guidedSummary"
                    x-on:input="checkInputs()"
                    rows="2"
                    class="mic-textarea w-full resize-none rounded-xl p-3 text-base text-gray-800 dark:text-gray-100"
                ></textarea>
            </div>

            {{-- 1. Topic / what happened --}}
            <div class="rounded-2xl border-2 border-blue-300 bg-white dark:border-blue-700 dark:bg-zinc-800 p-4 shadow-sm">
                <label class="mb-1 flex items-center gap-2 text-base font-bold text-gray-800 dark:text-gray-200">
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-blue-600 text-sm font-bold text-white">1</span>
                    What happened in this story?
                </label>
                <p class="mb-2 pl-9 text-sm text-gray-500 dark:text-gray-400">A memory, a person, an event, or a small moment. Keep it simple — a few words is enough to start.</p>
                <textarea
                    wire:model.debounce.1500ms="guidedTopic"
                    x-on:input="checkInputs()"
                    rows="2"
                    class="mic-textarea w-full resize-none rounded-xl p-3 text-base text-gray-800 dark:text-gray-100"
                ></textarea>
            </div>

            {{-- 2. Character / want --}}
            <div class="rounded-2xl border border-gray-200 bg-white dark:border-zinc-700 dark:bg-zinc-800 p-4 shadow-sm">
                <label class="mb-1 flex items-center gap-2 text-base font-bold text-gray-800 dark:text-gray-200">
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-blue-100 text-sm font-bold text-blue-700 dark:bg-blue-900/40 dark:text-blue-400">2</span>
                    Who is the main character, and what do they want?
                </label>
                <p class="mb-2 pl-9 text-sm text-gray-500 dark:text-gray-400">Every good story has a character who wants something. It can be tiny: a grandchild trying to remember a recipe, a friend hoping to win a bet, you wanting to feel at home.</p>
                <textarea
                    wire:model.debounce.1500ms="guidedCharacter"
                    x-on:input="checkInputs()"
                    rows="2"
                    class="mic-textarea w-full resize-none rounded-xl p-3 text-base text-gray-800 dark:text-gray-100"
                ></textarea>
            </div>

            {{-- 3. Obstacle --}}
            <div class="rounded-2xl border border-gray-200 bg-white dark:border-zinc-700 dark:bg-zinc-800 p-4 shadow-sm">
                <label class="mb-1 flex items-center gap-2 text-base font-bold text-gray-800 dark:text-gray-200">
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-blue-100 text-sm font-bold text-blue-700 dark:bg-blue-900/40 dark:text-blue-400">3</span>
                    What got in the way, or made this moment interesting?
                </label>
                <p class="mb-2 pl-9 text-sm text-gray-500 dark:text-gray-400">A problem, a tough choice, something hard, or even a happy surprise. This is the engine of the story.</p>
                <textarea
                    wire:model.debounce.1500ms="guidedObstacle"
                    x-on:input="checkInputs()"
                    rows="2"
                    class="mic-textarea w-full resize-none rounded-xl p-3 text-base text-gray-800 dark:text-gray-100"
                ></textarea>
            </div>

            {{-- 4. Setting --}}
            <div class="rounded-2xl border border-gray-200 bg-white dark:border-zinc-700 dark:bg-zinc-800 p-4 shadow-sm">
                <label class="mb-1 flex items-center gap-2 text-base font-bold text-gray-800 dark:text-gray-200">
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-blue-100 text-sm font-bold text-blue-700 dark:bg-blue-900/40 dark:text-blue-400">4</span>
                    Where and when did this happen?
                </label>
                <p class="mb-2 pl-9 text-sm text-gray-500 dark:text-gray-400">A specific place and moment — a kitchen table, a bus stop, a rainy Tuesday morning. Grounding the reader makes the story feel real.</p>
                <textarea
                    wire:model.debounce.1500ms="guidedSetting"
                    x-on:input="checkInputs()"
                    rows="2"
                    class="mic-textarea w-full resize-none rounded-xl p-3 text-base text-gray-800 dark:text-gray-100"
                ></textarea>
            </div>

            {{-- 5. Change --}}
            <div class="rounded-2xl border border-gray-200 bg-white dark:border-zinc-700 dark:bg-zinc-800 p-4 shadow-sm">
                <label class="mb-1 flex items-center gap-2 text-base font-bold text-gray-800 dark:text-gray-200">
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-blue-100 text-sm font-bold text-blue-700 dark:bg-blue-900/40 dark:text-blue-400">5</span>
                    How did this moment leave you, or what changed?
                </label>
                <p class="mb-2 pl-9 text-sm text-gray-500 dark:text-gray-400">What did you learn, feel differently, or notice by the end? This gives the story its heart and makes it feel complete.</p>
                <textarea
                    wire:model.debounce.1500ms="guidedChange"
                    x-on:input="checkInputs()"
                    rows="2"
                    class="mic-textarea w-full resize-none rounded-xl p-3 text-base text-gray-800 dark:text-gray-100"
                ></textarea>
            </div>

            {{-- 6. Vivid detail --}}
            <div class="rounded-2xl border border-gray-200 bg-white dark:border-zinc-700 dark:bg-zinc-800 p-4 shadow-sm">
                <label class="mb-1 flex items-center gap-2 text-base font-bold text-gray-800 dark:text-gray-200">
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-blue-100 text-sm font-bold text-blue-700 dark:bg-blue-900/40 dark:text-blue-400">6</span>
                    One small, vivid detail you remember
                </label>
                <p class="mb-2 pl-9 text-sm text-gray-500 dark:text-gray-400">A smell, a sound, something someone said, or an object. The little things make a story stick in the reader's mind.</p>
                <textarea
                    wire:model.debounce.1500ms="guidedDetail"
                    x-on:input="checkInputs()"
                    rows="2"
                    class="mic-textarea w-full resize-none rounded-xl p-3 text-base text-gray-800 dark:text-gray-100"
                ></textarea>
            </div>

        </div>

        {{-- Generate button --}}
        <div class="mt-6 space-y-3 pb-8">
            @if ($guidedValidationMessage)
                <div class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800/50 dark:bg-amber-900/20 dark:text-amber-200">
                    {{ $guidedValidationMessage }}
                </div>
            @endif

            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800/40 dark:bg-amber-900/10">
                <p class="mb-2 text-sm font-semibold text-amber-800 dark:text-amber-300">A great first draft has five building blocks. The more you can share, the stronger the story:</p>
                <ul class="space-y-1 text-sm text-gray-700 dark:text-gray-300">
                    <li>• A character who wants something</li>
                    <li>• Something that gets in the way</li>
                    <li>• A clear place and moment</li>
                    <li>• A change by the end</li>
                    <li>• One vivid detail</li>
                </ul>
            </div>

            <div x-show="hasInput && !hasEnough" x-cloak
                class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800/50 dark:bg-amber-900/20 dark:text-amber-200">
                This sounds like a great memory! To write your true story accurately, could you share a little more? Either fill in a second box, or add more detail to one box — about 40 words is plenty.
            </div>

            <button
                wire:click="aiGuidedGenerate"
                wire:loading.attr="disabled"
                :disabled="!hasEnough"
                class="flex w-full items-center justify-center gap-3 rounded-xl bg-blue-600 px-6 py-5 text-xl font-bold text-white shadow-md transition-colors hover:bg-blue-700 active:bg-blue-800 disabled:bg-gray-400 disabled:opacity-100 disabled:cursor-not-allowed"
            >
                <span wire:loading.remove wire:target="aiGuidedGenerate">✨ Write My Story!</span>
                <span wire:loading wire:target="aiGuidedGenerate" class="flex items-center gap-2">
                    <span class="size-5 rounded-full border-2 border-white/40 border-t-white animate-spin inline-block"></span>
                    Starting your story…
                </span>
            </button>

            @if($guidedSummary || $guidedTopic || $guidedCharacter || $guidedObstacle || $guidedSetting || $guidedChange || $guidedDetail)
                <button
                    type="button"
                    @click="if (confirm('Clear all your answers and start fresh?')) { $wire.startOver() }"
                    class="flex w-full items-center justify-center gap-2 rounded-xl border-2 border-red-200 bg-white px-6 py-3 text-base font-semibold text-red-500 hover:bg-red-50 active:bg-red-100 dark:border-red-800 dark:bg-zinc-800 dark:text-red-400"
                >
                    🗑 Clear all answers and start over
                </button>
            @endif

            @if ($guidedDraftSavedAt)
                <p class="text-center text-sm text-gray-400">Draft saved at {{ $guidedDraftSavedAt }}</p>
            @endif

            <button wire:click="$set('step', 'welcome')" class="flex w-full items-center justify-center gap-1 py-2 text-sm text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                ← Back
            </button>
        </div>

        </div>

    @elseif ($step === 'kid_wizard')
        {{-- Kid wizard (under 6) — big buttons, simple words, and voice input --}}
        <div class="mb-5 px-4"
            x-data="{
                recording: false,
                transcribing: false,
                activeField: '',
                supported: ('MediaDevices' in window && 'getUserMedia' in navigator.mediaDevices),
                showRecorder: false,
                isIos: (/iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.userAgent.includes('Mac') && 'ontouchend' in document)),
                isActive(field) { return this.activeField === field; },
                isBusy(field) { return this.transcribing || (this.recording && this.activeField !== field); },
                async startVoice(field) {
                    if (! this.supported) return;
                    if (this.recording) {
                        window.kidVoice.stop();
                        return;
                    }
                    await window.kidVoice.start(field, this.$wire, (s) => {
                        if (s.recording !== undefined) this.recording = s.recording;
                        if (s.transcribing !== undefined) this.transcribing = s.transcribing;
                        if (s.activeField !== undefined) this.activeField = s.activeField;
                    });
                }
            }"
        >
            <div class="mb-6 text-center">
                <h1 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">Let's make a story!</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1"
                    x-text="isIos ? 'Pick the answers you like. Tap in the box to start the microphone.' : 'Pick the answers you like. You can type your story in the boxes below.'"
                ></p>
            </div>

            {{-- Progress dots --}}
            <div class="mb-6 flex items-center justify-center gap-2">
                <div class="size-3 rounded-full {{ $kidStep === 'idea' ? 'bg-blue-600' : 'bg-gray-300' }}"></div>
                <div class="size-3 rounded-full {{ $kidStep === 'who' ? 'bg-blue-600' : 'bg-gray-300' }}"></div>
                <div class="size-3 rounded-full {{ $kidStep === 'ending' ? 'bg-blue-600' : 'bg-gray-300' }}"></div>
            </div>

            @if ($kidStep === 'idea')
                <div class="space-y-6">
                    @php
                        $kidIdeas = [
                            '🦸' => 'Superhero',
                            '🚒' => 'Fire truck',
                            '🦖' => 'Dinosaur',
                            '📚' => 'Reading',
                            '🌳' => 'Park',
                            '🚀' => 'Space',
                        ];
                    @endphp

                    <div>
                        <h2 class="mb-3 text-lg font-bold text-gray-900 dark:text-white">Pick a fun idea</h2>
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            @foreach ($kidIdeas as $icon => $idea)
                                <div class="relative">
                                    <input
                                        type="radio"
                                        id="kid-idea-{{ $loop->index }}"
                                        name="kidIdea"
                                        class="peer absolute inset-0 h-full w-full opacity-0 cursor-pointer"
                                        wire:model="kidIdea"
                                        value="{{ $idea }}"
                                    />
                                    <label
                                        for="kid-idea-{{ $loop->index }}"
                                        class="flex h-full flex-col items-center justify-center gap-1 rounded-2xl border-2 p-4 text-center text-base font-bold transition border-gray-200 bg-white text-gray-700 hover:border-blue-300 dark:border-zinc-600 dark:bg-zinc-800 dark:text-gray-300 peer-checked:border-blue-600 peer-checked:bg-blue-600 peer-checked:text-white peer-checked:shadow-md dark:peer-checked:border-blue-400 dark:peer-checked:bg-blue-500 dark:peer-checked:text-white"
                                    >
                                        <span class="text-3xl" aria-hidden="true">{{ $icon }}</span>
                                        <span>{{ $idea }}</span>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-3">
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Or type your own idea</label>
                            <div class="relative">
                                <input
                                    wire:model="kidIdea"
                                    type="text"
                                    class="w-full rounded-2xl border border-gray-300 bg-white py-3 pl-4 pr-4 text-base text-gray-900 placeholder-gray-400 focus:border-blue-500 focus:ring-blue-500 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                                    placeholder="Your idea"
                                />
                                <button
                                    type="button"
                                    x-on:click="startVoice('kidIdea')"
                                    x-bind:disabled="! supported || isBusy('kidIdea')"
                                    x-show="! isIos && showRecorder"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 flex items-center gap-2 rounded-full bg-blue-600 px-3 py-1.5 text-sm font-bold text-white shadow hover:bg-blue-700 cursor-pointer disabled:opacity-60"
                                >
                                    <span x-show="! (recording || (transcribing && isActive('kidIdea')))">Tap to talk</span>
                                    <span x-show="recording && isActive('kidIdea')">Stop</span>
                                    <span x-show="transcribing && isActive('kidIdea')">Working...</span>
                                </button>
                                <p x-show="isIos" class="mt-1 text-right text-xs text-gray-500 dark:text-gray-400">Tap in the box to start the microphone.</p>
                            </div>
                        </div>
                    </div>

                    <div id="kid-what-section">
                        <h2 class="mb-3 text-lg font-bold text-gray-900 dark:text-white">What happened?</h2>
                        <div class="relative">
                            <textarea
                                wire:model="kidWhat"
                                rows="4"
                                class="w-full rounded-2xl border border-gray-300 bg-white p-4 text-base text-gray-900 placeholder-gray-400 focus:border-blue-500 focus:ring-blue-500 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                                placeholder="Tell us one thing that happened..."
                            ></textarea>
                            <button
                                type="button"
                                x-on:click="startVoice('kidWhat')"
                                x-bind:disabled="! supported || isBusy('kidWhat')"
                                x-show="! isIos && showRecorder"
                                class="absolute bottom-3 right-3 flex items-center gap-2 rounded-full bg-blue-600 px-3 py-1.5 text-sm font-bold text-white shadow hover:bg-blue-700 cursor-pointer disabled:opacity-60"
                            >
                                <span x-show="! (recording || (transcribing && isActive('kidWhat')))">Tap to talk</span>
                                <span x-show="recording && isActive('kidWhat')">Stop</span>
                                <span x-show="transcribing && isActive('kidWhat')">Working...</span>
                            </button>
                            <p x-show="isIos" class="mt-1 text-right text-xs text-gray-500 dark:text-gray-400">Tap in the box to start the microphone.</p>
                        </div>
                    </div>

                    @if ($kidValidationMessage)
                        <p class="rounded-xl bg-red-50 p-3 text-center text-sm font-semibold text-red-600 dark:bg-red-900/20 dark:text-red-300">{{ $kidValidationMessage }}</p>
                    @endif

                    <div class="flex justify-end">
                        <button
                            type="button"
                            wire:click="kidNextStep"
                            class="rounded-xl bg-blue-600 px-8 py-4 text-lg font-bold text-white shadow hover:bg-blue-700 cursor-pointer"
                        >
                            Next
                        </button>
                    </div>
                </div>
            @elseif ($kidStep === 'who')
                <div class="space-y-6">
                    @php
                        $kidWho = [
                            '👤' => 'Me',
                            '👩' => 'Mom',
                            '👨' => 'Dad',
                            '👦' => 'Brother',
                            '👧' => 'Sister',
                            '🤝' => 'Friend',
                            '🐶' => 'Pet',
                            '👵' => 'Grandma or Grandpa',
                        ];
                    @endphp

                    <div>
                        <h2 class="mb-3 text-lg font-bold text-gray-900 dark:text-white">Who was there?</h2>
                        <div class="grid grid-cols-2 gap-3">
                            @foreach ($kidWho as $icon => $who)
                                <div class="relative">
                                    <input
                                        type="radio"
                                        id="kid-who-{{ $loop->index }}"
                                        name="kidWho"
                                        class="peer absolute inset-0 h-full w-full opacity-0 cursor-pointer"
                                        wire:model="kidWho"
                                        value="{{ $who }}"
                                    />
                                    <label
                                        for="kid-who-{{ $loop->index }}"
                                        class="flex h-full flex-col items-center justify-center gap-1 rounded-2xl border-2 p-4 text-center text-base font-bold transition border-gray-200 bg-white text-gray-700 hover:border-blue-300 dark:border-zinc-600 dark:bg-zinc-800 dark:text-gray-300 peer-checked:border-blue-600 peer-checked:bg-blue-600 peer-checked:text-white peer-checked:shadow-md dark:peer-checked:border-blue-400 dark:peer-checked:bg-blue-500 dark:peer-checked:text-white"
                                    >
                                        <span class="text-3xl" aria-hidden="true">{{ $icon }}</span>
                                        <span>{{ $who }}</span>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    @php
                        $kidWhere = [
                            '🏠' => 'Home',
                            '🌳' => 'Park',
                            '🏫' => 'School',
                            '🛒' => 'Store',
                            '🏡' => "Grandma's house",
                            '🚗' => 'In the car',
                        ];
                    @endphp

                    <div id="kid-where-section">
                        <h2 class="mb-3 text-lg font-bold text-gray-900 dark:text-white">Where were you?</h2>
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            @foreach ($kidWhere as $icon => $where)
                                <div class="relative">
                                    <input
                                        type="radio"
                                        id="kid-where-{{ $loop->index }}"
                                        name="kidWhere"
                                        class="peer absolute inset-0 h-full w-full opacity-0 cursor-pointer"
                                        wire:model="kidWhere"
                                        value="{{ $where }}"
                                    />
                                    <label
                                        for="kid-where-{{ $loop->index }}"
                                        class="flex h-full flex-col items-center justify-center gap-1 rounded-2xl border-2 p-4 text-center text-base font-bold transition border-gray-200 bg-white text-gray-700 hover:border-blue-300 dark:border-zinc-600 dark:bg-zinc-800 dark:text-gray-300 peer-checked:border-blue-600 peer-checked:bg-blue-600 peer-checked:text-white peer-checked:shadow-md dark:peer-checked:border-blue-400 dark:peer-checked:bg-blue-500 dark:peer-checked:text-white"
                                    >
                                        <span class="text-3xl" aria-hidden="true">{{ $icon }}</span>
                                        <span>{{ $where }}</span>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    @if ($kidValidationMessage)
                        <p class="rounded-xl bg-red-50 p-3 text-center text-sm font-semibold text-red-600 dark:bg-red-900/20 dark:text-red-300">{{ $kidValidationMessage }}</p>
                    @endif

                    <div id="kid-step2-actions" class="flex justify-between">
                        <button
                            type="button"
                            wire:click="kidBack"
                            class="rounded-xl border-2 border-gray-300 bg-white px-6 py-3 text-base font-bold text-gray-700 hover:bg-gray-50 cursor-pointer dark:border-zinc-600 dark:bg-zinc-800 dark:text-gray-300"
                        >
                            Back
                        </button>
                        <button
                            type="button"
                            wire:click="kidNextStep"
                            class="rounded-xl bg-blue-600 px-8 py-4 text-lg font-bold text-white shadow hover:bg-blue-700 cursor-pointer"
                        >
                            Next
                        </button>
                    </div>
                </div>
            @elseif ($kidStep === 'ending')
                <div class="space-y-6">
                    <div>
                        <h2 class="mb-3 text-lg font-bold text-gray-900 dark:text-white">How did it end?</h2>
                        <div class="grid grid-cols-2 gap-3">
                            @foreach (['We found it', 'We laughed', 'We helped', 'It was a surprise', 'We went home', 'Everything was okay'] as $ending)
                                <div class="relative">
                                    <input
                                        type="radio"
                                        id="kid-ending-{{ $loop->index }}"
                                        name="kidEnding"
                                        class="peer absolute inset-0 h-full w-full opacity-0 cursor-pointer"
                                        wire:model="kidEnding"
                                        value="{{ $ending }}"
                                    />
                                    <label
                                        for="kid-ending-{{ $loop->index }}"
                                        class="flex h-full flex-col items-center justify-center rounded-2xl border-2 p-4 text-center text-base font-bold transition border-gray-200 bg-white text-gray-700 hover:border-blue-300 dark:border-zinc-600 dark:bg-zinc-800 dark:text-gray-300 peer-checked:border-blue-600 peer-checked:bg-blue-600 peer-checked:text-white peer-checked:shadow-md dark:peer-checked:border-blue-400 dark:peer-checked:bg-blue-500 dark:peer-checked:text-white"
                                    >
                                        {{ $ending }}
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div id="kid-detail-section">
                        <h2 class="mb-3 text-lg font-bold text-gray-900 dark:text-white">One little detail</h2>
                        <div class="relative">
                            <textarea
                                wire:model="kidDetail"
                                rows="3"
                                class="w-full rounded-2xl border border-gray-300 bg-white p-4 text-base text-gray-900 placeholder-gray-400 focus:border-blue-500 focus:ring-blue-500 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                                placeholder="A sound, a color, or something funny..."
                            ></textarea>
                            <button
                                type="button"
                                x-on:click="startVoice('kidDetail')"
                                x-bind:disabled="! supported || isBusy('kidDetail')"
                                x-show="! isIos && showRecorder"
                                class="absolute bottom-3 right-3 flex items-center gap-2 rounded-full bg-blue-600 px-3 py-1.5 text-sm font-bold text-white shadow hover:bg-blue-700 cursor-pointer disabled:opacity-60"
                            >
                                <span x-show="! (recording || (transcribing && isActive('kidDetail')))">Tap to talk</span>
                                <span x-show="recording && isActive('kidDetail')">Stop</span>
                                <span x-show="transcribing && isActive('kidDetail')">Working...</span>
                            </button>
                            <p x-show="isIos" class="mt-1 text-right text-xs text-gray-500 dark:text-gray-400">Tap in the box to start the microphone.</p>
                        </div>
                    </div>

                    @if ($kidValidationMessage)
                        <p class="rounded-xl bg-red-50 p-3 text-center text-sm font-semibold text-red-600 dark:bg-red-900/20 dark:text-red-300">{{ $kidValidationMessage }}</p>
                    @endif

                    <div class="flex justify-between">
                        <button
                            type="button"
                            wire:click="kidBack"
                            class="rounded-xl border-2 border-gray-300 bg-white px-6 py-3 text-base font-bold text-gray-700 hover:bg-gray-50 cursor-pointer dark:border-zinc-600 dark:bg-zinc-800 dark:text-gray-300"
                        >
                            Back
                        </button>
                        <button
                            type="button"
                            wire:click="kidGenerate"
                            wire:loading.attr="disabled"
                            class="rounded-xl bg-green-600 px-8 py-4 text-lg font-bold text-white shadow hover:bg-green-700 cursor-pointer disabled:opacity-60"
                        >
                            Write my story!
                        </button>
                    </div>
                </div>
            @endif

            <script>
                window.kidVoice = {
                    transcribeUrl: '{{ route('voice.transcribe') }}',
                    csrf: '{{ csrf_token() }}',
                    audioContext: null,
                    stream: null,
                    processor: null,
                    source: null,
                    chunks: [],
                    wire: null,
                    field: null,
                    onState: null,

                    async start(field, wire, onState) {
                        this.wire = wire;
                        this.field = field;
                        this.onState = onState;
                        this.chunks = [];

                        try {
                            this.stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                            this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
                            await this.audioContext.resume();
                            this.source = this.audioContext.createMediaStreamSource(this.stream);
                            this.processor = this.audioContext.createScriptProcessor(4096, 1, 1);

                            this.processor.onaudioprocess = (e) => {
                                this.chunks.push(new Float32Array(e.inputBuffer.getChannelData(0)));
                            };

                            this.source.connect(this.processor);
                            this.processor.connect(this.audioContext.destination);

                            onState({ recording: true, activeField: field });
                        } catch (err) {
                            onState({ recording: false, activeField: '', transcribing: false });
                            alert('Could not use the microphone. Please allow microphone access and try again.');
                        }
                    },

                    stop() {
                        if (this.processor) {
                            this.processor.disconnect();
                            this.processor = null;
                        }
                        if (this.source) {
                            this.source.disconnect();
                            this.source = null;
                        }
                        if (this.stream) {
                            this.stream.getTracks().forEach((t) => t.stop());
                            this.stream = null;
                        }
                        if (this.audioContext) {
                            this.audioContext.close();
                            const sampleRate = this.audioContext.sampleRate;
                            this.audioContext = null;

                            this.onState({ recording: false });

                            const wav = this.encodeWav(this.chunks, sampleRate);
                            this.upload(new Blob([wav], { type: 'audio/wav' }));
                        } else {
                            this.onState({ recording: false, activeField: '', transcribing: false });
                        }
                    },

                    encodeWav(chunks, sampleRate) {
                        const total = chunks.reduce((sum, c) => sum + c.length, 0);
                        const buffer = new ArrayBuffer(44 + total * 2);
                        const view = new DataView(buffer);

                        const writeString = (offset, string) => {
                            for (let i = 0; i < string.length; i++) {
                                view.setUint8(offset + i, string.charCodeAt(i));
                            }
                        };

                        writeString(0, 'RIFF');
                        view.setUint32(4, 36 + total * 2, true);
                        writeString(8, 'WAVE');
                        writeString(12, 'fmt ');
                        view.setUint32(16, 16, true);
                        view.setUint16(20, 1, true);
                        view.setUint16(22, 1, true);
                        view.setUint32(24, sampleRate, true);
                        view.setUint32(28, sampleRate * 2, true);
                        view.setUint16(32, 2, true);
                        view.setUint16(34, 16, true);
                        writeString(36, 'data');
                        view.setUint32(40, total * 2, true);

                        const data = new Int16Array(buffer, 44, total);
                        let idx = 0;
                        for (const chunk of chunks) {
                            for (let i = 0; i < chunk.length; i++) {
                                const s = Math.max(-1, Math.min(1, chunk[i]));
                                data[idx++] = s < 0 ? s * 0x8000 : s * 0x7FFF;
                            }
                        }

                        return buffer;
                    },

                    async upload(blob) {
                        this.onState({ transcribing: true, activeField: this.field });

                        const form = new FormData();
                        form.append('audio', blob, 'voice.wav');

                        try {
                            const res = await fetch(this.transcribeUrl, {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': this.csrf },
                                body: form,
                            });
                            const data = await res.json();

                            if (data.text) {
                                const current = this.wire.get(this.field) || '';
                                this.wire.set(this.field, current ? current + ' ' + data.text : data.text);
                            } else if (data.error) {
                                alert(data.error);
                            }
                        } catch (err) {
                            alert('Could not send your recording. Please check your connection and try again.');
                        }

                        this.onState({ transcribing: false, activeField: '' });
                    },
                };
            </script>
        </div>

    @elseif ($step === 'duplicate')
        {{-- Duplicate warning: this looks like an existing story --}}
        <div class="mb-5 text-center px-4">
            <div class="mb-3 flex size-14 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900/30 mx-auto">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-7 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.647 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                </svg>
            </div>
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">This sounds familiar</h2>
            <p class="mt-1 text-base text-gray-500 dark:text-gray-400">
                This looks a lot like your story
                <a href="{{ route('books.show', $similarStoryId) }}" wire:navigate class="font-semibold text-blue-600 hover:underline dark:text-blue-400">{{ $similarStoryTitle }}</a>.
                Did you mean to write it again?
            </p>
        </div>

        <div class="rounded-2xl border-2 border-amber-300 bg-white p-5 shadow-sm dark:border-amber-700 dark:bg-zinc-800 space-y-4">
            <p class="text-base text-gray-700 dark:text-gray-300">If this is a different memory, try something new. Otherwise, you can still continue.</p>

            <div class="flex flex-col gap-3">
                <button type="button" wire:click="trySomethingNew" class="flex w-full items-center justify-center gap-2 rounded-xl bg-blue-600 px-5 py-3 text-base font-bold text-white shadow-md transition hover:bg-blue-700">
                    Try something new
                </button>

                <a href="{{ route('books.show', $similarStoryId) }}" wire:navigate class="flex w-full items-center justify-center gap-2 rounded-xl border-2 border-amber-200 bg-white px-5 py-3 text-base font-semibold text-amber-600 transition hover:bg-amber-50 dark:border-amber-800 dark:bg-zinc-800 dark:text-amber-200">
                    View the existing story
                </a>

                <button type="button" wire:click="continueWithDuplicate" wire:loading.attr="disabled" class="flex w-full items-center justify-center gap-2 rounded-xl border-2 border-gray-200 bg-white px-5 py-3 text-base font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-gray-300">
                    <span wire:loading.remove wire:target="continueWithDuplicate">Continue anyway</span>
                    <span wire:loading wire:target="continueWithDuplicate" class="flex items-center gap-2">
                        <span class="size-5 rounded-full border-2 border-gray-400/40 border-t-gray-700 animate-spin inline-block"></span>
                        Continuing…
                    </span>
                </button>
            </div>
        </div>

    @elseif ($step === 'clarify')
        {{-- AI follow-up question to gather a little more context --}}
        <div class="mb-5 text-center px-4">
            <div class="mb-3 flex size-14 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900/30 mx-auto">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-7 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" />
                </svg>
            </div>
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">A quick follow-up</h2>
            <p class="mt-1 text-base text-gray-500 dark:text-gray-400">This one small detail helps the AI write your story just the way you remember it.</p>
        </div>

        <div class="rounded-2xl border-2 border-blue-300 bg-white p-5 shadow-sm dark:border-blue-700 dark:bg-zinc-800 space-y-4">
            <p class="text-lg font-medium text-gray-800 dark:text-gray-200">{{ $clarifyQuestion }}</p>

            <textarea
                wire:model="clarifyAnswer"
                rows="3"
                class="mic-textarea w-full resize-none rounded-xl p-4 text-lg text-gray-800 dark:text-gray-100"
                placeholder="Tap here and tell us a little more..."
            ></textarea>

            <button
                wire:click="submitClarify"
                wire:loading.attr="disabled"
                class="flex w-full items-center justify-center gap-3 rounded-xl bg-blue-600 px-6 py-5 text-xl font-bold text-white shadow-md transition-colors hover:bg-blue-700 active:bg-blue-800 disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="submitClarify">Add detail & continue</span>
                <span wire:loading wire:target="submitClarify" class="flex items-center gap-2">
                    <span class="size-5 rounded-full border-2 border-white/40 border-t-white animate-spin inline-block"></span>
                    Thinking…
                </span>
            </button>

            <button
                wire:click="skipClarify"
                wire:loading.attr="disabled"
                class="flex w-full items-center justify-center gap-2 rounded-xl border-2 border-blue-200 bg-white px-6 py-4 text-base font-semibold text-blue-600 hover:bg-blue-50 active:bg-blue-100 dark:border-blue-800 dark:bg-zinc-800 dark:text-blue-400 dark:hover:bg-blue-900/20"
            >
                No thanks — write my story now
            </button>
        </div>

    @elseif ($step === 'ai_prompt')
        {{-- AI Quick-Write step --}}
        <div class="mb-5 text-center px-4">
            <div class="mb-4 flex size-14 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900/30 mx-auto">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-7 text-blue-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" />
                </svg>
            </div>
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">What's your story idea?</h2>
            <p class="mt-1 text-base text-gray-500 dark:text-gray-400">Describe it in a sentence or two — the AI will write the full story for you!</p>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800 p-5 space-y-5">

            {{-- Prompt --}}
            <div>
                <label class="mb-2 block text-lg font-medium text-gray-800 dark:text-gray-200">Your story idea</label>
                <div class="relative">
                    <textarea
                        wire:model="prompt"
                        rows="4"
                        placeholder="🎤 e.g. A mystery about a gentleman noticing strange goings-on in a senior living community..."
                        class="mic-textarea w-full resize-none rounded-xl p-4 text-lg text-gray-800 dark:text-gray-100"
                    ></textarea>
                </div>
                @error('prompt')
                    <p class="mt-2 text-base text-red-600 font-medium">{{ $message }}</p>
                @enderror
            </div>

            {{-- Length --}}
            <div>
                <label class="mb-2 block text-base font-medium text-gray-700 dark:text-gray-300">How long?</label>
                <div class="grid grid-cols-3 gap-3">
                    @foreach([
                        ['value' => 'short_story', 'label' => 'Short Story', 'sub' => '~600 words'],
                        ['value' => 'chapter',     'label' => 'First Chapter', 'sub' => '~2,000 words'],
                        ['value' => 'outline',     'label' => 'Novel Outline', 'sub' => 'Chapter plan'],
                    ] as $f)
                        <button type="button" wire:click="$set('format', '{{ $f['value'] }}')"
                            class="flex flex-col items-center justify-center rounded-xl border-2 px-3 py-3 text-center transition-colors
                                {{ $format === $f['value']
                                    ? 'border-blue-400 bg-blue-50 dark:bg-blue-900/20'
                                    : 'border-gray-200 bg-gray-50 hover:border-gray-300 dark:border-zinc-600 dark:bg-zinc-700' }}">
                            <span class="text-sm font-semibold {{ $format === $f['value'] ? 'text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-200' }}">{{ $f['label'] }}</span>
                            <span class="text-xs {{ $format === $f['value'] ? 'text-blue-400' : 'text-gray-400' }}">{{ $f['sub'] }}</span>
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Genre --}}
            <div>
                <label class="mb-2 block text-base font-medium text-gray-700 dark:text-gray-300">Story type <span class="text-gray-400 font-normal">(optional)</span></label>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    @foreach([
                        ['value' => '',                  'label' => 'Any'],
                        ['value' => 'mystery',           'label' => 'Mystery'],
                        ['value' => 'romance',           'label' => 'Romance'],
                        ['value' => 'fantasy',           'label' => 'Fantasy'],
                        ['value' => 'historical fiction','label' => 'Historical'],
                        ['value' => 'science fiction',   'label' => 'Sci-Fi'],
                        ['value' => 'horror',            'label' => 'Horror'],
                        ['value' => 'non-fiction',       'label' => 'True Story'],
                    ] as $g)
                        <button type="button" wire:click="$set('genre', '{{ $g['value'] }}')"
                            class="rounded-xl border-2 px-3 py-3 text-center transition-colors
                                {{ $genre === $g['value']
                                    ? 'border-blue-400 bg-blue-50 dark:bg-blue-900/20'
                                    : 'border-gray-200 bg-gray-50 hover:border-gray-300 dark:border-zinc-600 dark:bg-zinc-700' }}">
                            <span class="text-sm font-semibold {{ $genre === $g['value'] ? 'text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-200' }}">{{ $g['label'] }}</span>
                        </button>
                    @endforeach
                </div>
            </div>

        </div>

        <div class="mt-4 pb-8 space-y-3">
            <button
                wire:click="generateFromPrompt"
                wire:loading.attr="disabled"
                class="flex w-full items-center justify-center gap-3 rounded-xl bg-blue-600 px-6 py-5 text-xl font-bold text-white shadow-md transition-colors hover:bg-blue-700 active:bg-blue-800 disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="generateFromPrompt">✨ Write My Story!</span>
                <span wire:loading wire:target="generateFromPrompt">Starting…</span>
            </button>
            <button wire:click="$set('step', 'idea')"
                class="flex w-full items-center justify-center gap-2 rounded-xl border-2 border-gray-300 bg-white px-5 py-3 text-base font-semibold text-gray-700 dark:border-zinc-600 dark:bg-zinc-800 dark:text-gray-300">
                ← Back
            </button>
            <div class="flex items-center justify-center">
                <a href="{{ route('books.index') }}" wire:navigate class="text-sm font-medium text-blue-600 py-1 px-3">My Stories</a>
            </div>
        </div>

    @elseif ($step === 'details')
        {{-- Step 2: Details --}}
        <div class="mb-8 text-center">
            <div class="mb-3 flex items-center justify-center gap-2 text-sm text-gray-400">
                <span class="font-semibold text-blue-500">2</span>
                <span>/</span>
                <span>2</span>
            </div>
            <div class="mx-auto mb-6 h-2 w-64 overflow-hidden rounded-full bg-gray-200 dark:bg-zinc-700">
                <div class="h-full w-full rounded-full bg-blue-500"></div>
            </div>
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Let&rsquo;s create your story!</h2>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800 p-6 space-y-5">

            {{-- Title --}}
            <div>
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                    Title <span class="text-gray-400 font-normal">(optional)</span>
                </label>
                <input
                    type="text"
                    wire:model="title"
                    placeholder="My Amazing Story"
                    class="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-800 placeholder-gray-400 focus:border-blue-400 focus:outline-none focus:ring-1 focus:ring-blue-400 dark:border-zinc-600 dark:bg-zinc-700 dark:text-gray-200"
                />
            </div>

            {{-- Genre --}}
            <div>
                <label class="mb-2 block text-lg font-semibold text-gray-800 dark:text-gray-200">Story Type (Optional)</label>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @foreach ([
                        ['value' => '', 'label' => 'Any', 'sub' => 'Let AI decide'],
                        ['value' => 'fantasy', 'label' => 'Fantasy', 'sub' => 'Magic & adventures'],
                        ['value' => 'romance', 'label' => 'Romance', 'sub' => 'Love stories'],
                        ['value' => 'mystery', 'label' => 'Mystery', 'sub' => 'Whodunit'],
                        ['value' => 'historical fiction', 'label' => 'Historical', 'sub' => 'Past times'],
                        ['value' => 'science fiction', 'label' => 'Sci-Fi', 'sub' => 'Future tech'],
                        ['value' => 'horror', 'label' => 'Horror', 'sub' => 'Spooky tales'],
                        ['value' => 'non-fiction', 'label' => 'True Story', 'sub' => 'Real events'],
                    ] as $g)
                        <button
                            type="button"
                            wire:click="$set('genre', '{{ $g['value'] }}')"
                            class="flex flex-col items-center justify-center rounded-xl border-2 px-3 py-4 text-center transition-colors min-h-[80px]
                                {{ $genre === $g['value']
                                    ? 'border-blue-400 bg-blue-50 dark:bg-blue-900/20'
                                    : 'border-gray-200 bg-gray-50 hover:border-gray-300 dark:border-zinc-600 dark:bg-zinc-700' }}"
                        >
                            <span class="text-base font-semibold {{ $genre === $g['value'] ? 'text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-200' }}">{{ $g['label'] }}</span>
                            <span class="text-xs {{ $genre === $g['value'] ? 'text-blue-400' : 'text-gray-400' }}">{{ $g['sub'] }}</span>
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Format --}}
            <div>
                <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">What do you want Claude to produce?</label>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    @foreach ([
                        ['value' => 'explore',     'label' => 'Explore idea',  'sub' => 'Q&A + framework', 'icon' => 'M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z'],
                        ['value' => 'short_story', 'label' => 'Short story',   'sub' => '~700 words',     'icon' => 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z'],
                        ['value' => 'chapter',     'label' => 'First chapter', 'sub' => '~2,500 words',   'icon' => 'M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25'],
                        ['value' => 'outline',     'label' => 'Full outline',  'sub' => '10–15 chapters', 'icon' => 'M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM3.75 12h.007v.008H3.75V12Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm-.375 5.25h.007v.008H3.75v-.008Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z'],
                    ] as $opt)
                        <button
                            type="button"
                            wire:click="$set('format', '{{ $opt['value'] }}')"
                            class="flex flex-col items-start rounded-xl border-2 px-3 py-3 text-left transition-colors
                                {{ $format === $opt['value']
                                    ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20'
                                    : 'border-gray-200 bg-gray-50 hover:border-gray-300 dark:border-zinc-600 dark:bg-zinc-700 dark:hover:border-zinc-500' }}"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="mb-1.5 size-4 {{ $format === $opt['value'] ? 'text-blue-500' : 'text-gray-400' }}" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $opt['icon'] }}" />
                            </svg>
                            <span class="text-xs font-semibold {{ $format === $opt['value'] ? 'text-blue-600 dark:text-blue-400' : 'text-gray-700 dark:text-gray-200' }}">{{ $opt['label'] }}</span>
                            <span class="text-xs {{ $format === $opt['value'] ? 'text-blue-400' : 'text-gray-400' }}">{{ $opt['sub'] }}</span>
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Attach images --}}
            <div>
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                    Reference images <span class="text-gray-400 font-normal">(optional — the AI will use them for inspiration)</span>
                </label>
                <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-dashed border-gray-300 bg-gray-50 px-4 py-3 text-sm text-gray-500 transition-colors hover:border-blue-400 hover:text-blue-500 dark:border-zinc-600 dark:bg-zinc-700">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
                    </svg>
                    Add images
                    <input type="file" wire:model="pendingImages" class="hidden" accept="image/*" multiple />
                </label>
                @if(count($pendingImages))
                    <p class="mt-1.5 text-xs text-gray-500">{{ count($pendingImages) }} image(s) selected</p>
                @endif
            </div>

                    <span class="inline-block size-5 rounded-full bg-white transition-transform {{ $isPrivate ? 'translate-x-5' : 'translate-x-1' }}" style="transform: translateY(0.125rem);"></span>
                </button>
            </div>

            {{-- Generate button --}}
            <button
                wire:click="generate"
                wire:loading.attr="disabled"
                class="w-full rounded-lg bg-blue-500 py-3 text-sm font-semibold text-white transition-colors hover:bg-blue-600 disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="generate">Generate your story</span>
                <span wire:loading wire:target="generate">Starting…</span>
            </button>

            <p class="text-center text-xs text-gray-400">
                or
                <a href="{{ route('templates.index') }}" class="text-blue-500 hover:underline" wire:navigate>Use existing templates</a>
            </p>
        </div>

        <button wire:click="$set('step', 'idea')" class="mt-4 flex items-center gap-1 text-sm text-gray-400 hover:text-gray-600">
            <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
            </svg>
            Back
        </button>

    @elseif ($step === 'voice_draft')
        {{-- Voice Step 0: Write your own draft - Elderly-friendly version --}}
        <div class="mb-4 text-center px-2">
            {{-- Clear step indicator with numbers instead of dots --}}
            <div class="mb-3 flex items-center justify-center gap-3">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-amber-500 text-base font-bold text-white">1</span>
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-gray-200 text-base font-medium text-gray-500 dark:bg-zinc-600 dark:text-gray-400">2</span>
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-gray-200 text-base font-medium text-gray-500 dark:bg-zinc-600 dark:text-gray-400">3</span>
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-gray-200 text-base font-medium text-gray-500 dark:bg-zinc-600 dark:text-gray-400">4</span>
            </div>
            <p class="mb-2 text-base font-semibold uppercase tracking-wide text-amber-600">Step 1 of 4</p>
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Tell Your Story</h2>
        </div>

        <div
            class="rounded-2xl border-2 border-amber-200 bg-white shadow-sm dark:border-amber-700 dark:bg-zinc-800 p-5 space-y-4"
            x-data="{
                active: null,
                answer: '',
                adding: false,
                fixOpen: false,
                fixRequest: '',
                fixing: false,
                openQ(q) { this.active = q; this.answer = ''; },
                cancelQ() { this.active = null; this.answer = ''; },
                submitQ() {
                    if (!this.answer.trim() || this.adding) return;
                    this.adding = true;
                    $wire.addDetail(this.answer).then(() => { this.adding = false; this.active = null; this.answer = ''; });
                },
                submitFix() {
                    if (!this.fixRequest.trim() || this.fixing) return;
                    this.fixing = true; this.fixOpen = false;
                    $wire.fixDraft(this.fixRequest).then(() => { this.fixing = false; this.fixRequest = ''; });
                }
            }"
        >
            {{-- Read-only story preview (not editable, so the mic can't drop text mid-sentence) --}}
            <div>
                <p class="mb-1.5 text-sm font-semibold text-gray-500 dark:text-gray-400">Your story so far</p>
                <div class="max-h-44 overflow-y-auto whitespace-pre-wrap rounded-xl border border-gray-200 bg-gray-50 p-4 text-lg leading-relaxed text-gray-800 dark:border-zinc-600 dark:bg-zinc-900/40 dark:text-gray-100">{{ trim($voiceDraft) !== '' ? $voiceDraft : 'Tap a question below to begin adding to your story.' }}</div>
                <div class="mt-1.5 flex items-center justify-between">
                    <p class="text-base font-medium text-amber-600 dark:text-amber-400">{{ str_word_count($voiceDraft) }} words</p>
                    @if (str_word_count($voiceDraft) > 0)
                        <button type="button" x-show="active === null && !fixOpen && !fixing" @click="fixOpen = true"
                            class="flex items-center gap-1.5 rounded-lg border border-orange-300 bg-orange-50 px-3 py-1.5 text-sm font-semibold text-orange-700 hover:bg-orange-100 active:bg-orange-200">✏️ Fix something</button>
                    @endif
                </div>
            </div>

            {{-- Tap-to-answer question cards --}}
            <div x-show="active === null && !fixOpen && !fixing" class="space-y-2.5">
                <p class="text-base font-semibold text-gray-800 dark:text-gray-200">💡 Tap a question to add more:</p>
                @foreach ([
                    'Who else was there?',
                    'Where did it happen?',
                    'What did you see, hear, or smell?',
                    'How did it make you feel?',
                    'What happened next?',
                ] as $q)
                    <button type="button" @click="openQ(@js($q))"
                        class="flex w-full items-center justify-between gap-3 rounded-xl border-2 border-amber-200 bg-amber-50 px-4 py-3.5 text-left text-base font-semibold text-amber-800 transition-colors hover:border-amber-300 hover:bg-amber-100 active:bg-amber-200 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-200">
                        <span>{{ $q }}</span>
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-amber-200 text-lg font-bold text-amber-700 dark:bg-amber-700 dark:text-amber-100">+</span>
                    </button>
                @endforeach
            </div>

            {{-- Answer panel — a dedicated empty box for the chosen question --}}
            <div x-show="active !== null" class="space-y-3">
                <p class="text-lg font-bold text-gray-900 dark:text-white" x-text="active"></p>
                <textarea x-model="answer" rows="4"
                    placeholder="🎤 Tap here, then tap the microphone key to talk..."
                    class="w-full resize-none rounded-xl border-2 border-amber-300 bg-white p-4 text-lg text-gray-800 focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-200 dark:bg-zinc-900 dark:text-gray-100"></textarea>
                <div class="flex gap-3">
                    <button @click="cancelQ()" :disabled="adding"
                        class="flex-1 rounded-xl border-2 border-gray-300 bg-white px-4 py-3.5 text-base font-semibold text-gray-600 disabled:opacity-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-gray-300">← Cancel</button>
                    <button @click="submitQ()" :disabled="adding || !answer.trim()"
                        class="flex-1 rounded-xl bg-green-600 px-4 py-3.5 text-base font-bold text-white hover:bg-green-700 active:bg-green-800 disabled:opacity-50">
                        <span x-show="!adding">Add to my story ✓</span>
                        <span x-show="adding">Adding…</span>
                    </button>
                </div>
            </div>

            {{-- Fix something panel (AI-applied edit) --}}
            <div x-show="fixOpen" class="rounded-xl border border-orange-200 bg-orange-50 p-4 space-y-3">
                <p class="text-sm font-semibold text-orange-800">What would you like to change?</p>
                <p class="text-xs text-orange-600">Speak or type it — e.g. "Change Herman to Harold" or "Make the ending happier"</p>
                <textarea x-model="fixRequest" rows="2"
                    placeholder="🎤 Tap and say what to change..."
                    class="w-full rounded-lg border border-orange-200 bg-white px-3 py-2 text-base text-gray-800 focus:border-orange-400 focus:outline-none focus:ring-2 focus:ring-orange-200"></textarea>
                <div class="flex gap-3">
                    <button @click="fixOpen = false; fixRequest = ''"
                        class="flex-1 rounded-lg border-2 border-gray-300 bg-white px-3 py-2.5 text-sm font-semibold text-gray-600">← Cancel</button>
                    <button @click="submitFix()" :disabled="!fixRequest.trim()"
                        class="flex-1 rounded-lg bg-orange-500 px-3 py-2.5 text-sm font-bold text-white hover:bg-orange-600 disabled:opacity-50">Send →</button>
                </div>
            </div>

            <div x-show="fixing" class="flex items-center gap-3 rounded-xl border border-orange-100 bg-orange-50 px-4 py-3">
                <div class="size-5 rounded-full border-2 border-orange-200 border-t-orange-500 animate-spin"></div>
                <p class="text-sm font-medium text-orange-700">Making your change…</p>
            </div>
        </div>

        {{-- Inline Back + Next row, spaced apart --}}
        <div class="mt-4 pb-8">
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px;">
           <div class="flex justify-between gap-4">
                 <button wire:click="$set('step', 'idea')"
                    class="flex items-center justify-center gap-2 rounded-xl border-2 border-gray-300 bg-white px-5 py-4 text-base font-semibold text-gray-700 dark:border-zinc-600 dark:bg-zinc-800 dark:text-gray-300">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                    Back
                </button>
                <button
                    wire:click="toVoiceCharacters"
                    wire:loading.attr="disabled"
                    class="flex items-center justify-center gap-2 rounded-xl bg-amber-500 px-6 py-4 text-lg font-bold text-white shadow-md transition-colors hover:bg-amber-600 active:bg-amber-700 disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="toVoiceCharacters">Next →</span>
                    <span wire:loading wire:target="toVoiceCharacters">Saving...</span>
                </button>
           </div>
            </div>
            <div class="mt-3 flex items-center justify-center gap-6">
                <a href="{{ route('books.index') }}" wire:navigate class="text-sm font-medium text-blue-600 py-1 px-3">My Stories</a>
                <button
                    x-data
                    @click="if (confirm('Cancel? Your work will be lost.')) { $wire.cancelStory(); }"
                    class="text-sm font-medium text-red-500 py-1 px-3"
                >Cancel Story</button>
            </div>
        </div>

    @elseif ($step === 'voice_characters')
        {{-- Voice Step 2: Characters --}}
        <div class="mb-6 text-center px-2">
            {{-- Clear step indicator with numbers --}}
            <div class="mb-3 flex items-center justify-center gap-3">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-green-500 text-base font-bold text-white">✓</span>
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-amber-500 text-base font-bold text-white">2</span>
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-gray-200 text-base font-medium text-gray-500 dark:bg-zinc-600 dark:text-gray-400">3</span>
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-gray-200 text-base font-medium text-gray-500 dark:bg-zinc-600 dark:text-gray-400">4</span>
            </div>
            <p class="mb-2 text-base font-semibold uppercase tracking-wide text-amber-600">Step 2 of 4</p>
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Who's in Your Story?</h2>
            <p class="mt-2 text-lg text-gray-600 dark:text-gray-300 max-w-sm mx-auto leading-relaxed">
                Tell us about the people in your story (optional)
            </p>
        </div>

        <div class="rounded-2xl border-2 border-amber-200 bg-white shadow-sm dark:border-amber-700 dark:bg-zinc-800 p-5 space-y-4">
            <div>
                <label class="mb-2 block text-lg font-semibold text-gray-800 dark:text-gray-200">
                    👥 Characters (Optional)
                </label>
                <p class="mb-2 text-base text-gray-600 dark:text-gray-400">
                    Who are the people in your story? What are they like?
                </p>
                <div class="relative" x-data="{ hasText: false }">
                    <textarea
                        wire:model="voiceCharacters"
                        rows="5"
                        placeholder="🎤 Tap to speak, or skip this step..."
                        class="mic-textarea w-full resize-none rounded-xl p-4 text-lg text-gray-800 dark:text-gray-100"
                        @input="hasText = $el.value.length > 0"
                        @focus="hasText = $el.value.length > 0"
                    ></textarea>
                    <div
                        x-show="!hasText"
                        class="mic-reminder pointer-events-none absolute bottom-3 left-0 right-0 flex justify-center px-4"
                    >
                        <span class="rounded-full bg-blue-600 px-4 py-2 text-sm font-bold text-white shadow-md w-full text-center">
                            🎤 Now tap the microphone key on your keyboard
                        </span>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between pt-2">
                <div class="flex justify-between gap-4">
                <button wire:click="$set('step', 'voice_draft')" class="flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-3 text-base font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-gray-300">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                    Go Back
                </button>
                <button
                    wire:click="toVoiceEmotion"
                    class="flex items-center justify-center gap-2 rounded-xl bg-amber-500 px-6 py-4 text-lg font-semibold text-white shadow-md transition-colors hover:bg-amber-600 active:bg-amber-700"
                >
                    {{-- Continue to Step 3 --}}
                    Next
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                    </svg>
                </button>
                </div>
            </div>
            <div class="text-center pt-1">
                <button wire:click="toVoiceEmotion" class="rounded-lg border border-gray-300 bg-gray-50 px-5 py-2 text-base font-medium text-gray-500 hover:bg-gray-100 active:bg-gray-200">Skip this step →</button>
            </div>
        </div>

    @elseif ($step === 'voice_emotion')
        {{-- Voice Step 3: The heart of it --}}
        <div class="mb-6 text-center px-2">
            {{-- Step indicator: Steps 1-2 complete, Step 3 active, Step 4 pending --}}
            <div class="mb-3 flex items-center justify-center gap-3">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-green-500 text-base font-bold text-white">✓</span>
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-green-500 text-base font-bold text-white">✓</span>
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-amber-500 text-base font-bold text-white">3</span>
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-gray-200 text-base font-medium text-gray-500 dark:bg-zinc-600 dark:text-gray-400">4</span>
            </div>
            <p class="mb-2 text-base font-semibold uppercase tracking-wide text-amber-600">Step 3 of 4</p>
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">What's the Heart of It?</h2>
            <p class="mt-2 text-lg text-gray-600 dark:text-gray-300 max-w-sm mx-auto leading-relaxed">
                What's the main feeling or moment in your story? (optional)
            </p>
        </div>

        <div class="rounded-2xl border-2 border-amber-200 bg-white shadow-sm dark:border-amber-700 dark:bg-zinc-800 p-5 space-y-4">
            <div>
                <label class="mb-2 block text-lg font-semibold text-gray-800 dark:text-gray-200">
                    💝 The Emotional Moment (Optional)
                </label>
                <p class="mb-2 text-base text-gray-600 dark:text-gray-400">
                    What do you want readers to feel? A touching scene? A surprise ending?
                </p>
                <div class="relative" x-data="{ hasText: false }">
                    <textarea
                        wire:model="voiceEmotionCore"
                        rows="5"
                        placeholder="🎤 Tap to speak, or skip this step..."
                        class="mic-textarea w-full resize-none rounded-xl p-4 text-lg text-gray-800 dark:text-gray-100"
                        @input="hasText = $el.value.length > 0"
                        @focus="hasText = $el.value.length > 0"
                    ></textarea>
                    <div
                        x-show="!hasText"
                        class="mic-reminder pointer-events-none absolute bottom-3 left-0 right-0 flex justify-center px-4"
                    >
                        <span class="rounded-full bg-blue-600 px-4 py-2 text-sm font-bold text-white shadow-md w-full text-center">
                            🎤 Now tap the microphone key on your keyboard
                        </span>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between pt-2">
                <div class="flex justify-between gap-4">
                <button wire:click="$set('step', 'voice_characters')" class="flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-3 text-base font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-gray-300">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                    Go Back
                </button>
                <button
                    wire:click="toVoiceTone"
                    class="flex items-center justify-center gap-2 rounded-xl bg-amber-500 px-6 py-4 text-lg font-semibold text-white shadow-md transition-colors hover:bg-amber-600 active:bg-amber-700"
                >
                    {{-- Continue to Step 4 --}}
                    Finish
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                    </svg>
                </button>
                </div>
            </div>
            <div class="text-center pt-1">
                <button wire:click="toVoiceTone" class="rounded-lg border border-gray-300 bg-gray-50 px-5 py-2 text-base font-medium text-gray-500 hover:bg-gray-100 active:bg-gray-200">Skip this step →</button>
            </div>
        </div>

    @elseif ($step === 'voice_tone')
        {{-- Voice Step 3: Tone & style + final generate --}}
        <div class="mb-8 text-center">
            <div class="mb-3 flex items-center justify-center gap-3">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-green-500 text-base font-bold text-white">✓</span>
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-green-500 text-base font-bold text-white">✓</span>
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-green-500 text-base font-bold text-white">✓</span>
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-amber-500 text-base font-bold text-white">4</span>
            </div>
            <p class="mb-1 text-base font-semibold uppercase tracking-wide text-amber-600">Last Step — Step 4 of 4</p>
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">How does it sound?</h2>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400 max-w-sm mx-auto">
                A few quick choices to help the AI match your style — then you're ready to go.
            </p>
        </div>

        <div class="rounded-2xl border border-amber-200 bg-white shadow-sm dark:border-amber-800/40 dark:bg-zinc-800 p-6 space-y-5">

            {{-- POV --}}
            <div>
                <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Point of view</label>
                <div class="grid grid-cols-3 gap-2">
                    @foreach ([
                        ['value' => 'first',  'label' => 'I tell the story',  'sub' => '"I walked in…"'],
                        ['value' => 'third',  'label' => 'About someone else',  'sub' => '"She walked in…"'],
                        ['value' => 'second', 'label' => 'You are in the story', 'sub' => '"You walk in…"'],
                    ] as $pov)
                        <button
                            type="button"
                            wire:click="$set('voicePov', '{{ $pov['value'] }}')"
                            class="flex flex-col items-start rounded-xl border-2 px-3 py-3 text-left transition-colors
                                {{ $voicePov === $pov['value']
                                    ? 'border-amber-400 bg-amber-50 dark:bg-amber-900/20'
                                    : 'border-gray-200 bg-gray-50 hover:border-gray-300 dark:border-zinc-600 dark:bg-zinc-700' }}"
                        >
                            <span class="text-xs font-semibold {{ $voicePov === $pov['value'] ? 'text-amber-600 dark:text-amber-400' : 'text-gray-700 dark:text-gray-200' }}">{{ $pov['label'] }}</span>
                            <span class="text-xs {{ $voicePov === $pov['value'] ? 'text-amber-400' : 'text-gray-400' }}">{{ $pov['sub'] }}</span>
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Tone description --}}
            <div>
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                    Tone & style <span class="text-gray-400 font-normal">(optional — describe it in your own words)</span>
                </label>
                <textarea
                    wire:model="voiceTone"
                    rows="3"
                    placeholder="e.g. Simple and warm. Short sentences."
                    class="w-full resize-none rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700 placeholder-gray-400 focus:border-amber-400 focus:outline-none focus:ring-1 focus:ring-amber-400 dark:border-zinc-600 dark:bg-zinc-700 dark:text-gray-200"
                ></textarea>
            </div>

            {{-- Genre --}}
            <div>
                <label class="mb-2 block text-lg font-semibold text-gray-800 dark:text-gray-200">
                    Story Type (Optional)
                </label>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @foreach ([
                        ['value' => '', 'label' => 'Any', 'sub' => 'Let AI decide'],
                        ['value' => 'fantasy', 'label' => 'Fantasy', 'sub' => 'Magic & adventures'],
                        ['value' => 'romance', 'label' => 'Romance', 'sub' => 'Love stories'],
                        ['value' => 'mystery', 'label' => 'Mystery', 'sub' => 'Whodunit'],
                        ['value' => 'historical fiction', 'label' => 'Historical', 'sub' => 'Past times'],
                        ['value' => 'science fiction', 'label' => 'Sci-Fi', 'sub' => 'Future tech'],
                        ['value' => 'horror', 'label' => 'Horror', 'sub' => 'Spooky tales'],
                        ['value' => 'non-fiction', 'label' => 'True Story', 'sub' => 'Real events'],
                    ] as $g)
                        <button
                            type="button"
                            wire:click="$set('genre', '{{ $g['value'] }}')"
                            class="flex flex-col items-center justify-center rounded-xl border-2 px-3 py-4 text-center transition-colors min-h-[80px]
                                {{ $genre === $g['value']
                                    ? 'border-amber-400 bg-amber-50 dark:bg-amber-900/20'
                                    : 'border-gray-200 bg-gray-50 hover:border-gray-300 dark:border-zinc-600 dark:bg-zinc-700' }}"
                        >
                            <span class="text-base font-semibold {{ $genre === $g['value'] ? 'text-amber-600 dark:text-amber-400' : 'text-gray-700 dark:text-gray-200' }}">{{ $g['label'] }}</span>
                            <span class="text-xs {{ $genre === $g['value'] ? 'text-amber-400' : 'text-gray-400' }}">{{ $g['sub'] }}</span>
                        </button>
                    @endforeach
                </div>
            </div>

                    <span class="inline-block size-5 rounded-full bg-white transition-transform {{ $isPrivate ? 'translate-x-5' : 'translate-x-1' }}" style="transform: translateY(0.125rem);"></span>
                </button>
            </div>

            {{-- Next: Title step --}}
            <button
                wire:click="toVoiceTitle"
                wire:loading.attr="disabled"
                class="w-full rounded-lg bg-amber-500 py-3 text-sm font-semibold text-white transition-colors hover:bg-amber-600 disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="toVoiceTitle">Next: Give Your Story a Title →</span>
                <span wire:loading wire:target="toVoiceTitle">Saving…</span>
            </button>

            <p class="text-center text-xs text-gray-400">One more step — then the AI will write your story!</p>
            <div class="text-center">
                <button wire:click="toVoiceTitle" class="text-base text-gray-400 underline hover:text-gray-600 cursor-pointer">Skip style choices &amp; continue →</button>
            </div>
        </div>

        <button wire:click="toVoiceEmotion" class="mt-4 flex items-center gap-1 text-sm text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
            <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
            </svg>
            Back
        </button>

    @elseif ($step === 'voice_title')
        {{-- Title step --}}
        <div class="mb-4 text-center px-2">
            <div class="mb-3 flex items-center justify-center gap-3">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-green-500 text-base font-bold text-white">✓</span>
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-green-500 text-base font-bold text-white">✓</span>
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-green-500 text-base font-bold text-white">✓</span>
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-green-500 text-base font-bold text-white">✓</span>
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-amber-500 text-base font-bold text-white">5</span>
            </div>
            <p class="mb-2 text-base font-semibold uppercase tracking-wide text-amber-600">Almost Done!</p>
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">What's the title?</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">You can always change this later.</p>
        </div>

        <div class="rounded-2xl border-2 border-amber-200 bg-white shadow-sm dark:border-amber-700 dark:bg-zinc-800 p-5 space-y-4">
            <label class="block text-lg font-medium text-gray-800 dark:text-gray-200">Story title</label>
            <div class="relative" x-data="{ hasText: @js(strlen($title) > 0) }">
                <textarea
                    wire:model="title"
                    rows="3"
                    placeholder="Tap here and speak your title..."
                    class="mic-textarea w-full resize-none rounded-xl p-4 text-lg text-gray-800 dark:text-gray-100"
                    @input="hasText = $el.value.length > 0"
                    @focus="hasText = $el.value.length > 0"
                ></textarea>
                <div x-show="!hasText" class="mic-reminder pointer-events-none absolute bottom-3 left-0 right-0 flex justify-center px-4">
                    <span class="rounded-full bg-blue-600 px-4 py-2 text-sm font-bold text-white shadow-md w-full text-center">
                        🎤 Now tap the microphone key on your keyboard
                    </span>
                </div>
            </div>
            <p class="text-sm text-gray-400 text-center">Or skip — we'll call it "Untitled Story" for now.</p>
        </div>

        {{-- Full-screen loading overlay while AI reads the story --}}
        <div wire:loading wire:target="toVoiceReview"
             class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-5 bg-white dark:bg-zinc-900 px-6">
            <div class="relative flex items-center justify-center">
                <div class="absolute size-28 rounded-full bg-green-100 animate-ping opacity-50"></div>
                <div class="relative flex size-24 items-center justify-center rounded-full bg-green-100">
                    <span class="text-5xl">📖</span>
                </div>
            </div>
            <div class="size-12 rounded-full border-4 border-green-100 border-t-green-500 animate-spin"></div>
            <p class="text-2xl font-bold text-gray-900 dark:text-white text-center">Your coach is reading…</p>
            <p class="text-base text-green-700 dark:text-green-400 text-center font-medium">Reading every word of your story carefully</p>
            <p class="text-sm text-gray-400 text-center">Please wait — this usually takes 30–90 seconds</p>
            <div class="flex gap-2 mt-2">
                <span class="size-3 rounded-full bg-green-400 animate-bounce" style="animation-delay: 0ms"></span>
                <span class="size-3 rounded-full bg-green-400 animate-bounce" style="animation-delay: 200ms"></span>
                <span class="size-3 rounded-full bg-green-400 animate-bounce" style="animation-delay: 400ms"></span>
            </div>
        </div>

        <div class="mt-4 pb-8">
            <div class="flex items-center gap-3">
                <button wire:click="toVoiceTone"
                    class="shrink-0 flex items-center justify-center gap-2 rounded-xl border-2 border-gray-300 bg-white px-5 py-4 text-base font-semibold text-gray-700 dark:border-zinc-600 dark:bg-zinc-800 dark:text-gray-300">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                    Back
                </button>
                <button
                    wire:click="toVoiceReview"
                    wire:loading.attr="disabled"
                    class="flex flex-1 items-center justify-center gap-2 rounded-xl bg-green-600 px-6 py-4 text-lg font-bold text-white shadow-md transition-colors hover:bg-green-700 active:bg-green-800 disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="toVoiceReview">Review My Story →</span>
                    <span wire:loading wire:target="toVoiceReview">Reading your story…</span>
                </button>
            </div>
            <div class="mt-3 flex items-center justify-center gap-6">
                <a href="{{ route('books.index') }}" wire:navigate class="text-sm font-medium text-blue-600 py-1 px-3">My Stories</a>
                <button
                    x-data
                    @click="if (confirm('Cancel? Your work will be lost.')) { $wire.cancelStory(); }"
                    class="text-sm font-medium text-red-500 py-1 px-3"
                >Cancel Story</button>
            </div>
        </div>

    @elseif ($step === 'voice_expand')
        {{-- Guided "Tell me more" — helps thin drafts grow past 50 words --}}
        <div class="mb-4 text-center px-2">
            <div class="mb-3 flex size-14 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900/30 mx-auto">
                <span class="text-3xl">💬</span>
            </div>
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Tell me a little more</h2>
            <p class="mt-1 text-base text-gray-500 dark:text-gray-400">A few more sentences will make your story really come alive.</p>
        </div>

        <div
            x-data="{
                active: null,
                answer: '',
                adding: false,
                get words() {
                    const v = $wire.voiceDraft || '';
                    return v.trim() ? v.trim().split(/\s+/).length : 0;
                },
                openQ(q) { this.active = q; this.answer = ''; },
                cancelQ() { this.active = null; this.answer = ''; },
                submitQ() {
                    if (!this.answer.trim() || this.adding) return;
                    this.adding = true;
                    $wire.addDetail(this.answer).then(() => { this.adding = false; this.active = null; this.answer = ''; });
                }
            }"
            class="rounded-2xl border-2 border-amber-200 bg-white shadow-sm dark:border-amber-700 dark:bg-zinc-800 p-5 space-y-4"
        >
            {{-- Read-only story preview --}}
            <div>
                <p class="mb-1.5 text-sm font-semibold text-gray-500 dark:text-gray-400">Your story so far</p>
                <div class="max-h-44 overflow-y-auto whitespace-pre-wrap rounded-xl border border-gray-200 bg-gray-50 p-4 text-lg leading-relaxed text-gray-800 dark:border-zinc-600 dark:bg-zinc-900/40 dark:text-gray-100">{{ trim($voiceDraft) !== '' ? $voiceDraft : 'Tap a question below to begin adding to your story.' }}</div>
            </div>

            {{-- Live progress toward 50 words --}}
            <div>
                <div class="mb-1 flex items-center justify-between text-sm font-medium">
                    <span class="text-gray-600 dark:text-gray-400"><span x-text="words"></span> words</span>
                    <span x-show="words >= 50" class="text-green-600">✓ Great length!</span>
                    <span x-show="words < 50" class="text-amber-600">Aim for 50+</span>
                </div>
                <div class="h-2.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-zinc-600">
                    <div class="h-full rounded-full transition-all duration-300"
                         :class="words >= 50 ? 'bg-green-500' : 'bg-amber-500'"
                         :style="`width: ${Math.min(100, (words / 50) * 100)}%`"></div>
                </div>
            </div>

            {{-- Tap-to-answer question cards --}}
            <div x-show="active === null" class="space-y-2.5">
                <p class="text-base font-semibold text-gray-800 dark:text-gray-200">💡 Tap a question to add more:</p>
                @foreach ([
                    'Who else was there?',
                    'Where did this happen?',
                    'What did you see, hear, or smell?',
                    'How did it make you feel?',
                    'What happened next?',
                ] as $q)
                    <button type="button" @click="openQ(@js($q))"
                        class="flex w-full items-center justify-between gap-3 rounded-xl border-2 border-amber-200 bg-amber-50 px-4 py-3.5 text-left text-base font-semibold text-amber-800 transition-colors hover:border-amber-300 hover:bg-amber-100 active:bg-amber-200 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-200">
                        <span>{{ $q }}</span>
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-amber-200 text-lg font-bold text-amber-700 dark:bg-amber-700 dark:text-amber-100">+</span>
                    </button>
                @endforeach
            </div>

            {{-- Answer panel --}}
            <div x-show="active !== null" class="space-y-3">
                <p class="text-lg font-bold text-gray-900 dark:text-white" x-text="active"></p>
                <textarea x-model="answer" rows="4"
                    placeholder="🎤 Tap here, then tap the microphone key to talk..."
                    class="w-full resize-none rounded-xl border-2 border-amber-300 bg-white p-4 text-lg text-gray-800 focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-200 dark:bg-zinc-900 dark:text-gray-100"></textarea>
                <div class="flex gap-3">
                    <button @click="cancelQ()" :disabled="adding"
                        class="flex-1 rounded-xl border-2 border-gray-300 bg-white px-4 py-3.5 text-base font-semibold text-gray-600 disabled:opacity-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-gray-300">← Cancel</button>
                    <button @click="submitQ()" :disabled="adding || !answer.trim()"
                        class="flex-1 rounded-xl bg-green-600 px-4 py-3.5 text-base font-bold text-white hover:bg-green-700 active:bg-green-800 disabled:opacity-50">
                        <span x-show="!adding">Add to my story ✓</span>
                        <span x-show="adding">Adding…</span>
                    </button>
                </div>
            </div>
        </div>

        {{-- Back + Continue --}}
        <div class="mt-4 pb-8">
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <button wire:click="$set('step', 'voice_review')"
                    class="flex items-center justify-center gap-2 rounded-xl border-2 border-gray-300 bg-white px-5 py-4 text-base font-semibold text-gray-700 dark:border-zinc-600 dark:bg-zinc-800 dark:text-gray-300">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                    Back
                </button>
                <button
                    wire:click="toVoiceReview"
                    wire:loading.attr="disabled"
                    class="flex items-center justify-center gap-2 rounded-xl bg-amber-500 px-6 py-4 text-base font-bold text-white shadow-md transition-colors hover:bg-amber-600 active:bg-amber-700 disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="toVoiceReview">Done — Re-check →</span>
                    <span wire:loading wire:target="toVoiceReview">Checking…</span>
                </button>
            </div>
            <div class="mt-3 flex items-center justify-center gap-6">
                <a href="{{ route('books.index') }}" wire:navigate class="text-sm font-medium text-blue-600 py-1 px-3">My Stories</a>
            </div>
        </div>

    @elseif ($step === 'voice_review')
        {{-- AI Review step --}}
        <div class="mb-4 text-center px-2">
            <div class="mb-3 flex items-center justify-center gap-3">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-green-500 text-base font-bold text-white">✓</span>
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-green-500 text-base font-bold text-white">✓</span>
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-green-500 text-base font-bold text-white">✓</span>
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-green-500 text-base font-bold text-white">✓</span>
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-green-500 text-base font-bold text-white">✓</span>
            </div>
            <p class="mb-2 text-base font-semibold uppercase tracking-wide text-green-600">Almost There!</p>
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Here's what I heard 👂</h2>
        </div>

        <div class="rounded-2xl border-2 border-green-200 bg-white shadow-sm dark:border-green-800/40 dark:bg-zinc-800 p-6 space-y-5">

            @if ($loadingReview || empty($aiReview))
                <div
                    class="flex flex-col items-center gap-5 py-12 px-4"
                    x-data="{
                        msgs: [
                            'Reading every word of your story…',
                            'Looking for what makes it special…',
                            'Your coach is almost ready…',
                            'Just a few more seconds…'
                        ],
                        idx: 0,
                        init() { setInterval(() => { this.idx = (this.idx + 1) % this.msgs.length }, 2500) }
                    }"
                >
                    {{-- Big pulsing book emoji --}}
                    <div class="relative flex items-center justify-center">
                        <div class="absolute size-24 rounded-full bg-green-100 animate-ping opacity-40"></div>
                        <div class="relative flex size-20 items-center justify-center rounded-full bg-green-100">
                            <span class="text-4xl">📖</span>
                        </div>
                    </div>
                    {{-- Rotating spinner ring --}}
                    <div class="size-10 rounded-full border-4 border-green-100 border-t-green-500 animate-spin"></div>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white text-center">Your coach is reading…</p>
                    {{-- Cycling reassurance message --}}
                    <p class="text-base text-green-700 dark:text-green-400 text-center font-medium" x-text="msgs[idx]"></p>
                    <p class="text-sm text-gray-400 text-center">Please wait — this usually takes 30–90 seconds</p>
                    {{-- Bouncing dots --}}
                    <div class="flex gap-2">
                        <span class="size-3 rounded-full bg-green-400 animate-bounce" style="animation-delay: 0ms"></span>
                        <span class="size-3 rounded-full bg-green-400 animate-bounce" style="animation-delay: 200ms"></span>
                        <span class="size-3 rounded-full bg-green-400 animate-bounce" style="animation-delay: 400ms"></span>
                    </div>
                </div>
            @else
                {{-- Read to Me - at top so user sees it immediately --}}
                <div x-data="{
                    speaking: false,
                    start() {
                        const text = {{ json_encode($aiReview) }};
                        window.speechSynthesis.cancel();
                        const u = new SpeechSynthesisUtterance(text);
                        u.rate = 0.9;
                        u.onend = () => { this.speaking = false; };
                        window.speechSynthesis.speak(u);
                        this.speaking = true;
                    },
                    stop() { window.speechSynthesis.cancel(); this.speaking = false; }
                }">
                    <button x-show="!speaking" @click="start()"
                        class="flex w-full items-center justify-center gap-2 rounded-xl bg-purple-100 border border-purple-300 px-4 py-3 text-base font-semibold text-purple-700">
                        🔊 Read This to Me
                    </button>
                    <p x-show="!speaking" class="mt-1 text-center text-sm text-gray-400">📢 Make sure your phone volume is turned up!</p>
                    <button x-show="speaking" @click="stop()"
                        class="flex w-full items-center justify-center gap-2 rounded-xl bg-purple-600 px-4 py-3 text-base font-semibold text-white">
                        ⏹ Stop Reading
                    </button>
                </div>

                {{-- AI summary --}}
                <div class="rounded-xl bg-green-50 dark:bg-green-900/20 px-4 py-4 text-base leading-relaxed text-gray-800 dark:text-gray-200">
                    {!! nl2br(e($aiReview)) !!}
                </div>

                {{-- Answer the coach / fix something — applies the change then re-runs the review --}}
                <div x-data="{ open: false, request: '', fixing: false }" class="space-y-3">
                    <button
                        x-show="!open && !fixing"
                        @click="open = true"
                        class="flex w-full items-center justify-center gap-2 rounded-xl border-2 border-orange-300 bg-orange-50 px-4 py-3 text-base font-semibold text-orange-700 hover:bg-orange-100 active:bg-orange-200"
                    >💬 Answer the coach or fix something</button>

                    <template x-if="open">
                        <div class="rounded-xl border border-orange-200 bg-orange-50 p-4 space-y-3">
                            <p class="text-sm font-semibold text-orange-800">What would you like to change or clarify?</p>
                            <p class="text-xs text-orange-600">Speak or type your answer — e.g. "I meant I felt free" or "Change Herman to Harold"</p>
                            <textarea
                                x-model="request"
                                rows="2"
                                autocapitalize="none" autocorrect="off" spellcheck="false"
                                placeholder="🎤 Tap and say your answer..."
                                class="w-full rounded-lg border border-orange-200 bg-white px-3 py-2 text-base text-gray-800 focus:border-orange-400 focus:outline-none focus:ring-2 focus:ring-orange-200"
                            ></textarea>
                            <div class="flex gap-3">
                                <button
                                    @click="open = false; request = ''"
                                    class="flex-1 rounded-lg border-2 border-gray-300 bg-white px-3 py-2.5 text-sm font-semibold text-gray-600"
                                >← Cancel</button>
                                <button
                                    @click="
                                        if (request.trim()) {
                                            fixing = true;
                                            open = false;
                                            $wire.fixDraft(request).then(() => { $wire.toVoiceReview(); fixing = false; request = ''; });
                                        }
                                    "
                                    class="flex-1 rounded-lg bg-orange-500 px-3 py-2.5 text-sm font-bold text-white hover:bg-orange-600"
                                >Send →</button>
                            </div>
                        </div>
                    </template>

                    <template x-if="fixing">
                        <div class="flex items-center gap-3 rounded-xl border border-orange-100 bg-orange-50 px-4 py-3">
                            <div class="size-5 rounded-full border-2 border-orange-200 border-t-orange-500 animate-spin"></div>
                            <p class="text-sm font-medium text-orange-700">Making your change and re-checking…</p>
                        </div>
                    </template>
                </div>

                @if (str_word_count($voiceDraft) < 50)
                    {{-- Thin content warning --}}
                    <div class="rounded-xl bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
                        📝 Your story has {{ str_word_count($voiceDraft) }} words. Adding a little more will make it much richer! Tap below to go back and keep writing.
                    </div>

                    {{-- Read Aloud button for the draft --}}
                    <div x-data="{
                        speaking: false,
                        start() {
                            const text = {{ json_encode($voiceDraft) }};
                            window.speechSynthesis.cancel();
                            const u = new SpeechSynthesisUtterance(text);
                            u.rate = 0.9;
                            u.onend = () => { this.speaking = false; };
                            window.speechSynthesis.speak(u);
                            this.speaking = true;
                        },
                        stop() { window.speechSynthesis.cancel(); this.speaking = false; }
                    }">
                        <button x-show="!speaking" @click="start()"
                            class="flex w-full items-center justify-center gap-2 rounded-xl bg-purple-100 border border-purple-300 px-4 py-3 text-base font-semibold text-purple-700">
                            🔊 Read My Story Aloud
                        </button>
                        <p x-show="!speaking" class="mt-1 text-center text-xs text-gray-400">Make sure your phone volume is turned up!</p>
                        <button x-show="speaking" @click="stop()"
                            class="flex w-full items-center justify-center gap-2 rounded-xl bg-purple-600 px-4 py-3 text-base font-semibold text-white">
                            ⏹ Stop Reading
                        </button>
                    </div>

                    <button wire:click="$set('step', 'voice_expand')"
                        class="w-full rounded-xl bg-amber-500 px-6 py-4 text-lg font-bold text-white">
                        ← Add More to My Story
                    </button>
                @else
                    {{-- Ending style choice cards --}}
                    <div>
                        <p class="mb-1 text-base font-semibold text-gray-800 dark:text-gray-200">How should your story end?</p>
                        <p class="mb-3 text-sm text-gray-500 dark:text-gray-400">Tap one (optional) — the AI will craft this kind of ending.</p>
                        <div class="grid grid-cols-2 gap-2.5">
                            @foreach([
                                ['value' => 'full_circle',       'emoji' => '🎀', 'label' => 'Full-circle',       'sub' => 'Ties back to the start'],
                                ['value' => 'funny',             'emoji' => '😄', 'label' => 'Funny',             'sub' => 'Ends with a smile'],
                                ['value' => 'thought_provoking', 'emoji' => '💭', 'label' => 'Thought-provoking', 'sub' => 'Leaves you thinking'],
                                ['value' => 'moral',             'emoji' => '🌟', 'label' => 'A life lesson',      'sub' => 'A gentle moral'],
                                ['value' => 'simple',            'emoji' => '✨', 'label' => 'Keep it simple',     'sub' => 'A quiet, natural close'],
                            ] as $end)
                                <button
                                    type="button"
                                    wire:click="$set('endingStyle', @js($endingStyle === $end['value'] ? '' : $end['value']))"
                                    class="flex flex-col items-start rounded-xl border-2 px-3 py-3 text-left transition-colors
                                        {{ $endingStyle === $end['value']
                                            ? 'border-green-500 bg-green-50 dark:bg-green-900/20'
                                            : 'border-gray-200 bg-gray-50 hover:border-gray-300 dark:border-zinc-600 dark:bg-zinc-700' }}"
                                >
                                    <span class="text-xl">{{ $end['emoji'] }}</span>
                                    <span class="text-sm font-bold {{ $endingStyle === $end['value'] ? 'text-green-700 dark:text-green-400' : 'text-gray-800 dark:text-gray-200' }}">{{ $end['label'] }}</span>
                                    <span class="text-xs {{ $endingStyle === $end['value'] ? 'text-green-500' : 'text-gray-400' }}">{{ $end['sub'] }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>

                    {{-- Finish + Back options --}}
                    <button
                        wire:click="generate"
                        wire:loading.attr="disabled"
                        class="flex w-full items-center justify-center gap-3 rounded-xl bg-green-600 px-6 py-5 text-xl font-bold text-white shadow-md transition-colors hover:bg-green-700 active:bg-green-800 disabled:opacity-60"
                    >
                        <span wire:loading.remove wire:target="generate">✨ Finish My Story!</span>
                        <span wire:loading wire:target="generate">Starting your story…</span>
                    </button>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <button wire:click="toVoiceTitle"
                            class="rounded-xl border-2 border-gray-300 bg-white px-4 py-3 text-base font-semibold text-gray-700">
                            ← Edit Title
                        </button>
                        <button wire:click="$set('step', 'voice_draft')"
                            class="rounded-xl border-2 border-gray-300 bg-white px-4 py-3 text-base font-semibold text-gray-700">
                            ← Edit Story
                        </button>
                    </div>
                @endif
            @endif
        </div>

        <div class="mt-3 flex items-center justify-center gap-6 pb-6">
            <a href="{{ route('books.index') }}" wire:navigate class="text-sm font-medium text-blue-600 py-1 px-3">My Stories</a>
            <button
                x-data
                @click="if (confirm('Cancel? Your work will be lost.')) { $wire.cancelStory(); }"
                class="text-sm font-medium text-red-500 py-1 px-3"
            >Cancel Story</button>
        </div>

    @elseif ($step === 'manual_entry')
        {{-- Manual story paste entry --}}
        <div class="mb-5 text-center px-4">
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Paste Your Story</h2>
            <p class="mt-1 text-base text-gray-500 dark:text-gray-400">Write or paste what you already have. The AI will review and improve it.</p>
        </div>

        <div class="rounded-2xl border-2 border-green-300 bg-white p-5 shadow-sm dark:border-green-700 dark:bg-zinc-800 space-y-4">
            <div>
                <label class="mb-2 block text-lg font-medium text-gray-800 dark:text-gray-200">Story title <span class="text-gray-400 font-normal text-base">(required)</span></label>
                <input type="text" wire:model="manualTitle" required
                    class="w-full rounded-xl border border-gray-300 px-4 py-3 text-lg text-gray-800 focus:border-green-400 focus:outline-none focus:ring-1 focus:ring-green-400 dark:border-zinc-600 dark:bg-zinc-800 dark:text-gray-200"
                    placeholder="Add your title here..."
                    wire:loading.attr="disabled" wire:target="startManualReview" />
                @error('manualTitle')
                    <p class="mt-2 text-base text-red-600 font-medium">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="mb-2 block text-lg font-medium text-gray-800 dark:text-gray-200">Your story</label>
                <textarea wire:model="manualStory" rows="8"
                    class="mic-textarea w-full resize-none rounded-xl p-4 text-lg text-gray-800 dark:text-gray-100"
                    placeholder="Paste or write your story here…"
                    wire:loading.attr="disabled" wire:target="startManualReview"></textarea>
                @error('manualStory')
                    <p class="mt-2 text-base text-red-600 font-medium">{{ $message }}</p>
                @enderror
            </div>

            <button
                wire:click="startManualReview"
                wire:loading.attr="disabled"
                class="flex w-full items-center justify-center gap-3 rounded-xl bg-green-600 px-6 py-5 text-xl font-bold text-white shadow-md transition-colors hover:bg-green-700 active:bg-green-800 disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="startManualReview">✨ Review & Refine My Story</span>
                <span wire:loading wire:target="startManualReview" class="flex items-center gap-2">
                    <span class="size-5 rounded-full border-2 border-white/40 border-t-white animate-spin inline-block"></span>
                    Reviewing…
                </span>
            </button>

            <button
                wire:click="$set('step', 'welcome')"
                class="flex w-full items-center justify-center gap-2 rounded-xl border-2 border-gray-200 bg-white px-6 py-4 text-base font-semibold text-gray-700 hover:bg-gray-50 cursor-pointer dark:border-zinc-600 dark:bg-zinc-800 dark:text-gray-300"
            >
                ← Back
            </button>
        </div>

    @elseif ($step === 'manual_clarify')
        {{-- Manual story follow-up question --}}
        <div class="mb-5 text-center px-4">
            <div class="mb-3 flex size-14 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30 mx-auto">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-7 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" />
                </svg>
            </div>
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">A quick follow-up</h2>
            <p class="mt-1 text-base text-gray-500 dark:text-gray-400">This one small detail helps the AI polish your story just the way you remember it.</p>
        </div>

        <div class="rounded-2xl border-2 border-green-300 bg-white p-5 shadow-sm dark:border-green-700 dark:bg-zinc-800 space-y-4">
            @error('manualStory')
                <p class="text-base text-red-600 font-medium">{{ $message }}</p>
            @enderror

            <p class="mb-2 text-sm font-semibold text-green-700 dark:text-green-300">
                Follow-up {{ $manualQuestionIndex + 1 }} of {{ count($manualQuestions) }}
            </p>
            <p class="text-lg font-medium text-gray-800 dark:text-gray-200">{{ $manualQuestion }}</p>

            <textarea
                wire:model="manualAnswer"
                rows="3"
                class="mic-textarea w-full resize-none rounded-xl p-4 text-lg text-gray-800 dark:text-gray-100"
                placeholder="Tap here and tell us a little more…"
            ></textarea>

            <button
                wire:click="submitManualAnswer"
                wire:loading.attr="disabled"
                class="flex w-full items-center justify-center gap-3 rounded-xl bg-green-600 px-6 py-5 text-xl font-bold text-white shadow-md transition-colors hover:bg-green-700 active:bg-green-800 disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="submitManualAnswer">Add detail & continue</span>
                <span wire:loading wire:target="submitManualAnswer" class="flex items-center gap-2">
                    <span class="size-5 rounded-full border-2 border-white/40 border-t-white animate-spin inline-block"></span>
                    Thinking…
                </span>
            </button>

            <button
                wire:click="skipManualAnswer"
                wire:loading.attr="disabled"
                class="flex w-full items-center justify-center gap-2 rounded-xl border-2 border-green-200 bg-white px-6 py-4 text-base font-semibold text-green-600 hover:bg-green-50 dark:border-green-800 dark:bg-zinc-800 dark:text-green-400"
            >
                No thanks — skip this question & continue
            </button>
        </div>

    @elseif ($step === 'manual_no_changes')
        {{-- AI found nothing to improve; ask the user what they want --}}
        <div class="mb-5 text-center px-4">
            <div class="mb-3 flex size-14 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30 mx-auto">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-7 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                </svg>
            </div>
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $manualFocusHeading }}</h2>
            @if ($manualFocusSubtext)
                <p class="mt-1 text-base text-gray-500 dark:text-gray-400">{{ $manualFocusSubtext }}</p>
            @endif
        </div>

        <div class="rounded-2xl border-2 border-green-300 bg-white p-5 shadow-sm dark:border-green-700 dark:bg-zinc-800 space-y-4">
            @error('manualStory')
                <p class="text-base text-red-600 font-medium">{{ $message }}</p>
            @enderror

            <textarea
                wire:model="manualFocusText"
                rows="3"
                class="w-full resize-none rounded-xl border-2 border-green-200 p-4 text-lg text-gray-800 dark:text-gray-100 focus:border-green-500 focus:ring-green-500"
                placeholder="{{ $this->manualFocusPlaceholder() }}"
                wire:loading.attr="disabled" wire:target="applyManualFocusText, saveManualAsIs"
            ></textarea>

            <button
                wire:click="applyManualFocusText"
                wire:loading.attr="disabled"
                class="flex w-full items-center justify-center gap-3 rounded-xl border-2 border-green-600 bg-white px-6 py-4 text-lg font-semibold text-green-700 hover:bg-green-50 dark:border-green-800 dark:bg-zinc-800 dark:text-green-400"
            >
                <span wire:loading.remove wire:target="applyManualFocusText">Refine with these details</span>
                <span wire:loading wire:target="applyManualFocusText" class="inline-flex items-center gap-2">
                    <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Reviewing…
                </span>
            </button>

            <button
                wire:click="saveManualAsIs"
                wire:loading.attr="disabled"
                class="flex w-full items-center justify-center gap-3 rounded-xl bg-green-600 px-6 py-4 text-lg font-bold text-white hover:bg-green-700"
            >
                <span wire:loading.remove wire:target="saveManualAsIs">No thanks — save it as is</span>
                <span wire:loading wire:target="saveManualAsIs" class="inline-flex items-center gap-2">
                    <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Reviewing…
                </span>
            </button>
        </div>

    @elseif ($step === 'generating')
        {{-- Generating state — poll for completion --}}
        <div wire:poll.3s="checkStatus" class="relative flex flex-col items-center justify-center overflow-hidden py-20 text-center"
            x-data="{
                messages: [
                    'Your writing coach is reading your story carefully…',
                    'Keeping your voice and fixing the typos…',
                    'Almost there — putting the finishing touches on…',
                    'Still working — good things take a little time!',
                    'Nearly ready — hang tight just a moment longer…'
                ],
                index: 0,
                init() { setInterval(() => { this.index = (this.index + 1) % this.messages.length }, 8000) }
            }"
        >
            @php
                $kidEmojis = collect([
                    ['Superhero' => '🦸', 'Fire truck' => '🚒', 'Dinosaur' => '🦖', 'Reading' => '📚', 'Park' => '🌳', 'Space' => '🚀'][$kidIdea] ?? null,
                    ['Me' => '👤', 'Mom' => '👩', 'Dad' => '👨', 'Brother' => '👦', 'Sister' => '👧', 'Friend' => '🤝', 'Pet' => '🐶', 'Grandma or Grandpa' => '👵'][$kidWho] ?? null,
                    ['Home' => '🏠', 'Park' => '🌳', 'School' => '🏫', 'Store' => '🛒', "Grandma's house" => '🏡', 'In the car' => '🚗'][$kidWhere] ?? null,
                    ['We found it' => '🔎', 'We laughed' => '😂', 'We helped' => '🤝', 'It was a surprise' => '🎁', 'We went home' => '🏠', 'Everything was okay' => '✅'][$kidEnding] ?? null,
                ])->filter()->values()->all();
            @endphp

            <div class="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
                @foreach ($kidEmojis as $i => $emoji)
                    <span
                        class="absolute text-4xl opacity-20 dark:opacity-10"
                        style="left: {{ 10 + $i * 22 }}%; bottom: -60px; animation: float 6s ease-in-out {{ $i * 1.2 }}s infinite;"
                    >
                        {{ $emoji }}
                    </span>
                @endforeach
            </div>

            <div class="mb-6 relative">
                @if ($format === 'author_voice')
                    <div class="size-20 rounded-full border-4 border-amber-100 border-t-amber-500 animate-spin"></div>
                @else
                    <div class="size-20 rounded-full border-4 border-blue-100 border-t-blue-500 animate-spin"></div>
                @endif
            </div>
            <h2 class="mb-3 text-2xl font-bold text-gray-900 dark:text-white">Polishing your story…</h2>
            <p class="text-lg text-gray-500 dark:text-gray-400 max-w-xs" x-text="messages[index]"></p>
            <p class="mt-4 text-sm text-gray-400">Usually 30–60 seconds. Please don't close this page.</p>
        </div>

    @elseif ($step === 'done')
        {{-- Completed --}}
        @php $story = \App\Models\Story::find($storyId); @endphp

        <div wire:poll.3s="checkStatus" class="flex flex-col items-center justify-center py-12 text-center">
            <div class="mb-5 flex size-14 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-7 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                </svg>
            </div>
            <h2 class="mb-1 text-2xl font-bold text-gray-900 dark:text-white">Your story is ready! 🎉</h2>
            <p class="mb-2 text-lg font-medium text-gray-700 dark:text-gray-200">{{ $story?->title ?? 'Untitled Story' }}</p>
            @if ($story?->content)
                <p class="mb-2 text-sm text-gray-400">{{ number_format(str_word_count($story->content)) }} words written</p>
            @endif

            <div class="mb-6 w-full max-w-xs">
                @if ($story?->cover_image_path)
                    <img
                        src="{{ Storage::disk('public')->url($story->cover_image_path) }}?v={{ Storage::disk('public')->lastModified($story->cover_image_path) }}"
                        alt="Cover"
                        class="mx-auto h-40 w-28 rounded-2xl object-contain shadow-md"
                    />
                @else
                    <div class="mx-auto flex h-40 w-28 flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-gray-300 bg-gray-50 p-3 text-center dark:border-zinc-600 dark:bg-zinc-800">
                        <span class="text-3xl" aria-hidden="true">🎨</span>
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Painting your cover…</p>
                        <p class="text-[10px] text-gray-400 dark:text-gray-500">This will appear in a few seconds</p>
                    </div>
                @endif
            </div>

            <div class="flex flex-col gap-3 w-full max-w-xs">
                @if ($story)
                    <a
                        href="{{ route('books.show', $story) }}"
                        class="flex items-center justify-center gap-2 rounded-xl bg-green-500 px-6 py-4 text-xl font-bold text-white shadow-md transition-colors hover:bg-green-600"
                        wire:navigate
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                        </svg>
                        Read My Story
                    </a>
                @endif
                <a
                    href="{{ route('books.index') }}"
                    class="rounded-xl border-2 border-gray-200 px-6 py-3 text-base font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-zinc-600 dark:text-gray-300 text-center"
                    wire:navigate
                >
                    Go to My Stories
                </a>
                <button
                    wire:click="cancelStory"
                    class="text-sm text-gray-400 underline hover:text-gray-600"
                >
                    Write another story
                </button>
            </div>
        </div>
    @endif

</div>

@script
<script>
    // Stop any speech synthesis when the user navigates away
    document.addEventListener('livewire:navigating', () => {
        window.speechSynthesis.cancel();
    });
    window.addEventListener('pagehide', () => {
        window.speechSynthesis.cancel();
    });
</script>
@endscript

{{-- Mobile-friendly select styles --}}
<style>
[x-cloak] { display: none !important; }

@keyframes float {
    0% { transform: translateY(0) rotate(0deg); opacity: 0; }
    10% { opacity: 0.35; }
    90% { opacity: 0.35; }
    100% { transform: translateY(-120vh) rotate(20deg); opacity: 0; }
}

select option {
    font-size: 18px;
    padding: 12px;
}

/* Mic textarea: looks like a blue button when empty, normal when typing */
.mic-textarea {
    background: #2563eb;
    border: 2px solid #2563eb;
    color: transparent;
    transition: background 0.2s, border-color 0.2s, color 0.15s;
    caret-color: #1e40af;
}
.mic-textarea::placeholder {
    color: rgba(255,255,255,0.92);
    font-size: 1.125rem;
    font-weight: 600;
    line-height: 1.6;
}
.mic-textarea:focus {
    background: #f9fafb;
    border-color: #3b82f6;
    color: #1f2937 !important;
    outline: none;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.25);
}
.mic-textarea:not(:placeholder-shown) {
    background: #f9fafb;
    border-color: #d1d5db;
    color: #1f2937 !important;
}
/* Ensure text is always visible once the field has any value */
.mic-textarea:focus,
.mic-textarea:not(:placeholder-shown) {
    color: #1f2937 !important;
    background: #f9fafb !important;
}
</style>
