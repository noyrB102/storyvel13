<?php

use App\Jobs\GenerateStoryContent;
use App\Models\Story;
use Livewire\Component;
use Livewire\WithFileUploads;
use App\Ai\Agents\StoryAgent;

new class extends Component
{
    use WithFileUploads;

    // welcome | idea | ai_prompt | details | voice_draft | voice_characters | voice_emotion | voice_tone | voice_title | voice_review | generating | done
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
            'format'        => 'required|in:explore,short_story,chapter,outline,author_voice',
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

    public function toAiPrompt(): void
    {
        $this->format = 'short_story';
        $this->step   = 'ai_prompt';
    }

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
        // Skip character/emotion/tone steps if user already has a substantial draft
        if ($this->hasSubstantialDraft()) {
            // Also skip title step if a title is already set
            if (!empty(trim($this->title))) {
                $this->toVoiceReview();
            } else {
                $this->step = 'voice_title';
            }
        } else {
            $this->step = 'voice_characters';
        }
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
        $this->aiReview        = '';
        $this->loadingReview   = false;
        $this->showIdeaDetails = false;
        $this->showFullIdea    = false;
        $this->storyId         = null;
        $this->step            = 'welcome';
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

        if ($story && $story->isCompleted()) {
            $this->step = 'done';
        } elseif ($story && $story->status === 'failed') {
            $this->step = 'idea';
            $this->addError('prompt', 'Story generation failed. Please try again.');
        }
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

        {{-- Spark cards: tap one to begin with a gentle sentence starter --}}
        <div class="mb-5">
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
        <div class="mb-5 rounded-2xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800/40 dark:bg-amber-900/10">
            <p class="mb-2 text-base font-semibold text-amber-800 dark:text-amber-300">✏️ A few tips to get going:</p>
            <ul class="space-y-1.5 text-base text-gray-700 dark:text-gray-300">
                <li>• Think of <strong>one or two people</strong> — what were their names?</li>
                <li>• <strong>Where</strong> did it happen?</li>
                <li>• <strong>How</strong> did it make you feel?</li>
                <li>• Don't worry about getting it perfect — just start talking! 🎤</li>
            </ul>
        </div>

        {{-- Start from scratch + My Stories --}}
        <div class="pb-8 space-y-3">
            <button
                wire:click="startWriting"
                class="flex w-full items-center justify-center gap-3 rounded-xl bg-green-600 px-6 py-4 text-xl font-bold text-white shadow-md transition-colors hover:bg-green-700 active:bg-green-800"
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
                <p class="pt-2 text-sm text-gray-400 text-center">Tip: tap &amp; hold inside the box above to <strong>Paste</strong> text</p>
            </div>
        </div>

        {{-- Back to inspiration ideas --}}
        <div class="mb-2 flex items-center justify-center">
            <button wire:click="$set('step', 'welcome')" class="text-sm font-medium text-gray-500 py-1 px-3 hover:text-gray-700 dark:text-gray-400">
                ← Back to story ideas
            </button>
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
                <div class="flex items-center justify-center mt-3">
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

        <div class="rounded-2xl border-2 border-amber-200 bg-white shadow-sm dark:border-amber-700 dark:bg-zinc-800 p-5 space-y-4">
            {{-- Original Idea Reference — only when it differs from what's in the box --}}
            @if (!empty($prompt) && trim($prompt) !== trim($voiceDraft))
                <div class="rounded-xl border border-blue-100 bg-blue-50/50 dark:border-blue-800/30 dark:bg-blue-900/10 px-4 py-3">
                    <div wire:click="$toggle('showIdeaDetails')" class="flex w-full items-center justify-between cursor-pointer select-none">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-medium text-blue-800 dark:text-blue-300">Your story so far</span>
                            <span class="text-xs text-blue-600 dark:text-blue-400">(tap to expand)</span>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-4 text-blue-400 transition-transform duration-200 {{ $showIdeaDetails ? 'rotate-180' : '' }}" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                    </div>
                    @if ($showIdeaDetails)
                        <div class="mt-2" wire:transition>
                            <div class="text-sm text-blue-700 dark:text-blue-300 break-words {{ $showFullIdea ? '' : 'line-clamp-4' }}">
                                {{ $prompt }}
                            </div>
                            @if (str_word_count($prompt) > 50)
                                <div wire:click="$toggle('showFullIdea')" class="mt-2 text-xs font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 cursor-pointer inline-block select-none">
                                    {{ $showFullIdea ? 'Show less' : 'Show more' }}
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            @endif

            @if ($this->hasSubstantialDraft())
                <div class="rounded-xl bg-green-50 dark:bg-green-900/20 px-4 py-3 text-sm text-green-800 dark:text-green-300 border border-green-100 dark:border-green-800/30">
                    <p><strong>✓ Great start!</strong> Your draft is ready. Review it or just tap Next.</p>
                </div>
            @else
                <div class="rounded-xl bg-amber-50 dark:bg-amber-900/20 px-4 py-3 text-amber-800 dark:text-amber-300">
                    <p class="mb-2 text-base font-semibold">💡 Keep going — add a few more sentences. Try one of these:</p>
                    <ul class="space-y-1.5 text-base">
                        <li>• Who else was there?</li>
                        <li>• Where did it happen?</li>
                        <li>• What did you see, hear, or smell?</li>
                        <li>• How did it make you feel?</li>
                        <li>• What happened next?</li>
                    </ul>
                    <p class="mt-2 text-sm">Just keep talking into the box below — there's no wrong answer. 🎤</p>
                </div>
            @endif

            <div>
                <div class="relative" x-data="{ hasText: @js(strlen($voiceDraft) > 0) }">
                    <textarea
                        wire:model="voiceDraft"
                        rows="5"
                        placeholder="🎤 Tap here first..."
                        class="mic-textarea w-full resize-none rounded-xl p-4 text-lg text-gray-800 dark:text-gray-100"
                        @input="hasText = $el.value.length > 0"
                        @focus="hasText = $el.value.length > 0"
                    ></textarea>
                    @if (!$this->hasSubstantialDraft())
                    <div
                        x-show="!hasText"
                        class="mic-reminder pointer-events-none absolute bottom-3 left-0 right-0 flex justify-center px-4"
                    >
                        <span class="rounded-full bg-blue-600 px-4 py-2 text-sm font-bold text-white shadow-md w-full text-center">
                            🎤 Now tap the microphone key on your keyboard
                        </span>
                    </div>
                    @endif
                </div>
                @error('voiceDraft')
                    <p class="mt-2 text-base text-red-600 font-medium">Please write at least a few sentences before continuing.</p>
                @enderror
            </div>

            {{-- Word count + Fix Something --}}
            @if (str_word_count($voiceDraft) > 0)
                <div
                    x-data="{ open: false, request: '', fixing: false, done: false }"
                    class="space-y-3"
                >
                    <div class="flex items-center justify-between">
                        <p class="text-base text-amber-600 dark:text-amber-400 font-medium">{{ str_word_count($voiceDraft) }} words written</p>
                        <button
                            x-show="!open && !done"
                            @click="open = true"
                            class="flex items-center gap-1.5 rounded-lg border border-orange-300 bg-orange-50 px-3 py-1.5 text-sm font-semibold text-orange-700 hover:bg-orange-100 active:bg-orange-200"
                        >✏️ Fix something</button>
                    </div>

                    <template x-if="open">
                        <div class="rounded-xl border border-orange-200 bg-orange-50 p-4 space-y-3">
                            <p class="text-sm font-semibold text-orange-800">What would you like to change?</p>
                            <p class="text-xs text-orange-600">Speak or type it — e.g. "Change Herman to Harold" or "Make the ending happier"</p>
                            <textarea
                                x-model="request"
                                rows="2"
                                placeholder="🎤 Tap and say what to change..."
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
                                            $wire.fixDraft(request).then(() => { fixing = false; done = true; request = ''; });
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
                            <p class="text-sm font-medium text-orange-700">Making your change…</p>
                        </div>
                    </template>

                    <template x-if="done">
                        <div class="flex items-center justify-between rounded-xl border border-green-200 bg-green-50 px-4 py-3">
                            <p class="text-sm font-semibold text-green-700">✅ Done! Review your story above.</p>
                            <button @click="done = false" class="text-xs text-green-600 underline">Fix something else</button>
                        </div>
                    </template>
                </div>
            @endif

        </div>

        {{-- Inline Back + Next row --}}
        <div class="mt-4 pb-8">
            <div class="flex items-center gap-3">
                <button wire:click="$set('step', 'idea')"
                    class="shrink-0 flex items-center justify-center gap-2 rounded-xl border-2 border-gray-300 bg-white px-5 py-4 text-base font-semibold text-gray-700 dark:border-zinc-600 dark:bg-zinc-800 dark:text-gray-300">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                    Back
                </button>
                <button
                    wire:click="toVoiceCharacters"
                    wire:loading.attr="disabled"
                    class="flex flex-1 items-center justify-center gap-2 rounded-xl bg-amber-500 px-6 py-4 text-lg font-bold text-white shadow-md transition-colors hover:bg-amber-600 active:bg-amber-700 disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="toVoiceCharacters">Next →</span>
                    <span wire:loading wire:target="toVoiceCharacters">Saving...</span>
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
                get words() {
                    const v = $wire.voiceDraft || '';
                    return v.trim() ? v.trim().split(/\s+/).length : 0;
                }
            }"
            class="rounded-2xl border-2 border-amber-200 bg-white shadow-sm dark:border-amber-700 dark:bg-zinc-800 p-5 space-y-4"
        >
            {{-- Gentle guiding questions --}}
            <div class="rounded-xl bg-amber-50 dark:bg-amber-900/20 p-4">
                <p class="mb-2 text-base font-semibold text-amber-800 dark:text-amber-300">Try answering one or two of these:</p>
                <ul class="space-y-1.5 text-base text-gray-700 dark:text-gray-300">
                    <li>• Who else was there?</li>
                    <li>• Where did this happen?</li>
                    <li>• What did you see, hear, or smell?</li>
                    <li>• How did it make you feel?</li>
                    <li>• What happened next?</li>
                </ul>
                <p class="mt-2 text-sm text-amber-600 dark:text-amber-400">Just keep talking — add to what you already wrote below. 🎤</p>
            </div>

            {{-- Draft textarea (adds to existing draft) --}}
            <div class="relative" x-data="{ hasText: @js(strlen($voiceDraft) > 0) }">
                <textarea
                    wire:model.live="voiceDraft"
                    rows="6"
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
        </div>

        {{-- Back + Continue --}}
        <div class="mt-4 pb-8">
            <div class="flex items-center gap-3">
                <button wire:click="$set('step', 'voice_review')"
                    class="shrink-0 flex items-center justify-center gap-2 rounded-xl border-2 border-gray-300 bg-white px-5 py-4 text-base font-semibold text-gray-700 dark:border-zinc-600 dark:bg-zinc-800 dark:text-gray-300">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                    Back
                </button>
                <button
                    wire:click="toVoiceReview"
                    wire:loading.attr="disabled"
                    class="flex flex-1 items-center justify-center gap-2 rounded-xl bg-amber-500 px-6 py-4 text-lg font-bold text-white shadow-md transition-colors hover:bg-amber-600 active:bg-amber-700 disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="toVoiceReview">Done — Re-check My Story →</span>
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

    @elseif ($step === 'generating')
        {{-- Generating state — poll for completion --}}
        <div wire:poll.3s="checkStatus" class="flex flex-col items-center justify-center py-20 text-center"
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

        <div class="flex flex-col items-center justify-center py-12 text-center">
            <div class="mb-5 flex size-14 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-7 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                </svg>
            </div>
            <h2 class="mb-1 text-2xl font-bold text-gray-900 dark:text-white">Your story is ready! 🎉</h2>
            <p class="mb-2 text-lg font-medium text-gray-700 dark:text-gray-200">{{ $story?->title ?? 'Untitled Story' }}</p>
            @if ($story?->content)
                <p class="mb-6 text-sm text-gray-400">{{ number_format(str_word_count($story->content)) }} words written</p>
            @endif

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
