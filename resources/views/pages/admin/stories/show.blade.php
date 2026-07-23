<x-layouts::writer :title="'Admin · ' . ($story->title ?? 'Story')">
    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <a href="{{ route('admin.stories.index') }}" wire:navigate class="inline-flex items-center gap-2 text-sm font-medium text-gray-500 transition hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                </svg>
                All Stories
            </a>
            <span class="w-fit rounded-full bg-violet-100 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-violet-700 dark:bg-violet-900/30 dark:text-violet-300">Admin read-only view</span>
        </div>

        <div class="mb-8 rounded-2xl border border-violet-100 bg-violet-50 p-5 dark:border-violet-900/40 dark:bg-violet-900/10">
            <p class="text-xs font-semibold uppercase tracking-wide text-violet-600 dark:text-violet-400">Story owner</p>
            <div class="mt-2 flex flex-col gap-1 sm:flex-row sm:items-center sm:gap-3">
                <p class="font-semibold text-gray-900 dark:text-white">{{ $story->user?->name ?? 'Unknown user' }}</p>
                <span class="hidden text-gray-300 sm:inline dark:text-zinc-600">·</span>
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ $story->user?->email ?? 'No email available' }}</p>
            </div>
        </div>

        @if ($story->cover_image_path)
            <div class="mb-8 flex justify-center overflow-hidden rounded-3xl bg-gray-100 dark:bg-zinc-800">
                <img src="{{ Storage::url($story->cover_image_path) }}" alt="{{ $story->title ?? 'Story cover' }}" class="max-h-96 w-auto max-w-full object-contain">
            </div>
        @endif

        <header class="mb-8">
            <div class="mb-4 flex flex-wrap items-center gap-2">
                <span class="rounded-full px-3 py-1 text-xs font-medium {{ $story->status === 'completed' ? 'bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400' : 'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400' }}">{{ ucfirst($story->status ?? 'unknown') }}</span>
                @if ($story->genre)
                    <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-medium text-blue-700 dark:bg-blue-900/20 dark:text-blue-400">{{ ucfirst($story->genre) }}</span>
                @endif
                <span class="text-xs text-gray-400">{{ $story->created_at->format('M j, Y · g:i A') }}</span>
                <span class="text-xs text-gray-400">{{ $story->is_private ? 'Private' : 'Public' }}</span>
            </div>
            <h1 class="text-3xl font-extrabold tracking-tight text-gray-900 dark:text-white sm:text-5xl">{{ $story->title ?? 'Untitled Story' }}</h1>
            <p class="mt-4 text-base font-medium text-gray-500 dark:text-gray-400">by {{ $story->author_name ?: ($story->user?->name ?? 'Unknown author') }}</p>
        </header>

        <div class="rounded-3xl border border-gray-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 sm:p-10">
            @if ($story->content)
                @php
                    $adminContent = $story->content;
                    if ($story->title) {
                        $adminContent = preg_replace('/^#+\s*' . preg_quote($story->title, '/') . '\s*(?:\n|$)/mi', '', $adminContent, 1);
                    }
                    $adminContent = preg_split('/^#+\s*Writing Coach.*$/mi', $adminContent)[0];
                    $adminContent = rtrim($adminContent);
                @endphp
                <article class="prose prose-lg prose-gray mx-auto max-w-prose dark:prose-invert prose-p:leading-8 prose-p:my-7 prose-headings:font-bold prose-strong:text-gray-900 dark:prose-strong:text-white">
                    {!! \Illuminate\Support\Str::markdown($adminContent) !!}
                </article>
            @else
                <p class="py-10 text-center text-gray-400">This story has no content yet.</p>
            @endif
        </div>

        <div class="mt-6 flex items-center justify-between text-xs text-gray-400">
            <span>{{ number_format(str_word_count($story->content ?? '')) }} words</span>
            <span>Story #{{ $story->id }}</span>
        </div>
    </div>
</x-layouts::writer>
