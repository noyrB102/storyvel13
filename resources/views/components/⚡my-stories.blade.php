<?php

use App\Models\SiteSetting;
use App\Models\Story;
use App\Models\StoryInput;
use App\Services\StoryImprover;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public ?int $pendingStoryId = null;
    public ?int $reviewingStoryId = null;
    public ?string $reviewQuestion = null;
    public string $reviewAnswer = '';
    public int $reviewQuestionIndex = 0;
    public array $reviewQuestions = [];
    public bool $reviewLoading = false;
    public bool $reviewImproving = false;
    public ?array $pendingReview = null;
    public string $pendingContext = '';
    public string $diagnostic = '';

    public function with(): array
    {
        $stories = Story::where('user_id', auth()->id())->latest()->get();
        $book = auth()->user()->currentBook();
        $targetCount = (int) SiteSetting::get('book_target_count', 8);
        $inBookIds = $book ? $book->stories()->pluck('stories.id')->toArray() : [];
        $emailedInBookIds = $book ? $book->stories()->whereNotNull('email_sent_at')->pluck('stories.id')->toArray() : [];
        $bookFull = $book !== null && count($inBookIds) >= $targetCount;

        return [
            'stories' => $stories,
            'targetCount' => $targetCount,
            'inBookIds' => $inBookIds,
            'emailedInBookIds' => $emailedInBookIds,
            'bookFull' => $bookFull,
        ];
    }

    public function startAddToBook(int $storyId): void
    {
        logger('my-stories startAddToBook', ['storyId' => $storyId]);

        $story = Story::where('user_id', auth()->id())->findOrFail($storyId);
        $storedContext = $story->guidedInput?->extra_context ?? '';

        $this->reviewAnswer = '';
        $this->reviewQuestionIndex = 0;
        $this->reviewQuestions = [];
        $this->reviewQuestion = null;

        if ($storedContext !== '') {
            // We already have details from a previous review; re-craft and add directly.
            $this->reviewingStoryId = $storyId;
            $this->pendingStoryId = $storyId;
            $this->pendingContext = $storedContext;
            $this->pendingReview = [];
            $this->continueAfterAnswers();
            return;
        }

        // No stored context; run a fresh review.
        $this->reviewingStoryId = $storyId;
        $this->pendingStoryId = $storyId;
        $this->pendingContext = '';
        $this->pendingReview = null;
        $this->runReviewForPendingStory();
    }

    protected function runReviewForPendingStory(): void
    {
        $storyId = $this->pendingStoryId;

        if (! $storyId) {
            $this->finalizeAddToBook();
            return;
        }

        $this->reviewLoading = true;
        $this->reviewImproving = false;

        $story = Story::where('user_id', auth()->id())->findOrFail($storyId);
        $this->diagnostic .= "3. story loaded id={$story->id} (PASS)\n";

        try {
            $this->diagnostic .= "4. calling StoryImprover::review (PASS)\n";
            $review = app(StoryImprover::class)->review($story->content ?? '');
            $this->diagnostic .= "5. StoryImprover::review returned " . ($review !== null ? 'PASS' : 'FAIL') . "\n";
        } catch (\Throwable $e) {
            $this->diagnostic .= "5. StoryImprover::review threw: ".get_class($e)." - ".str_replace("\n", ' ', $e->getMessage())." (FAIL)\n";
            $review = null;
        }

        $this->reviewLoading = false;

        if ($review === null) {
            $this->diagnostic .= "6. review is null; calling finalize (FAIL)\n";
            $this->finalizeAddToBook();
            return;
        }

        $this->diagnostic .= "6. review parsed; keys: ".implode(',', array_keys($review)) ." (PASS)\n";

        $questionMap = [
            'voice' => 'How would you tell this story out loud to a friend? Share one or two sentences in your own words.',
            'detail' => 'What is one sight, sound, smell, or feeling you remember from this moment?',
            'ending' => 'How did this moment leave you, or what did you learn from it?',
            'shorter' => 'Is there a part you would be okay leaving out or shortening?',
        ];

        $questions = [];
        foreach (['voice', 'detail', 'ending', 'shorter'] as $key) {
            if (! empty($review[$key]['recommend'])) {
                $question = ! empty($review[$key]['question']) ? $review[$key]['question'] : $questionMap[$key];
                $questions[] = ['key' => $key, 'text' => $question];
            }
        }

        $this->reviewQuestions = array_slice($questions, 0, 2);

        if (empty($this->reviewQuestions)) {
            $this->diagnostic .= "7. no questions found; calling finalize (PASS)\n";
            $this->finalizeAddToBook();
            return;
        }

        $this->diagnostic .= "7. questions found: ".count($this->reviewQuestions)." (PASS)\n";
        $this->pendingReview = $review;
        $this->reviewQuestionIndex = 0;
        $this->reviewQuestion = $this->reviewQuestions[0]['text'] ?? null;
    }

    public function submitReviewAnswer(): void
    {
        $this->validate([
            'reviewAnswer' => 'required|string|max:5000',
        ]);

        $this->pendingContext .= ($this->pendingContext ? "\n\n" : '') . $this->reviewQuestion . "\n" . $this->reviewAnswer;

        StoryInput::updateOrCreate(
            ['story_id' => $this->pendingStoryId],
            ['extra_context' => $this->pendingContext, 'user_id' => auth()->id()]
        );

        $this->reviewQuestionIndex++;

        if ($this->reviewQuestionIndex < count($this->reviewQuestions)) {
            $this->reviewQuestion = $this->reviewQuestions[$this->reviewQuestionIndex]['text'];
            $this->reviewAnswer = '';
            return;
        }

        $this->continueAfterAnswers();
    }

    public function skipReviewQuestion(): void
    {
        if ($this->reviewImproving) {
            return;
        }

        $this->reviewQuestionIndex++;

        if ($this->reviewQuestionIndex < count($this->reviewQuestions)) {
            $this->reviewQuestion = $this->reviewQuestions[$this->reviewQuestionIndex]['text'];
            $this->reviewAnswer = '';
            return;
        }

        $this->continueAfterAnswers();
    }

    public function skipReviewAndAdd(): void
    {
        $this->finalizeAddToBook();
    }

    public function cancelReview(): void
    {
        $this->resetReviewState();
    }

    protected function continueAfterAnswers(): void
    {
        if ($this->reviewImproving) {
            return;
        }

        $this->reviewImproving = true;
        set_time_limit(0);

        try {
            $story = Story::where('user_id', auth()->id())->findOrFail($this->pendingStoryId);

            if ($this->pendingContext !== '') {
                StoryInput::updateOrCreate(
                    ['story_id' => $story->id],
                    ['extra_context' => $this->pendingContext, 'user_id' => auth()->id()]
                );
            }

            $newContent = app(StoryImprover::class)->improve($story->content ?? '', $this->pendingContext, $this->pendingReview);

            if ($newContent !== '' && $newContent !== $story->content) {
                $story->update(['content' => $newContent]);
            }
        } catch (\Throwable) {
            // If re-crafting fails, the original story is still used.
        }

        // After re-crafting, add the story to the book
        $this->finalizeAddToBook();
    }

    public function finalizeAddToBook(): void
    {
        $storyId = $this->pendingStoryId;
        if ($storyId === null) {
            return;
        }

        $this->diagnostic .= "8. finalizeAddToBook called pendingStoryId=".(int) $storyId." (PASS)\n";
        logger('my-stories finalizeAddToBook', ['storyId' => $storyId]);
        $this->resetReviewState();
        $this->dispatch('add-to-book', storyId: $storyId);
    }

    public function resetReviewState(): void
    {
        $this->pendingStoryId = null;
        $this->reviewingStoryId = null;
        $this->reviewQuestion = null;
        $this->reviewAnswer = '';
        $this->reviewQuestionIndex = 0;
        $this->reviewQuestions = [];
        $this->reviewLoading = false;
        $this->reviewImproving = false;
        $this->pendingReview = null;
        $this->pendingContext = '';
    }

    public function removeFromBook(int $storyId): void
    {
        $this->dispatch('remove-from-book', storyId: $storyId);
    }

    #[On('book-updated')]
    public function onBookUpdated(): void
    {
        // Re-runs with() automatically after the event.
    }

    public function hasPendingStories(): bool
    {
        return Story::where('user_id', auth()->id())
            ->where(function ($query) {
                $query->whereNull('cover_image_path')
                    ->orWhere('status', '!=', 'completed');
            })
            ->exists();
    }

    /**
     * Build the rich-text (12pt Arial) HTML payload used when copying a story
     * to the clipboard for pasting into an email. Excludes any image.
     */
    public function copyHtml(Story $story): string
    {
        abort_if($story->user_id !== auth()->id(), 403);

        $title  = trim($story->title ?? 'Untitled Story');
        $author = trim($story->author_name ?? optional($story->user)->name ?? '');

        $raw = $story->content ?? '';
        // Drop any trailing "Writing Coach" note section.
        $raw = preg_split('/^#+\s*Writing Coach.*$/mi', $raw)[0];
        // Remove a leading markdown heading that duplicates the title.
        if ($title !== '') {
            $raw = preg_replace('/^#+\s*' . preg_quote($title, '/') . '\s*(?:\n|$)/mi', '', $raw, 1);
        }
        $bodyHtml = (string) Str::markdown(trim($raw));
        // Decode entities so pasted content shows real quotes, then re-encode only < > &
        // to keep the HTML valid while avoiding &quot; fragments in the pasted output.
        $bodyHtml = htmlspecialchars(html_entity_decode($bodyHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_NOQUOTES, 'UTF-8');

        $style = 'font-family: Arial, Helvetica, sans-serif; font-size: 12pt; line-height: 1.5; color: #000;';

        $html = '<div style="' . $style . '">';
        $html .= '<p style="' . $style . ' font-weight: bold; font-size: 14pt; margin: 0 0 4pt 0;">' . htmlspecialchars($title, ENT_NOQUOTES, 'UTF-8') . '</p>';
        if ($author !== '') {
            $html .= '<p style="' . $style . ' color: #444; margin: 0 0 12pt 0;">by ' . htmlspecialchars($author, ENT_NOQUOTES, 'UTF-8') . '</p>';
        }
        $html .= '<div style="' . $style . '">' . $bodyHtml . '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Plain-text fallback for the clipboard copy.
     */
    public function copyText(Story $story): string
    {
        abort_if($story->user_id !== auth()->id(), 403);

        $title  = trim($story->title ?? 'Untitled Story');
        $author = trim($story->author_name ?? optional($story->user)->name ?? '');

        $raw = $story->content ?? '';
        $raw = preg_split('/^#+\s*Writing Coach.*$/mi', $raw)[0];
        if ($title !== '') {
            $raw = preg_replace('/^#+\s*' . preg_quote($title, '/') . '\s*(?:\n|$)/mi', '', $raw, 1);
        }
        $body = trim(strip_tags((string) Str::markdown(trim($raw))));
        // Decode any HTML entities so plain text doesn't contain &quot; fragments.
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $text = $title . "\n";
        if ($author !== '') {
            $text .= 'by ' . $author . "\n";
        }
        $text .= "\n" . $body;

        return $text;
    }
};

?>

<div
    @if ($this->hasPendingStories())
        wire:poll.5s
    @endif
>
    {{-- ===== MOBILE SIMPLIFIED VIEW ===== --}}
    <div class="flex min-h-[80vh] min-w-0 flex-col items-center justify-start px-4 pb-8 pt-10 text-center sm:px-6 md:hidden">
        <p class="text-2xl font-bold text-gray-900 dark:text-white mb-1">
            Hello, {{ auth()->user()->name }} 👋
        </p>
        <p class="text-base text-gray-500 dark:text-gray-400 mb-10">What would you like to do?</p>

        <div class="w-full max-w-sm flex flex-col gap-4">
            <a href="{{ route('writer.create') }}" wire:navigate
               class="flex min-h-16 min-w-0 flex-wrap items-center justify-center gap-3 rounded-2xl bg-blue-600 px-5 py-6 text-center text-xl font-bold text-white shadow-lg active:bg-blue-700">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-7" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Start a New Story
            </a>

            @if ($stories->isEmpty())
                <div class="rounded-2xl border-2 border-dashed border-gray-200 bg-gray-50 px-6 py-8 text-gray-400 dark:border-zinc-700 dark:bg-zinc-800">
                    <p class="text-base">You haven't written any stories yet.</p>
                    <p class="text-sm mt-1">Tap above to write your first one!</p>
                </div>
            @endif
        </div>

        {{-- Mobile story list (scrolled to) --}}
        @if ($stories->isNotEmpty())
            <div id="my-stories" class="w-full max-w-sm mt-10 text-left">
                <h2 class="mb-4 text-lg font-bold text-gray-800 dark:text-white">My Stories</h2>
                <div class="flex flex-col gap-3">
                    @foreach ($stories as $story)
                        <div class="min-w-0 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                            <a href="{{ route('books.show', $story) }}" wire:navigate
                               class="grid min-w-0 grid-cols-[auto_minmax(0,1fr)_auto] items-start gap-4 p-4">
                                <div class="flex size-12 shrink-0 items-center justify-center rounded-2xl {{ $story->cover_image_path ? '' : 'bg-blue-50 dark:bg-zinc-700' }}">
                                    @if ($story->cover_image_path)
                                        <img src="{{ Storage::url($story->cover_image_path) }}?v={{ Storage::disk('public')->lastModified($story->cover_image_path) }}" class="size-12 rounded-2xl object-contain" />
                                    @else
                                        <svg xmlns="http://www.w3.org/2000/svg" class="size-6 text-blue-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                                        </svg>
                                    @endif
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="break-words text-base font-semibold leading-snug text-gray-900 [overflow-wrap:anywhere] dark:text-white">{{ $story->title ?? 'Untitled Story' }}</p>
                                    <p class="text-sm text-gray-400">{{ $story->created_at->format('M j, Y') }}</p>
                                </div>
                                <svg xmlns="http://www.w3.org/2000/svg" class="size-5 shrink-0 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                </svg>
                            </a>
                            <div class="border-t border-gray-200 px-4 py-3 dark:border-zinc-700">
                                @if (in_array($story->id, $emailedInBookIds))
                                    <span class="flex w-full cursor-not-allowed items-center justify-center gap-2 rounded-xl bg-amber-100 px-4 py-3 text-sm font-semibold text-amber-700 opacity-90 dark:bg-amber-900/30 dark:text-amber-100" title="Sent to print — this story is locked">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 0 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                                        Sent to print
                                    </span>
                                @elseif (in_array($story->id, $inBookIds))
                                    <button type="button" wire:click="removeFromBook({{ $story->id }})" class="flex w-full items-center justify-center gap-2 rounded-xl bg-red-50 px-4 py-3 text-sm font-semibold text-red-600 transition hover:bg-red-100 dark:bg-red-900/20 dark:text-red-400">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                        Remove from My Next Book
                                    </button>
                                @elseif ($bookFull)
                                    <span class="block text-center text-sm font-medium text-gray-400" title="Remove a story from My Next Book to add more">Book is full</span>
                                @elseif ($story->status !== 'completed')
                                    <span class="block text-center text-sm font-medium text-gray-400">Not completed</span>
                                @else
                                    <button type="button" wire:click="startAddToBook({{ $story->id }})" wire:loading.attr="disabled" wire:target="startAddToBook({{ $story->id }})" class="flex w-full cursor-pointer items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                                        <span wire:loading.remove wire:target="startAddToBook({{ $story->id }})" class="flex items-center justify-center gap-2">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                            Add to My Next Book
                                        </span>
                                        <span wire:loading wire:target="startAddToBook({{ $story->id }})" class="flex items-center justify-center gap-2">
                                            <span class="size-4 rounded-full border-2 border-white/40 border-t-white animate-spin inline-block"></span>
                                            Reviewing…
                                        </span>
                                    </button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- My Next Book section (mobile) --}}
        <livewire:my-book />
    </div>

    {{-- ===== DESKTOP VIEW (unchanged) ===== --}}
    <div class="hidden md:block mx-auto max-w-6xl px-4 py-12 sm:px-6 lg:px-8">

        <!-- Page Header -->
        <div class="mb-8 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white">My Stories</h1>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $stories->count() }} {{ Str::plural('story', $stories->count()) }}</p>
            </div>
            <a
                href="{{ route('writer.create') }}"
                class="flex items-center gap-2 rounded-lg bg-blue-500 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition-colors hover:bg-blue-600"
                wire:navigate
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                New Story
            </a>
        </div>

        @if (session('success'))
            <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-400">
                {{ session('success') }}
            </div>
        @endif

        @if ($stories->isEmpty())
            <!-- Empty State -->
            <div class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-gray-300 bg-white py-24 text-center dark:border-zinc-700 dark:bg-zinc-800">
                <div class="mb-4 flex size-16 items-center justify-center rounded-full bg-blue-50 dark:bg-zinc-700">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-8 text-blue-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                    </svg>
                </div>
                <h3 class="mb-1 text-base font-semibold text-gray-900 dark:text-white">No stories yet</h3>
                <p class="mb-6 max-w-xs text-sm text-gray-500 dark:text-gray-400">
                    Start writing your first book or story with AI assistance.
                </p>
                <a
                    href="{{ route('writer.create') }}"
                    class="rounded-lg bg-blue-500 px-5 py-2.5 text-sm font-medium text-white transition-colors hover:bg-blue-600"
                    wire:navigate
                >
                    Create your first story
                </a>
            </div>
        @else
            <!-- Stories Grid -->
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($stories as $story)
                    <div class="group relative flex flex-col overflow-hidden rounded-3xl border border-gray-200 bg-white shadow-sm transition-shadow hover:shadow-md dark:border-zinc-700 dark:bg-zinc-800">
                        <a
                            href="{{ route('books.show', $story) }}"
                            wire:navigate
                            class="flex flex-1 flex-col"
                        >
                            <!-- Cover Image -->
                            <div class="relative h-44 w-full overflow-hidden">
                                @if ($story->cover_image_path)
                                    <img
                                        src="{{ Storage::url($story->cover_image_path) }}?v={{ Storage::disk('public')->lastModified($story->cover_image_path) }}"
                                        alt="{{ $story->title ?? 'Story cover' }}"
                                        class="h-full w-full object-contain transition-transform duration-300 group-hover:scale-105"
                                    />
                                @else
                                    <div class="flex h-full items-center justify-center bg-gradient-to-br from-blue-50 to-indigo-100 dark:from-zinc-700 dark:to-zinc-600">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="size-12 text-blue-200 dark:text-zinc-500" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                                        </svg>
                                    </div>
                                @endif

                                <!-- Status badge -->
                                @if ($story->status !== 'completed')
                                    <span class="absolute right-3 top-3 rounded-full px-2.5 py-0.5 text-xs font-medium
                                        {{ $story->status === 'generating' ? 'bg-yellow-100 text-yellow-700' : '' }}
                                        {{ $story->status === 'pending'    ? 'bg-gray-100 text-gray-600'   : '' }}
                                        {{ $story->status === 'failed'     ? 'bg-red-100 text-red-600'     : '' }}
                                    ">
                                        {{ ucfirst($story->status) }}
                                    </span>
                                @endif
                            </div>

                            <!-- Card Body -->
                            <div class="flex flex-1 flex-col p-5">
                                <div class="mb-1 flex items-start justify-between gap-2">
                                    <h2 class="line-clamp-1 text-sm font-semibold text-gray-900 dark:text-white">
                                        {{ $story->title ?? 'Untitled Story' }}
                                    </h2>
                                    @if ($story->genre)
                                        <span class="shrink-0 rounded-full bg-blue-50 px-2 py-0.5 text-xs text-blue-600 dark:bg-blue-900/30 dark:text-blue-400">
                                            {{ ucfirst($story->genre) }}
                                        </span>
                                    @endif
                                </div>
                                @if ($story->author_name)
                                    <p class="mb-1 text-xs text-gray-400 dark:text-gray-500">by {{ $story->author_name }}</p>
                                @endif
                                <p class="mb-4 line-clamp-2 flex-1 text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                                    {{ $story->content ? Str::limit(strip_tags($story->content), 120) : $story->prompt }}
                                </p>
                                <p class="text-xs text-gray-400 dark:text-gray-500">
                                    {{ $story->created_at->diffForHumans() }}
                                </p>
                            </div>
                        </a>

                        <div class="border-t border-gray-200 p-5 dark:border-zinc-700">
                            @if (in_array($story->id, $emailedInBookIds))
                                <span class="flex w-full cursor-not-allowed items-center justify-center gap-2 rounded-xl bg-amber-100 px-4 py-3 text-sm font-semibold text-amber-700 opacity-90 dark:bg-amber-900/30 dark:text-amber-100" title="Sent to print — this story is locked">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 0 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                                    Sent to print
                                </span>
                            @elseif (in_array($story->id, $inBookIds))
                                <button type="button" wire:click="removeFromBook({{ $story->id }})" class="flex w-full items-center justify-center gap-2 rounded-xl bg-red-50 px-4 py-3 text-sm font-semibold text-red-600 transition hover:bg-red-100 dark:bg-red-900/20 dark:text-red-400">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                    Remove from My Next Book
                                </button>
                            @elseif ($bookFull)
                                <span class="block text-center text-sm font-medium text-gray-400" title="Remove a story from My Next Book to add more">Book is full</span>
                            @elseif ($story->status !== 'completed')
                                <span class="block text-center text-sm font-medium text-gray-400">Not completed</span>
                            @else
                                <button type="button" wire:click="startAddToBook({{ $story->id }})" wire:loading.attr="disabled" wire:target="startAddToBook({{ $story->id }})" class="flex w-full cursor-pointer items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                                    <span wire:loading.remove wire:target="startAddToBook({{ $story->id }})" class="flex items-center justify-center gap-2">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                        Add to My Next Book
                                    </span>
                                    <span wire:loading wire:target="startAddToBook({{ $story->id }})" class="flex items-center justify-center gap-2">
                                        <span class="size-4 rounded-full border-2 border-white/40 border-t-white animate-spin inline-block"></span>
                                        Reviewing…
                                    </span>
                                </button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- My Next Book section (desktop) --}}
        <livewire:my-book />

    </div>

