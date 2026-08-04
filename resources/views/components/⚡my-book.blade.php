<?php

use App\Models\Book;
use App\Models\SiteSetting;
use App\Mail\SendStory;
use App\Models\Story;
use App\Services\StoryCopier;
use App\Services\StoryImprover;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public bool $showPicker = false;
    public ?int $pickingSlot = null;
    public bool $showEmailModal = false;
    public ?int $emailModalStoryId = null;

    public function getBook(): Book
    {
        $user = auth()->user();
        $book = $user->books()->where('status', 'draft')->latest()->first()
            ?? $user->books()->create(['title' => 'My Next Book', 'status' => 'draft']);

        // If the global target count was lowered, remove any now-hidden slots
        // so those stories can be re-selected in visible slots.
        $targetCount = (int) SiteSetting::get('book_target_count', 8);
        $book->stories()->newPivotQuery()
            ->where('position', '>=', $targetCount)
            ->delete();

        return $book;
    }

    public function openPicker(int $slot): void
    {
        $this->pickingSlot = $slot;
        $this->showPicker = true;
    }

    public function closePicker(): void
    {
        $this->showPicker = false;
        $this->pickingSlot = null;
    }

    #[On('open-email-modal')]
    public function openEmailModal(int $storyId): void
    {
        $this->emailModalStoryId = $storyId;
        $this->showEmailModal = true;
    }

    public function closeEmailModal(): void
    {
        $this->emailModalStoryId = null;
        $this->showEmailModal = false;
    }

    public function emailWithImage(): void
    {
        if ($this->emailModalStoryId === null) {
            return;
        }
        $this->emailStory($this->emailModalStoryId, true);
        $this->closeEmailModal();
    }

    public function emailWithoutImage(): void
    {
        if ($this->emailModalStoryId === null) {
            return;
        }
        $this->emailStory($this->emailModalStoryId, false);
        $this->closeEmailModal();
    }

    public function addStory(int $storyId): void
    {
        $book = $this->getBook();
        $story = Story::where('user_id', auth()->id())->findOrFail($storyId);

        // Don't exceed the configured target count
        if ($book->stories()->count() >= (int) SiteSetting::get('book_target_count', 8)) {
            return;
        }

        // Don't add duplicates
        if ($book->stories()->where('story_id', $storyId)->exists()) {
            return;
        }

        $book->stories()->attach($storyId, ['position' => $this->pickingSlot]);

        $this->closePicker();
        $this->dispatch('book-updated');
    }

    #[On('add-to-book')]
    public function addToFirstSlot(int $storyId): void
    {
        logger('my-book addToFirstSlot', ['storyId' => $storyId]);
        try {
            $book = $this->getBook();
            $targetCount = (int) SiteSetting::get('book_target_count', 8);
            $story = Story::where('user_id', auth()->id())->findOrFail($storyId);

            if ($book->stories()->count() >= $targetCount) {
                logger('my-book addToFirstSlot book full', ['storyId' => $storyId, 'count' => $book->stories()->count(), 'target' => $targetCount]);
                return;
            }

            if ($book->stories()->where('story_id', $storyId)->exists()) {
                logger('my-book addToFirstSlot already in book', ['storyId' => $storyId]);
                return;
            }

            $usedPositions = $book->stories()->pluck('position')->all();
            $this->pickingSlot = null;
            for ($i = 0; $i < $targetCount; $i++) {
                if (! in_array($i, $usedPositions, true)) {
                    $this->pickingSlot = $i;
                    break;
                }
            }

            if ($this->pickingSlot === null) {
                logger('my-book addToFirstSlot no slot', ['storyId' => $storyId]);
                return;
            }

            logger('my-book addToFirstSlot attaching', ['storyId' => $storyId, 'slot' => $this->pickingSlot]);
            $this->addStory($storyId);
            logger('my-book addToFirstSlot attached', ['storyId' => $storyId]);
        } catch (\Throwable $e) {
            logger('my-book addToFirstSlot error', ['storyId' => $storyId, 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function removeStory(int $position): void
    {
        $book = $this->getBook();
        $story = $book->stories()->wherePivot('position', $position)->first();

        if ($story && $story->email_sent_at === null) {
            $book->stories()->detach($story->id);
            $this->dispatch('book-updated');
        }
    }

    #[On('remove-from-book')]
    public function removeStoryById(int $storyId): void
    {
        $book = $this->getBook();
        $story = $book->stories()->where('stories.id', $storyId)->first();

        if ($story && $story->email_sent_at === null) {
            $book->stories()->detach($storyId);
            $this->dispatch('book-updated');
        }
    }

    public function emailStory(int $storyId, bool $includeImage = false): void
    {
        $story = Story::where('user_id', auth()->id())->findOrFail($storyId);

        if ($story->email_sent_at !== null) {
            return;
        }

        $story->content = app(StoryImprover::class)->polish($story->content);
        $story->save();

        $html = $this->copyHtml($story);
        $text = $this->copyText($story);

        Mail::to('bswanson@outlook.com')
            ->bcc('bswanson@outlook.com')
            ->send(new SendStory($story, $html, $text, $includeImage));

        $story->email_sent_at = now();
        $story->save();

        $this->dispatch('book-updated');
    }

    /**
     * Build the rich-text (12pt Arial) HTML payload used when copying a story
     * to the clipboard for pasting into an email.
     */
    public function copyHtml(Story $story, bool $includeImage = false): string
    {
        abort_if($story->user_id !== auth()->id(), 403);

        return app(StoryCopier::class)->html($story, $includeImage);
    }

    /**
     * Plain-text fallback for the clipboard copy.
     */
    public function copyText(Story $story): string
    {
        abort_if($story->user_id !== auth()->id(), 403);

        return app(StoryCopier::class)->text($story);
    }

    public function copyPayload(int $storyId, bool $includeImage = false): array
    {
        $story = Story::where('user_id', auth()->id())->findOrFail($storyId);

        return [
            'html' => $this->copyHtml($story, $includeImage),
            'text' => $this->copyText($story),
        ];
    }

    public function with(): array
    {
        $book = $this->getBook();
        $bookStories = $book->stories()
            ->where('stories.user_id', auth()->id())
            ->get()
            ->keyBy('pivot.position');

        // Stories available to add (user's completed stories not already in book)
        $usedIds = $bookStories->pluck('id')->toArray();
        $availableStories = Story::where('user_id', auth()->id())
            ->where('status', 'completed')
            ->whereNotIn('id', $usedIds)
            ->latest()
            ->get();

        return [
            'book' => $book,
            'bookStories' => $bookStories,
            'availableStories' => $availableStories,
            'filledCount' => $bookStories->count(),
            'targetCount' => (int) SiteSetting::get('book_target_count', 8),
            'showEmailModal' => $this->showEmailModal,
            'emailModalStoryId' => $this->emailModalStoryId,
        ];
    }
};

?>

<div wire:poll.10s>
    {{-- ===== MOBILE "My Next Book" Section ===== --}}
    <div x-data="{ open: true }" class="mt-10 w-full min-w-0 max-w-sm text-left md:hidden">
        <div @click="open = !open" class="mb-4 flex cursor-pointer items-center justify-between">
            <div>
                <h2 class="text-lg font-bold text-gray-800 dark:text-white">
                    My Next Book
                </h2>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $filledCount }} of {{ $targetCount }} <span class="text-xs text-gray-400">- Click to expand or collapse this section</span></p>
            </div>
            <svg xmlns="http://www.w3.org/2000/svg" class="size-5 shrink-0 text-gray-400 transition-transform" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" :class="{'rotate-180': !open}">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" />
            </svg>
        </div>
        <div x-show="open">

        @if (auth()->user()?->email === 'loran')
            <p class="mb-3 text-sm text-gray-600 dark:text-gray-300">Additionally, Ann will include the other 22 stories she has already done.  </p>

        @endif

        {{-- Progress bar --}}
        <div class="w-full bg-gray-200 dark:bg-zinc-700 rounded-full h-2 mb-5">
            <div class="bg-blue-500 h-2 rounded-full transition-all duration-300" style="width: {{ ($filledCount / $targetCount) * 100 }}%"></div>
        </div>

        {{-- Slots --}}
        <div class="flex flex-col gap-3">
            @for ($i = 0; $i < $targetCount; $i++)
                @if (isset($bookStories[$i]))
                    @php $story = $bookStories[$i]; @endphp
                    <div class="grid min-w-0 grid-cols-[auto_minmax(0,1fr)_auto] items-start gap-x-3 gap-y-2 rounded-2xl border border-blue-200 bg-blue-50 p-4 max-[360px]:grid-cols-[auto_minmax(0,1fr)] dark:border-blue-800 dark:bg-blue-900/20">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-blue-500 text-sm font-bold text-white">{{ $i + 1 }}</span>
                        <div class="flex min-w-0 items-start gap-3">
                            <div class="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-xl">
                                @if ($story->cover_image_path)
                                    <img src="{{ Storage::url($story->cover_image_path) }}" class="size-10 rounded-xl object-cover" />
                                @else
                                    <div class="flex size-10 items-center justify-center rounded-xl bg-blue-100 dark:bg-zinc-700">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="size-5 text-blue-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                                        </svg>
                                    </div>
                                @endif
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="break-words text-sm font-semibold leading-snug text-gray-900 [overflow-wrap:anywhere] dark:text-white">{{ $story->title ?? 'Untitled Story' }}</p>
                                <button
                                    type="button"
                                    x-data="{ copied: false }"
                                    @click.stop="
                                        (async () => {
                                            let payload;
                                            try {
                                                payload = await $wire.copyPayload({{ $story->id }});
                                            } catch (e) {
                                                alert('Could not load story.');
                                                return;
                                            }
                                            let success = false;
                                            if (navigator.clipboard && typeof ClipboardItem !== 'undefined') {
                                                try {
                                                    await navigator.clipboard.write([
                                                        new ClipboardItem({
                                                            'text/html': new Blob([payload.html], {type: 'text/html'}),
                                                            'text/plain': new Blob([payload.text], {type: 'text/plain'}),
                                                        })
                                                    ]);
                                                    success = true;
                                                } catch (e) {}
                                            }
                                            if (!success && navigator.clipboard) {
                                                try {
                                                    await navigator.clipboard.writeText(payload.text);
                                                    success = true;
                                                } catch (e) {}
                                            }
                                            if (!success) {
                                                try {
                                                    const ta = document.createElement('textarea');
                                                    ta.value = payload.text; ta.style.position = 'fixed'; ta.style.left = '-9999px'; ta.style.opacity = '0';
                                                    document.body.appendChild(ta); ta.select();
                                                    success = document.execCommand('copy');
                                                    document.body.removeChild(ta);
                                                } catch (e3) {}
                                            }
                                            if (success) { copied = true; setTimeout(() => copied = false, 1500); }
                                            else { alert('Could not copy to clipboard.'); }
                                        })()
                                    "
                                    class="mt-2 inline-flex min-h-10 w-full cursor-pointer items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 active:bg-blue-800 dark:bg-blue-500 dark:hover:bg-blue-600"
                                    title="Copy story to clipboard"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0 0 13.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 0 1-.75.75H9a.75.75 0 0 1-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 0 1 1.927-.184" />
                                    </svg>
                                    <span x-text="copied ? 'Copied' : 'Copy'"></span>
                                </button>

                            </div>
                        </div>
                        @if (!$story->email_sent_at)
                        <button
                            type="button"
                            wire:click="removeStory({{ $i }})"
                            class="flex size-11 shrink-0 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-400 shadow-sm hover:border-red-200 hover:text-red-500 max-[360px]:col-start-2 max-[360px]:row-start-2 max-[360px]:justify-self-end dark:border-zinc-600 dark:bg-zinc-800"
                            title="Remove from book"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                        @endif
                    </div>
                @else
                    <button
                        wire:click="openPicker({{ $i }})"
                        class="flex min-h-16 min-w-0 flex-wrap items-center gap-3 rounded-2xl border-2 border-dashed border-gray-300 p-4 text-left text-gray-400 transition-colors hover:border-blue-400 hover:text-blue-500 dark:border-zinc-600 dark:hover:border-blue-500"
                    >
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-gray-200 text-sm font-bold text-gray-500 dark:bg-zinc-700 dark:text-gray-400">{{ $i + 1 }}</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        <span class="min-w-0 flex-1 break-words text-base font-medium">Add a Story</span>
                    </button>
                @endif
            @endfor
        </div>

        @if ($filledCount === $targetCount)
            <div class="mt-5 rounded-2xl bg-green-50 border border-green-200 p-4 text-center dark:bg-green-900/20 dark:border-green-800">
                <p class="text-base font-semibold text-green-700 dark:text-green-400">Your book is ready! All {{ $targetCount }} stories selected.</p>
            </div>
        @endif
        </div>
    </div>

    {{-- ===== DESKTOP "My Next Book" Section ===== --}}
    <div x-data="{ open: true }" class="hidden md:block mb-10">
        <div @click="open = !open" class="mb-4 flex cursor-pointer items-center justify-between">
            <div>
                <h2 class="text-xl font-bold text-gray-900 dark:text-white">
                    My Next Book
                </h2>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $filledCount }} of {{ $targetCount }} stories <span class="text-xs text-gray-400">- Click to expand or collapse this section</span></p>
            </div>
            <svg xmlns="http://www.w3.org/2000/svg" class="size-5 shrink-0 text-gray-400 transition-transform" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" :class="{'rotate-180': !open}">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" />
            </svg>
        </div>
        <div x-show="open">

        @if (auth()->user()?->email === 'loran')
             <p class="mb-3 text-sm text-gray-600 dark:text-gray-300">Additionally, Ann will include the other 22 stories she has already done.  </p>

        @endif

        {{-- Progress bar --}}
        <div class="w-full bg-gray-200 dark:bg-zinc-700 rounded-full h-2.5 mb-6">
            <div class="bg-blue-500 h-2.5 rounded-full transition-all duration-300" style="width: {{ ($filledCount / $targetCount) * 100 }}%"></div>
        </div>

        {{-- Desktop grid of slots --}}
        <div class="grid grid-cols-4 gap-4">
            @for ($i = 0; $i < $targetCount; $i++)
                @if (isset($bookStories[$i]))
                    @php $story = $bookStories[$i]; @endphp
                    <div class="relative group flex flex-col items-center rounded-2xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-900/20">
                        @if (!$story->email_sent_at)
                        <button
                            wire:click="removeStory({{ $i }})"
                            class="absolute -top-2 -right-2 flex size-7 items-center justify-center rounded-full bg-white text-gray-400 shadow border border-gray-200 opacity-0 group-hover:opacity-100 transition-opacity hover:text-red-500 hover:border-red-200 dark:bg-zinc-800 dark:border-zinc-600"
                            title="Remove from book"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                        @endif
                        <div class="mb-2 flex items-center gap-1.5">
                            <span class="flex size-7 items-center justify-center rounded-full bg-blue-500 text-xs font-bold text-white">{{ $i + 1 }}</span>
                            <button
                                type="button"
                                x-data="{ copied: false }"
                                @click.stop="
                                    (async () => {
                                        let payload;
                                        try {
                                            payload = await $wire.copyPayload({{ $story->id }});
                                        } catch (e) {
                                            alert('Could not load story.');
                                            return;
                                        }
                                        let success = false;
                                        if (navigator.clipboard && typeof ClipboardItem !== 'undefined') {
                                            try {
                                                await navigator.clipboard.write([
                                                    new ClipboardItem({
                                                        'text/html': new Blob([payload.html], {type: 'text/html'}),
                                                        'text/plain': new Blob([payload.text], {type: 'text/plain'}),
                                                    })
                                                ]);
                                                success = true;
                                            } catch (e) {}
                                        }
                                        if (!success && navigator.clipboard) {
                                            try {
                                                await navigator.clipboard.writeText(payload.text);
                                                success = true;
                                            } catch (e) {}
                                        }
                                        if (!success) {
                                            try {
                                                const ta = document.createElement('textarea');
                                                ta.value = payload.text; ta.style.position = 'fixed'; ta.style.left = '-9999px'; ta.style.opacity = '0';
                                                document.body.appendChild(ta); ta.select();
                                                success = document.execCommand('copy');
                                                document.body.removeChild(ta);
                                            } catch (e3) {}
                                        }
                                        if (success) { copied = true; setTimeout(() => copied = false, 1500); }
                                        else { alert('Could not copy to clipboard.'); }
                                    })()
                                "
                                class="inline-flex cursor-pointer items-center gap-1 rounded-full bg-blue-100 px-2.5 py-1 text-xs font-semibold text-blue-700 shadow-sm ring-1 ring-blue-200 hover:bg-blue-200 dark:bg-blue-900 dark:text-blue-100 dark:ring-blue-700 dark:hover:bg-blue-800"
                                title="Copy story to clipboard"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0 0 13.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 0 1-.75.75H9a.75.75 0 0 1-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 0 1 1.927-.184" />
                                </svg>
                                <span x-text="copied ? '✓' : 'Copy'"></span>
                            </button>

                        </div>
                        <div class="w-full aspect-square rounded-xl overflow-hidden mb-2">
                            @if ($story->cover_image_path)
                                <img src="{{ Storage::url($story->cover_image_path) }}" class="w-full h-full object-cover" />
                            @else
                                <div class="w-full h-full bg-blue-100 dark:bg-zinc-700 flex items-center justify-center">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="size-8 text-blue-300" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                                    </svg>
                                </div>
                            @endif
                        </div>
                        <p class="text-xs font-semibold text-gray-900 dark:text-white text-center truncate w-full">{{ $story->title ?? 'Untitled' }}</p>
                    </div>
                @else
                    <button
                        wire:click="openPicker({{ $i }})"
                        class="flex flex-col items-center justify-center rounded-2xl border-2 border-dashed border-gray-300 p-4 text-gray-400 hover:border-blue-400 hover:text-blue-500 transition-colors aspect-square dark:border-zinc-600 dark:hover:border-blue-500"
                    >
                        <span class="mb-2 flex size-7 items-center justify-center rounded-full bg-gray-200 text-xs font-bold text-gray-500 dark:bg-zinc-700 dark:text-gray-400">{{ $i + 1 }}</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-8 mb-1" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        <span class="text-xs font-medium">Add Story</span>
                    </button>
                @endif
            @endfor
        </div>

        @if ($filledCount === $targetCount)
            <div class="mt-5 rounded-2xl bg-green-50 border border-green-200 p-4 text-center dark:bg-green-900/20 dark:border-green-800">
                <p class="text-base font-semibold text-green-700 dark:text-green-400">Your book is ready! All {{ $targetCount }} stories selected.</p>
            </div>
        @endif
        </div>
    </div>

    {{-- ===== Story Picker Modal ===== --}}
    @if ($showPicker)
        <div
            class="fixed inset-0 z-50 flex items-end md:items-center justify-center"
            x-data
            x-on:keydown.escape.window="$wire.closePicker()"
        >
            {{-- Backdrop --}}
            <div class="absolute inset-0 bg-black/40" wire:click="closePicker"></div>

            {{-- Modal --}}
            <div class="relative w-full max-w-md max-h-[80vh] bg-white dark:bg-zinc-900 rounded-t-3xl md:rounded-3xl shadow-2xl overflow-hidden flex flex-col">
                {{-- Header --}}
                <div class="flex items-center justify-between px-6 py-5 border-b border-gray-200 dark:border-zinc-700">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white">Choose a Story</h3>
                    <button wire:click="closePicker" class="flex size-9 items-center justify-center rounded-full bg-gray-100 text-gray-500 hover:bg-gray-200 dark:bg-zinc-800 dark:hover:bg-zinc-700">
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                {{-- Story list --}}
                <div class="flex-1 overflow-y-auto px-4 py-4">
                    @if ($availableStories->isEmpty())
                        <div class="py-12 text-center text-gray-400">
                            <p class="text-base">No more stories available.</p>
                            <p class="text-sm mt-1">Write more stories to fill your book!</p>
                        </div>
                    @else
                        <div class="flex flex-col gap-2">
                            @foreach ($availableStories as $story)
                                <button
                                    wire:click="addStory({{ $story->id }})"
                                    class="flex items-center gap-4 rounded-2xl border border-gray-200 bg-white p-4 text-left shadow-sm hover:bg-blue-50 hover:border-blue-200 transition-colors dark:border-zinc-700 dark:bg-zinc-800 dark:hover:bg-zinc-700"
                                >
                                    <div class="flex size-12 shrink-0 items-center justify-center rounded-2xl overflow-hidden">
                                        @if ($story->cover_image_path)
                                            <img src="{{ Storage::url($story->cover_image_path) }}" class="size-12 rounded-2xl object-cover" />
                                        @else
                                            <div class="size-12 rounded-2xl bg-blue-50 dark:bg-zinc-700 flex items-center justify-center">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="size-6 text-blue-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                                                </svg>
                                            </div>
                                        @endif
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-base font-semibold text-gray-900 dark:text-white">{{ $story->title ?? 'Untitled Story' }}</p>
                                        <p class="text-sm text-gray-400">{{ $story->created_at->format('M j, Y') }}</p>
                                    </div>
                                    <svg xmlns="http://www.w3.org/2000/svg" class="size-5 shrink-0 text-blue-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                    </svg>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    @if ($showEmailModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" wire:key="email-modal">
            <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl dark:bg-zinc-800">
                <h3 class="mb-2 text-lg font-bold text-gray-900 dark:text-white">Publish this story</h3>
                <p class="mb-6 text-sm text-gray-600 dark:text-gray-300">Include the cover image when publishing?</p>
                <div class="space-y-3">
                    <button type="button" wire:click="emailWithImage" wire:loading.attr="disabled" wire:target="emailWithImage" class="flex w-full items-center justify-center gap-2 rounded-xl bg-green-600 px-4 py-3 text-sm font-bold text-white shadow-sm hover:bg-green-700 disabled:opacity-75 dark:bg-green-500 dark:hover:bg-green-600">
                        <span wire:loading.remove wire:target="emailWithImage">Publish with cover image</span>
                        <span wire:loading wire:target="emailWithImage" class="flex items-center justify-center gap-2">
                            <span class="size-4 rounded-full border-2 border-white/40 border-t-white animate-spin inline-block"></span>
                            <span>Publishing…</span>
                        </span>
                    </button>
                    <button type="button" wire:click="emailWithoutImage" wire:loading.attr="disabled" wire:target="emailWithoutImage" class="flex w-full items-center justify-center gap-2 rounded-xl bg-green-100 px-4 py-3 text-sm font-semibold text-green-700 ring-1 ring-green-200 hover:bg-green-200 dark:bg-green-900 dark:text-green-100 dark:ring-green-700 dark:hover:bg-green-800">
                        <span wire:loading.remove wire:target="emailWithoutImage">Publish story only</span>
                        <span wire:loading wire:target="emailWithoutImage" class="flex items-center justify-center gap-2">
                            <span class="size-4 rounded-full border-2 border-green-700/40 border-t-green-700 animate-spin inline-block"></span>
                            <span>Publishing…</span>
                        </span>
                    </button>
                    <button type="button" wire:click="closeEmailModal" class="w-full rounded-xl border border-gray-300 px-4 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-zinc-600 dark:text-gray-300 dark:hover:bg-zinc-700">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
