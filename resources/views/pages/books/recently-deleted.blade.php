<x-layouts::writer :title="__('Recently Deleted')">
    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Recently Deleted</h1>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Restore a story whenever you need it, or permanently remove it.</p>
            </div>
            <a href="{{ route('books.index') }}" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-gray-200 dark:hover:bg-zinc-700">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                </svg>
                My Stories
            </a>
        </div>

        @if (session('success'))
            <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-400">
                {{ session('success') }}
            </div>
        @endif

        @if ($deletedStories->isEmpty() && $originals->isEmpty())
            <div class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-20 text-center dark:border-zinc-700 dark:bg-zinc-800">
                <div class="mb-4 flex size-14 items-center justify-center rounded-full bg-blue-50 dark:bg-zinc-700">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-7 text-blue-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5V6.75a3.375 3.375 0 0 0-3.375-3.375h-3.75A3.375 3.375 0 0 0 4.125 6.75v10.5a3.375 3.375 0 0 0 3.375 3.375h5.25m0-12.375a3.375 3.375 0 0 0-3.375 3.375v5.625a3.375 3.375 0 0 0 3.375 3.375h3.75a3.375 3.375 0 0 0 3.375-3.375v-3.375" />
                    </svg>
                </div>
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">No deleted stories</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Stories you delete will appear here.</p>
            </div>
        @else
            <div class="space-y-4">
                @foreach ($deletedStories as $story)
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <h2 class="truncate text-base font-semibold text-gray-900 dark:text-white">{{ $story->title ?? 'Untitled Story' }}</h2>
                                    <span class="shrink-0 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">Deleted</span>
                                </div>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Deleted {{ $story->deleted_at->diffForHumans() }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <form action="{{ route('books.recently-deleted.restore', $story->id) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-blue-500 px-3 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-600">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                                        </svg>
                                        Restore
                                    </button>
                                </form>
                                <form action="{{ route('books.recently-deleted.destroy', $story->id) }}" method="POST" onsubmit="return confirm('Permanently delete this story? This cannot be undone.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 px-3 py-2 text-sm font-medium text-red-600 transition-colors hover:bg-red-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-900/20">Delete permanently</button>
                                </form>
                            </div>
                        </div>
                    </div>
                @endforeach

                @foreach ($originals as $original)
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <h2 class="truncate text-base font-semibold text-gray-900 dark:text-white">{{ $original->title ?? 'Untitled Story' }}</h2>
                                    <span class="shrink-0 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">Saved original</span>
                                </div>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Saved {{ $original->created_at->diffForHumans() }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <form action="{{ route('books.recently-deleted.originals.restore', $original) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-blue-500 px-3 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-600">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                                        </svg>
                                        Restore
                                    </button>
                                </form>
                                <form action="{{ route('books.recently-deleted.originals.destroy', $original) }}" method="POST" onsubmit="return confirm('Permanently delete this story? This cannot be undone.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 px-3 py-2 text-sm font-medium text-red-600 transition-colors hover:bg-red-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-900/20">Delete permanently</button>
                                </form>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts::writer>
