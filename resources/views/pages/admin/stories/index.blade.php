<x-layouts::writer title="Admin · All Stories">
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8 flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <div class="mb-2 flex items-center gap-2">
                    <span class="rounded-full bg-violet-100 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-violet-700 dark:bg-violet-900/30 dark:text-violet-300">Admin only</span>
                </div>
                <h1 class="text-3xl font-bold tracking-tight text-gray-900 dark:text-white">All Stories</h1>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Read stories from every StoryVel user. This view is read-only.</p>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white px-5 py-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Total stories</p>
                <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($stories->total()) }}</p>
            </div>
        </div>

        <form method="GET" action="{{ route('admin.stories.index') }}" class="mb-8 flex flex-col gap-3 sm:flex-row">
            <label class="relative flex-1">
                <span class="sr-only">Search all stories</span>
                <svg xmlns="http://www.w3.org/2000/svg" class="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35m2.1-5.4a7.5 7.5 0 1 1-15 0 7.5 7.5 0 0 1 15 0Z" />
                </svg>
                <input type="search" name="search" value="{{ $search }}" placeholder="Search by story, author, user, or email" class="w-full rounded-xl border border-gray-200 bg-white py-3 pl-12 pr-4 text-sm text-gray-900 shadow-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-100 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:focus:border-blue-500 dark:focus:ring-blue-900/30">
            </label>
            <button type="submit" class="rounded-xl bg-blue-600 px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">Search</button>
            @if ($search !== '')
                <a href="{{ route('admin.stories.index') }}" class="rounded-xl border border-gray-200 bg-white px-6 py-3 text-center text-sm font-semibold text-gray-600 transition hover:bg-gray-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-gray-300 dark:hover:bg-zinc-700">Clear</a>
            @endif
        </form>

        @if ($stories->isEmpty())
            <div class="rounded-3xl border border-dashed border-gray-300 bg-white px-6 py-20 text-center dark:border-zinc-700 dark:bg-zinc-800">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">No stories found</h2>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Try a different story title, author, user name, or email.</p>
            </div>
        @else
            <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($stories as $story)
                    <a href="{{ route('admin.stories.show', $story) }}" wire:navigate class="group overflow-hidden rounded-3xl border border-gray-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="flex h-44 items-center justify-center overflow-hidden bg-gradient-to-br from-blue-50 to-violet-50 dark:from-zinc-800 dark:to-zinc-700">
                            @if ($story->cover_image_path)
                                <img src="{{ Storage::url($story->cover_image_path) }}" alt="{{ $story->title ?? 'Story cover' }}" class="h-full w-full object-cover">
                            @else
                                <svg xmlns="http://www.w3.org/2000/svg" class="size-12 text-blue-300 dark:text-zinc-500" fill="none" viewBox="0 0 24 24" stroke-width="1.25" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                                </svg>
                            @endif
                        </div>
                        <div class="p-5">
                            <div class="mb-3 flex items-center justify-between gap-3">
                                <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $story->status === 'completed' ? 'bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400' : 'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400' }}">{{ ucfirst($story->status ?? 'unknown') }}</span>
                                <span class="text-xs text-gray-400">{{ $story->created_at->format('M j, Y') }}</span>
                            </div>
                            <h2 class="line-clamp-2 text-lg font-bold text-gray-900 transition group-hover:text-blue-600 dark:text-white dark:group-hover:text-blue-400">{{ $story->title ?? 'Untitled Story' }}</h2>
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">by {{ $story->author_name ?: ($story->user?->name ?? 'Unknown author') }}</p>
                            <div class="mt-4 border-t border-gray-100 pt-4 dark:border-zinc-700">
                                <p class="truncate text-xs font-medium text-gray-600 dark:text-gray-300">{{ $story->user?->name ?? 'Unknown user' }}</p>
                                <p class="mt-1 truncate text-xs text-gray-400">{{ $story->user?->email ?? 'No email' }}</p>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>

            <div class="mt-8">
                {{ $stories->links() }}
            </div>
        @endif
    </div>
</x-layouts::writer>