@if ($reviewingStoryId)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4" role="dialog" aria-modal="true">
        <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl dark:bg-zinc-800">
            @if ($reviewLoading)
                <div class="text-center">
                    <p class="text-lg font-semibold text-gray-900 dark:text-white">Reviewing your story…</p>
                    <p class="mt-2 text-sm text-gray-500">This may take a few moments.</p>
                </div>
            @elseif ($reviewImproving)
                <div class="text-center">
                    <p class="text-lg font-semibold text-gray-900 dark:text-white">Re-crafting your story…</p>
                    <p class="mt-2 text-sm text-gray-500">Applying your details.</p>
                </div>
            @else
                <h3 class="mb-1 text-lg font-bold text-gray-900 dark:text-white">Help make this story even better</h3>
                <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">Question {{ $reviewQuestionIndex + 1 }} of {{ count($reviewQuestions) }}</p>

                <p class="mb-3 text-base font-medium text-gray-800 dark:text-gray-200">{{ $reviewQuestion }}</p>

                <textarea wire:model="reviewAnswer" rows="3"
                    class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-gray-800 focus:border-blue-400 focus:outline-none focus:ring-1 focus:ring-blue-400 dark:border-zinc-600 dark:bg-zinc-700 dark:text-gray-200"
                    placeholder="Tap here and answer in your own words…"></textarea>
                @error('reviewAnswer')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror

                <div class="mt-4 flex flex-col gap-2">
                    <button type="button" wire:click="submitReviewAnswer" wire:loading.attr="disabled" class="flex w-full items-center justify-center gap-2 rounded-xl bg-green-600 px-4 py-3 text-lg font-bold text-white hover:bg-green-700 disabled:opacity-50">
                        <span wire:loading.remove wire:target="submitReviewAnswer">Add detail</span>
                        <span wire:loading wire:target="submitReviewAnswer" class="flex items-center gap-2">
                            <span class="size-5 rounded-full border-2 border-white/40 border-t-white animate-spin inline-block"></span>
                            Finishing up…
                        </span>
                    </button>
                    <button type="button" wire:click="skipReviewQuestion" wire:loading.attr="disabled" class="w-full rounded-xl border border-gray-200 px-4 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:border-zinc-600 dark:text-gray-300 dark:hover:bg-zinc-700">
                        <span wire:loading.remove wire:target="skipReviewQuestion">Skip this question</span>
                        <span wire:loading wire:target="skipReviewQuestion">Skipping…</span>
                    </button>
                    <button type="button" wire:click="skipReviewAndAdd" wire:loading.attr="disabled" class="w-full rounded-xl border border-gray-200 px-4 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:border-zinc-600 dark:text-gray-300 dark:hover:bg-zinc-700">
                        <span wire:loading.remove wire:target="skipReviewAndAdd">Skip & Add to My Next Book</span>
                        <span wire:loading wire:target="skipReviewAndAdd">Saving…</span>
                    </button>
                    <button type="button" wire:click="cancelReview" wire:loading.attr="disabled" wire:target="cancelReview" class="w-full rounded-xl px-4 py-3 text-sm font-medium text-gray-500 hover:bg-gray-100 disabled:opacity-50 dark:text-gray-400 dark:hover:bg-zinc-700">
                        Cancel
                    </button>
                </div>
            @endif
        </div>
    </div>
@endif
</div>
