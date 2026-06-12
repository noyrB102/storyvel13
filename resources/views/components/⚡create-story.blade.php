<?php

use App\Jobs\GenerateStoryContent;
use App\Models\Story;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    // idea | details | voice_draft | voice_characters | voice_emotion | voice_tone | generating | done
    public string $step = 'idea';

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

    // UI toggle states
    public bool $showIdeaDetails = false;
    public bool $showFullIdea = false;

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
        }
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
        return str_word_count($this->prompt) > 50;
    }

    public function toVoiceDraft(): void
    {
        $this->validate(['prompt' => 'required|min:10']);
        $this->format = 'author_voice';
        $this->step   = 'voice_draft';
    }

    public function toVoiceCharacters(): void
    {
        $this->validate(['voiceDraft' => 'required|min:30']);
        $this->step = 'voice_characters';
    }

    public function toVoiceEmotion(): void
    {
        $this->step = 'voice_emotion';
    }

    public function toVoiceTone(): void
    {
        $this->step = 'voice_tone';
    }

    public function togglePrivate(): void
    {
        $this->isPrivate = !$this->isPrivate;
    }

    public function generate(): void
    {
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

    @if ($step === 'idea')
        {{-- Hero - Elderly-friendly large text --}}
        <div class="mb-6 text-center px-4">
            <h1 class="mb-3 text-3xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl">
                Create Your Story
            </h1>
            <p class="mx-auto max-w-sm text-lg text-gray-600 dark:text-gray-300 leading-relaxed">
                Tell us your idea and we'll help you write it
            </p>
        </div>

        {{-- Input Card - Larger touch targets --}}
        <div class="rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800 mb-8">
            <div class="p-5">
                <label class="mb-3 block text-lg font-medium text-gray-800 dark:text-gray-200">
                    What's your story about?
                </label>
                <div class="relative" x-data="{ hasText: false }">
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
                        class="mic-reminder pointer-events-none absolute bottom-3 left-0 right-0 flex justify-center"
                    >
                        <span class="rounded-full bg-blue-600 px-4 py-2 text-sm font-bold text-white shadow-md">
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

        {{-- Fixed bottom action bar - always visible above iOS keyboard --}}
        <div class="fixed bottom-0 left-0 right-0 z-50 bg-white border-t border-gray-200 px-4 pt-3 pb-8 shadow-lg dark:bg-zinc-900 dark:border-zinc-700"
             x-data="{ hasText: @js(strlen($prompt) > 0) }">
            <button
                wire:click="toVoiceDraft"
                wire:loading.attr="disabled"
                class="flex w-full items-center justify-center gap-3 rounded-xl px-6 py-4 text-xl font-bold text-white shadow-md transition-colors disabled:opacity-60"
                :class="hasText ? 'bg-green-600 hover:bg-green-700 active:bg-green-800' : 'bg-blue-600 hover:bg-blue-700 active:bg-blue-800'"
                @input.window="hasText = document.querySelector('[wire\\:model=\'prompt\']')?.value?.length > 0"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="size-6 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                </svg>
                <span wire:loading.remove wire:target="toVoiceDraft" x-text="hasText ? 'Continue Your Story →' : 'Start Writing My Story →'"></span>
                <span wire:loading wire:target="toVoiceDraft">Starting...</span>
            </button>
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
        {{-- Cancel with confirmation --}}
        <div class="flex justify-end mb-2 px-1">
            <button
                x-data
                @click="if (confirm('Are you sure you want to cancel? Your work will be lost.')) { $wire.set('step', 'idea'); $wire.set('prompt', ''); $wire.set('voiceDraft', ''); }"
                class="flex items-center gap-1 rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-100 active:bg-red-200"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
                Cancel
            </button>
        </div>

        {{-- Voice Step 0: Write your own draft - Elderly-friendly version --}}
        <div class="mb-6 text-center px-2">
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
            {{-- Original Idea Reference --}}
            @if (!empty($prompt))
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
                <div class="rounded-xl bg-green-50 dark:bg-green-900/20 px-4 py-3 text-sm text-green-800 dark:text-green-300 space-y-1.5 border border-green-100 dark:border-green-800/30">
                    <p><strong>✓ I see you've already written this story!</strong> Your draft has been pre-filled below.</p>
                    <p class="text-green-700 dark:text-green-400">Review it, make any edits, or just continue to the next step. The AI will help polish what you've written while keeping your voice.</p>
                </div>
            @else
                <div class="rounded-lg bg-amber-50 dark:bg-amber-900/20 px-3 py-2 text-sm text-amber-800 dark:text-amber-300">
                    <p><strong>Don't overthink this.</strong> Just write a bit — a sentence or two is enough to start.</p>
                </div>
            @endif

            <div>
                <div class="relative" x-data="{ hasText: false }">
                    <textarea
                        wire:model="voiceDraft"
                        rows="10"
                        placeholder="🎤 Tap here first..."
                        class="mic-textarea w-full resize-none rounded-xl p-4 text-lg text-gray-800 dark:text-gray-100"
                        @input="hasText = $el.value.length > 0"
                        @focus="hasText = $el.value.length > 0"
                    ></textarea>
                    <div
                        x-show="!hasText"
                        class="mic-reminder pointer-events-none absolute bottom-3 left-0 right-0 flex justify-center"
                    >
                        <span class="rounded-full bg-blue-600 px-4 py-2 text-sm font-bold text-white shadow-md">
                            🎤 Now tap the microphone key on your keyboard
                        </span>
                    </div>
                </div>
                @error('voiceDraft')
                    <p class="mt-2 text-base text-red-600 font-medium">Please write at least a few sentences before continuing.</p>
                @enderror
            </div>

            {{-- Simple progress indicator --}}
            @if (str_word_count($voiceDraft) > 0)
                <p class="text-base text-amber-600 dark:text-amber-400 font-medium">{{ str_word_count($voiceDraft) }} words written</p>
            @endif

        </div>

        {{-- Fixed bottom action bar for voice_draft --}}
        <div class="fixed bottom-0 left-0 right-0 z-50 bg-white border-t border-gray-200 px-4 pt-3 pb-8 shadow-lg dark:bg-zinc-900 dark:border-zinc-700">
            <div class="flex items-center gap-3">
                <button wire:click="$set('step', 'idea')" class="flex items-center justify-center gap-2 rounded-xl border-2 border-gray-300 bg-white px-4 py-4 text-base font-medium text-gray-700 dark:border-zinc-600 dark:bg-zinc-800 dark:text-gray-300">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                    Back
                </button>
                <button
                    wire:click="toVoiceCharacters"
                    wire:loading.attr="disabled"
                    class="flex flex-1 items-center justify-center gap-2 rounded-xl bg-amber-500 px-6 py-4 text-lg font-bold text-white shadow-md transition-colors hover:bg-amber-600 active:bg-amber-700 disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="toVoiceCharacters">Continue to Step 2 →</span>
                    <span wire:loading wire:target="toVoiceCharacters">Saving...</span>
                </button>
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
                        class="mic-reminder pointer-events-none absolute bottom-3 left-0 right-0 flex justify-center"
                    >
                        <span class="rounded-full bg-blue-600 px-4 py-2 text-sm font-bold text-white shadow-md">
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
                    Continue to Step 3
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
                        class="mic-reminder pointer-events-none absolute bottom-3 left-0 right-0 flex justify-center"
                    >
                        <span class="rounded-full bg-blue-600 px-4 py-2 text-sm font-bold text-white shadow-md">
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
                    Continue to Step 4
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

            {{-- Generate --}}
            <button
                wire:click="generate"
                wire:loading.attr="disabled"
                class="w-full rounded-lg bg-amber-500 py-3 text-sm font-semibold text-white transition-colors hover:bg-amber-600 disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="generate">Finish My Story ✨</span>
                <span wire:loading wire:target="generate">Starting…</span>
            </button>

            <p class="text-center text-xs text-gray-400">The AI will fix spelling &amp; punctuation but keep your words and style.</p>
            <div class="text-center">
                <button wire:click="generate" class="text-base text-gray-400 underline hover:text-gray-600 cursor-pointer">Skip style choices &amp; finish now →</button>
            </div>
        </div>

        <button wire:click="toVoiceEmotion" class="mt-4 flex items-center gap-1 text-sm text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
            <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
            </svg>
            Back
        </button>

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
                    wire:click="$set('step', 'idea')"
                    class="text-sm text-gray-400 underline hover:text-gray-600"
                >
                    Write another story
                </button>
            </div>
        </div>
    @endif

</div>
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
