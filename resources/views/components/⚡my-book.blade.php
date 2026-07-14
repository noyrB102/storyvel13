<?php

use App\Models\Book;
use App\Models\Story;
use Livewire\Component;

new class extends Component
{
    public bool $showPicker = false;
    public ?int $pickingSlot = null;

    public function getBook(): Book
    {
        $user = auth()->user();
        return $user->books()->where('status', 'draft')->latest()->first()
            ?? $user->books()->create(['title' => 'My Next Book', 'status' => 'draft']);
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

    public function addStory(int $storyId): void
    {
        $book = $this->getBook();
        $story = Story::where('user_id', auth()->id())->findOrFail($storyId);

        // Don't exceed 8 stories
        if ($book->stories()->count() >= 8) {
            return;
        }

        // Don't add duplicates
        if ($book->stories()->where('story_id', $storyId)->exists()) {
            return;
        }

        $book->stories()->attach($storyId, ['position' => $this->pickingSlot]);

        $this->closePicker();
    }

    public function removeStory(int $position): void
    {
        $book = $this->getBook();
        $book->stories()->wherePivot('position', $position)->detach();
    }

    public function with(): array
    {
        $book = $this->getBook();
        $bookStories = $book->stories()->get()->keyBy('pivot.position');

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
        ];
    }
};

?>

<div>
    {{-- ===== MOBILE "My Next Book" Section ===== --}}
    <div class="md:hidden w-full max-w-sm mt-10 text-left">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-bold text-gray-800 dark:text-white flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                </svg>
                My Next Book
            </h2>
            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $filledCount }} of 8</span>
        </div>

        {{-- Progress bar --}}
        <div class="w-full bg-gray-200 dark:bg-zinc-700 rounded-full h-2 mb-5">
            <div class="bg-blue-500 h-2 rounded-full transition-all duration-300" style="width: {{ ($filledCount / 8) * 100 }}%"></div>
        </div>

        {{-- 8 Slots --}}
        <div class="flex flex-col gap-3">
            @for ($i = 0; $i < 8; $i++)
                @if (isset($bookStories[$i]))
                    @php $story = $bookStories[$i]; @endphp
                    <div class="flex items-center gap-3 rounded-2xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-900/20">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-blue-500 text-sm font-bold text-white">{{ $i + 1 }}</span>
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-xl overflow-hidden">
                            @if ($story->cover_image_path)
                                <img src="{{ Storage::url($story->cover_image_path) }}" class="size-10 rounded-xl object-cover" />
                            @else
                                <div class="size-10 rounded-xl bg-blue-100 dark:bg-zinc-700 flex items-center justify-center">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="size-5 text-blue-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                                    </svg>
                                </div>
                            @endif
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $story->title ?? 'Untitled Story' }}</p>
                        </div>
                        <button
                            wire:click="removeStory({{ $i }})"
                            class="flex size-8 shrink-0 items-center justify-center rounded-full bg-white text-gray-400 shadow-sm border border-gray-200 hover:text-red-500 hover:border-red-200 dark:bg-zinc-800 dark:border-zinc-600"
                            title="Remove from book"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                @else
                    <button
                        wire:click="openPicker({{ $i }})"
                        class="flex items-center gap-3 rounded-2xl border-2 border-dashed border-gray-300 p-4 text-gray-400 hover:border-blue-400 hover:text-blue-500 transition-colors dark:border-zinc-600 dark:hover:border-blue-500"
                    >
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-gray-200 text-sm font-bold text-gray-500 dark:bg-zinc-700 dark:text-gray-400">{{ $i + 1 }}</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        <span class="text-base font-medium">Add a Story</span>
                    </button>
                @endif
            @endfor
        </div>

        @if ($filledCount === 8)
            <div class="mt-5 rounded-2xl bg-green-50 border border-green-200 p-4 text-center dark:bg-green-900/20 dark:border-green-800">
                <p class="text-base font-semibold text-green-700 dark:text-green-400">Your book is ready! All 8 stories selected.</p>
            </div>
        @endif
    </div>

    {{-- ===== DESKTOP "My Next Book" Section ===== --}}
    <div class="hidden md:block mb-10">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-6 text-blue-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                </svg>
                My Next Book
            </h2>
            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $filledCount }} of 8 stories</span>
        </div>

        {{-- Progress bar --}}
        <div class="w-full bg-gray-200 dark:bg-zinc-700 rounded-full h-2.5 mb-6">
            <div class="bg-blue-500 h-2.5 rounded-full transition-all duration-300" style="width: {{ ($filledCount / 8) * 100 }}%"></div>
        </div>

        {{-- Desktop grid of slots --}}
        <div class="grid grid-cols-4 gap-4">
            @for ($i = 0; $i < 8; $i++)
                @if (isset($bookStories[$i]))
                    @php $story = $bookStories[$i]; @endphp
                    <div class="relative group flex flex-col items-center rounded-2xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-900/20">
                        <button
                            wire:click="removeStory({{ $i }})"
                            class="absolute -top-2 -right-2 flex size-7 items-center justify-center rounded-full bg-white text-gray-400 shadow border border-gray-200 opacity-0 group-hover:opacity-100 transition-opacity hover:text-red-500 hover:border-red-200 dark:bg-zinc-800 dark:border-zinc-600"
                            title="Remove from book"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                        <span class="mb-2 flex size-7 items-center justify-center rounded-full bg-blue-500 text-xs font-bold text-white">{{ $i + 1 }}</span>
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

        @if ($filledCount === 8)
            <div class="mt-5 rounded-2xl bg-green-50 border border-green-200 p-4 text-center dark:bg-green-900/20 dark:border-green-800">
                <p class="text-base font-semibold text-green-700 dark:text-green-400">Your book is ready! All 8 stories selected.</p>
            </div>
        @endif
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
</div>
