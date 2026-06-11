<?php

use App\Models\Story;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $sortBy = 'newest';
    public ?string $filterGenre = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingSortBy(): void
    {
        $this->resetPage();
    }

    public function updatingFilterGenre(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $query = Story::where('is_private', false)
            ->where('status', 'completed');

        // Search
        if ($this->search) {
            $search = $this->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('author_name', 'like', "%{$search}%")
                    ->orWhere('genre', 'like', "%{$search}%");
            });
        }

        // Genre filter
        if ($this->filterGenre) {
            $query->where('genre', $this->filterGenre);
        }

        // Sort
        $query = match ($this->sortBy) {
            'oldest' => $query->oldest(),
            'a-z' => $query->orderByRaw('COALESCE(title, "") ASC'),
            'z-a' => $query->orderByRaw('COALESCE(title, "") DESC'),
            'author' => $query->orderBy('author_name', 'asc'),
            default => $query->latest(), // newest
        };

        return [
            'stories' => $query->paginate(12),
            'genres' => Story::where('is_private', false)
                ->whereNotNull('genre')
                ->distinct()
                ->pluck('genre')
                ->sort()
                ->values(),
        ];
    }
}

?>

<div class="min-h-screen bg-gray-50 dark:bg-zinc-950">
    <!-- Header -->
    <header class="border-b border-gray-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="mx-auto max-w-6xl px-4 py-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between">
                <!-- Logo -->
                <a href="{{ route('home') }}" class="flex items-center gap-2">
                    <x-app-logo-icon class="size-7 fill-current text-blue-500" />
                    <span class="text-lg font-semibold text-gray-900 dark:text-white">StoryVel</span>
                </a>

                <!-- Auth Links -->
                <div class="flex items-center gap-3">
                    @auth
                        <a href="{{ route('books.index') }}" class="rounded-lg bg-blue-500 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-600">
                            My Stories
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="text-sm font-medium text-gray-600 transition-colors hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">
                            Log in
                        </a>
                        <a href="{{ route('register') }}" class="rounded-lg bg-blue-500 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-600">
                            Get Started
                        </a>
                    @endauth
                </div>
            </div>
        </div>
    </header>

    <!-- Hero -->
    <div class="bg-white dark:bg-zinc-900">
        <div class="mx-auto max-w-6xl px-4 py-12 sm:px-6 lg:px-8">
            <div class="text-center">
                <h1 class="text-3xl font-bold text-gray-900 dark:text-white sm:text-4xl">Discover Stories</h1>
                <p class="mx-auto mt-3 max-w-xl text-base text-gray-500 dark:text-gray-400">
                    Browse stories from our community. Read, get inspired, or create your own.
                </p>
            </div>
        </div>
    </div>

    <!-- Filters & Search -->
    <div class="border-b border-gray-200 bg-gray-50/50 dark:border-zinc-700 dark:bg-zinc-900/50">
        <div class="mx-auto max-w-6xl px-4 py-4 sm:px-6 lg:px-8">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <!-- Search -->
                <div class="relative flex-1 max-w-md">
                    <svg xmlns="http://www.w3.org/2000/svg" class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.197 5.197a7.5 7.5 0 0 0 10.606 10.606Z" />
                    </svg>
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search stories, authors, genres..."
                        class="w-full rounded-lg border border-gray-200 bg-white py-2 pl-10 pr-4 text-sm text-gray-800 placeholder-gray-400 focus:border-blue-400 focus:outline-none focus:ring-1 focus:ring-blue-400 dark:border-zinc-600 dark:bg-zinc-800 dark:text-gray-200"
                    />
                </div>

                <!-- Sort & Filters -->
                <div class="flex flex-wrap items-center gap-2">
                    <!-- Sort Dropdown -->
                    <select
                        wire:model.live="sortBy"
                        class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 focus:border-blue-400 focus:outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-gray-300"
                    >
                        <option value="newest">Newest first</option>
                        <option value="oldest">Oldest first</option>
                        <option value="a-z">A-Z</option>
                        <option value="z-a">Z-A</option>
                        <option value="author">By Author</option>
                    </select>

                    <!-- Genre Filter -->
                    <select
                        wire:model.live="filterGenre"
                        class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 focus:border-blue-400 focus:outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-gray-300"
                    >
                        <option value="">All genres</option>
                        @foreach ($genres as $genre)
                            <option value="{{ $genre }}">{{ ucfirst($genre) }}</option>
                        @endforeach
                    </select>

                    <!-- New Story Button -->
                    @auth
                        <a href="{{ route('writer.create') }}" class="flex items-center gap-1.5 rounded-lg bg-blue-500 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-600">
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                            New Story
                        </a>
                    @else
                        <a href="{{ route('login') }}?redirect={{ route('writer.create') }}" class="flex items-center gap-1.5 rounded-lg bg-blue-500 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-600">
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                            New Story
                        </a>
                    @endauth
                </div>
            </div>
        </div>
    </div>

    <!-- Stories Grid -->
    <div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
        @if ($stories->isEmpty())
            <div class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-gray-300 bg-white py-20 text-center dark:border-zinc-700 dark:bg-zinc-800">
                <div class="mb-4 flex size-16 items-center justify-center rounded-full bg-gray-100 dark:bg-zinc-700">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-8 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                    </svg>
                </div>
                <h3 class="mb-1 text-base font-semibold text-gray-900 dark:text-white">No stories yet</h3>
                <p class="mb-6 max-w-xs text-sm text-gray-500 dark:text-gray-400">
                    Be the first to create a story!
                </p>
                @guest
                    <a href="{{ route('login') }}" class="rounded-lg bg-blue-500 px-5 py-2.5 text-sm font-medium text-white transition-colors hover:bg-blue-600">
                        Get Started
                    </a>
                @endguest
            </div>
        @else
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($stories as $story)
                    <a
                        href="{{ route('stories.public.show', $story) }}"
                        wire:navigate
                        class="group flex flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm transition-shadow hover:shadow-md dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <!-- Cover Image -->
                        <div class="relative h-44 w-full overflow-hidden bg-gradient-to-br from-blue-50 to-indigo-100 dark:from-zinc-700 dark:to-zinc-600">
                            @if ($story->cover_image_path)
                                <img
                                    src="{{ Storage::url($story->cover_image_path) }}"
                                    alt="{{ $story->title ?? 'Story cover' }}"
                                    class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                                />
                            @else
                                <div class="flex h-full items-center justify-center">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="size-12 text-blue-200 dark:text-zinc-500" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                                    </svg>
                                </div>
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
                @endforeach
            </div>

            <!-- Pagination -->
            <div class="mt-8">
                {{ $stories->links() }}
            </div>
        @endif
    </div>

    <!-- Footer -->
    <footer class="border-t border-gray-200 bg-white py-8 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="mx-auto max-w-6xl px-4 text-center text-sm text-gray-500 dark:text-gray-400 sm:px-6 lg:px-8">
            <p>&copy; {{ date('Y') }} StoryVel. Create and share your stories.</p>
        </div>
    </footer>
</div>
