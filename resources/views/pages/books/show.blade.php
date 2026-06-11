<x-layouts::writer :title="$story->title ?? 'Story'">
    <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">

        <!-- Top bar: back + actions -->
        <div class="mb-8 flex items-center justify-between gap-4">
            <a href="{{ route('books.index') }}" wire:navigate
               class="inline-flex items-center gap-1.5 text-sm text-gray-400 transition-colors hover:text-gray-600 dark:hover:text-gray-300">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                </svg>
                My Stories
            </a>

            <div class="flex items-center gap-2">
                <a href="{{ route('books.edit', $story) }}" wire:navigate
                   class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-gray-300 dark:hover:bg-zinc-700"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" />
                    </svg>
                    Edit
                </a>

                <!-- Download Dropdown -->
                <div class="relative" x-data="{ open: false }">
                    <button type="button"
                        @click="open = !open"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-gray-300 dark:hover:bg-zinc-700"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M7.5 12 12 16.5m0 0L16.5 12m-4.5 4.5V3" />
                        </svg>
                        Download
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                    </button>
                    <div x-show="open" @click.away="open = false" class="absolute right-0 top-full z-10 mt-1 w-40 rounded-lg border border-gray-200 bg-white py-1 shadow-lg dark:border-zinc-600 dark:bg-zinc-800">
                        <a href="{{ route('books.download.pdf', $story) }}" class="flex items-center gap-2 px-4 py-2 text-xs text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-zinc-700">
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-4 text-red-500" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M7 11.5v-1h3v1H7zm0 3v-1h3v1H7zm0 3v-1h3v1H7zm5-6v-1h3v1h-3zm0 3v-1h3v1h-3zm0 3v-1h3v1h-3z"/>
                                <path fill-rule="evenodd" d="M4 2a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8l-6-6H4zm14 18H6a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h7v5h5v9a1 1 0 0 1-1 1z" clip-rule="evenodd"/>
                            </svg>
                            PDF
                        </a>
                        <a href="{{ route('books.download.word', $story) }}" class="flex items-center gap-2 px-4 py-2 text-xs text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-zinc-700">
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-4 text-blue-500" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6z"/>
                                <path fill="#fff" d="M12 11h4v2h-4v-2zm0 3h4v2h-4v-2zm-3-3h2v2H9v-2zm0 3h2v2H9v-2z"/>
                                <path d="M14 2v6h6"/>
                            </svg>
                            Word
                        </a>
                    </div>
                </div>

                <form action="{{ route('books.destroy', $story) }}" method="POST"
                      onsubmit="return confirm('Delete \'{{ addslashes($story->title ?? 'this story') }}\'? This cannot be undone.')">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-medium text-red-500 transition-colors hover:bg-red-50 dark:border-zinc-600 dark:bg-zinc-800 dark:hover:bg-red-900/20"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                        </svg>
                        Delete
                    </button>
                </form>
            </div>
        </div>

        <!-- Cover Image -->
        @if ($story->cover_image_path)
            <div class="mb-8 overflow-hidden rounded-2xl shadow-md">
                <img
                    src="{{ Storage::url($story->cover_image_path) }}"
                    alt="{{ $story->title ?? 'Story cover' }}"
                    class="h-64 w-full object-cover sm:h-80"
                />
            </div>
        @endif

        <!-- Header -->
        <div class="mb-8">
            <div class="mb-3 flex flex-wrap items-center gap-2">
                @if ($story->genre)
                    <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-medium text-blue-600 dark:bg-blue-900/30 dark:text-blue-400">
                        {{ ucfirst($story->genre) }}
                    </span>
                @endif
                <span class="text-xs text-gray-400">{{ $story->created_at->format('M j, Y') }}</span>
            </div>

            <h1 class="mb-4 text-3xl font-extrabold tracking-tight text-gray-900 dark:text-white sm:text-4xl lg:text-5xl">
                {{ $story->title ?? 'Untitled Story' }}
            </h1>

            @if ($story->author_name)
                <p class="mb-3 text-sm font-medium text-gray-500 dark:text-gray-400">
                    {{ $story->author_name }}
                </p>
            @endif

            <div class="mb-4 h-1 w-20 rounded-full bg-gradient-to-r from-blue-500 to-blue-300 dark:from-blue-400 dark:to-blue-600"></div>

            <p class="text-sm italic text-gray-500 dark:text-gray-400 line-clamp-3">
                {{ Str::limit($story->prompt, 320) }}
            </p>
        </div>

        <!-- Divider -->
        <hr class="mb-8 border-gray-200 dark:border-zinc-700" />

        @php
            $storyBody    = $story->content ?? '';
            $coachNote    = null;
            $coachStrongs = [];
            $coachBullets = [];
            if ($story->format === 'author_voice' && $storyBody) {
                // Split on the Writing Coach Note heading (## or plain text)
                if (preg_match('/^(?:##?\s*)?Writing Coach Note\b/mi', $storyBody, $m, PREG_OFFSET_CAPTURE)) {
                    $offset    = $m[0][1];
                    $coachNote = trim(substr($storyBody, $offset));
                    $storyBody = trim(substr($storyBody, 0, $offset));
                    // Extract bullet lines from the coach note
                    preg_match_all('/^[\-\*]\s+(.+)$/m', $coachNote, $bullets);
                    $coachBullets = $bullets[1] ?? [];
                    // Extract bold section headings (e.g. **What's already strong:**)
                    preg_match_all('/\*\*(.+?)\*\*/m', $coachNote, $strongs);
                    $coachStrongs = array_unique($strongs[1] ?? []);
                }
            }
        @endphp

        <!-- Full Story Content -->
        <div class="rounded-2xl border border-gray-200 bg-white p-8 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
            @if ($storyBody)
                <article class="story-content prose prose-base prose-gray mx-auto max-w-prose dark:prose-invert
                            prose-headings:font-bold prose-headings:text-gray-900 prose-headings:tracking-tight
                            prose-p:text-gray-700 prose-p:leading-[1.8] prose-p:my-10
                            prose-strong:text-gray-900 prose-strong:font-semibold
                            prose-blockquote:border-l-4 prose-blockquote:border-blue-400 prose-blockquote:pl-4 prose-blockquote:italic
                            prose-a:text-blue-600 prose-a:no-underline hover:prose-a:underline
                            dark:prose-headings:text-white dark:prose-p:text-gray-300 dark:prose-strong:text-white
                            dark:prose-blockquote:border-blue-500">
                    {!! Str::markdown($storyBody) !!}
                </article>

                <style>
                    .story-content > p {
                        margin-bottom: 2.5rem !important;
                    }
                    .story-content > p:first-of-type::first-letter {
                        float: left;
                        font-size: 3.5em;
                        line-height: 0.8;
                        margin-right: 0.1em;
                        margin-top: 0.05em;
                        font-weight: 700;
                        color: inherit;
                    }
                    .story-content > p:first-of-type {
                        font-size: 1.05em;
                        margin-bottom: 2.5rem !important;
                    }
                </style>
            @elseif ($story->content && !$storyBody)
                {{-- Edge case: entire content was just a coach note with no story body --}}
                <p class="py-8 text-sm text-gray-400">The story body could not be separated from the coach note. Check the raw content in Edit.</p>
            @elseif ($story->status === 'generating')
                <div class="flex items-center gap-3 py-8 text-sm text-gray-500">
                    <div class="size-5 rounded-full border-2 border-blue-200 border-t-blue-500 animate-spin"></div>
                    Still generating… refresh in a moment.
                </div>
            @elseif ($story->status === 'failed')
                <p class="py-8 text-sm text-red-500">Generation failed. Please try creating this story again.</p>
            @else
                <p class="py-8 text-sm text-gray-400">No content yet.</p>
            @endif
        </div>

        <!-- Actions -->
        <div class="mt-6 flex items-center justify-between">
            <a
                href="{{ route('writer.create') }}"
                wire:navigate
                class="rounded-lg bg-blue-500 px-5 py-2.5 text-sm font-medium text-white transition-colors hover:bg-blue-600"
            >
                Write another
            </a>

            @if ($story->isCompleted())
                <div class="text-xs text-gray-400">
                    {{ str_word_count($story->content) }} words
                </div>
            @endif
        </div>

        <!-- Writing Coach Note (author_voice only) -->
        @if ($coachNote && $story->isCompleted())
            <div class="mt-8 rounded-2xl border-2 border-amber-200 bg-amber-50 p-6 dark:border-amber-800/50 dark:bg-amber-900/10"
                 x-data="{ expanded: true }">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <span class="text-xl">✍️</span>
                        <div>
                            <h3 class="text-sm font-semibold text-amber-800 dark:text-amber-300">Writing Coach Note</h3>
                            <p class="text-xs text-amber-600 dark:text-amber-400">Click a suggestion to bring it into the chat below</p>
                        </div>
                    </div>
                    <button @click="expanded = !expanded" class="text-amber-400 hover:text-amber-600">
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-5 transition-transform" :class="expanded ? '' : 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5" />
                        </svg>
                    </button>
                </div>

                <div x-show="expanded" x-transition class="mt-4">
                    {{-- Render the coach note prose --}}
                    <div class="prose prose-sm prose-amber max-w-none
                                prose-p:text-amber-900 prose-headings:text-amber-800 prose-strong:text-amber-900
                                prose-li:text-amber-800
                                dark:prose-p:text-amber-200 dark:prose-headings:text-amber-300 dark:prose-strong:text-amber-200
                                dark:prose-li:text-amber-300">
                        {!! Str::markdown($coachNote) !!}
                    </div>

                    {{-- Clickable suggestion chips --}}
                    @if (count($coachBullets))
                        <div class="mt-5 border-t border-amber-200 pt-4 dark:border-amber-800/40">
                            <p class="mb-2.5 text-xs font-medium text-amber-700 dark:text-amber-400">Work on a suggestion:</p>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($coachBullets as $bullet)
                                    <button
                                        type="button"
                                        onclick="window.dispatchEvent(new CustomEvent('coach-suggestion', { detail: { text: {{ json_encode('Coach suggestion: ' . $bullet) }} } }))"
                                        class="rounded-full border border-amber-300 bg-white px-3 py-1.5 text-left text-xs text-amber-700 transition-colors hover:border-amber-400 hover:bg-amber-100 dark:border-amber-700 dark:bg-zinc-800 dark:text-amber-400 dark:hover:border-amber-500 cursor-pointer"
                                    >
                                        {{ Str::limit($bullet, 80) }}
                                    </button>
                                @endforeach
                                <button
                                    type="button"
                                    onclick="window.dispatchEvent(new CustomEvent('coach-suggestion', { detail: { text: 'Based on your coaching notes, what should I focus on next to improve this story?' } }))"
                                    class="rounded-full border border-amber-300 bg-amber-500 px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-amber-600 dark:border-amber-600 cursor-pointer"
                                >
                                    Ask what to focus on next →
                                </button>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        <!-- Done button - Prominent placement before chat -->
        @if ($story->isCompleted())
            <div class="mt-8">
                <a href="{{ route('books.index') }}" class="flex w-full items-center justify-center gap-2 rounded-xl bg-green-500 px-6 py-4 text-lg font-semibold text-white shadow-md transition-colors hover:bg-green-600">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                    </svg>
                    I'm Happy with My Story — Done! Go to My Stories
                </a>
                <p class="mt-2 text-center text-sm text-gray-500 dark:text-gray-400">Or continue chatting with your writing coach below</p>
            </div>
        @endif

        <!-- Continue the conversation -->
        @if ($story->isCompleted())
            <div
                class="mt-6"
                x-data
                x-on:story-content-updated.window="window.location.reload()"
            >
                @if ($story->format === 'author_voice')
                    <h2 class="mb-1 text-lg font-semibold text-gray-900 dark:text-white">Continue with your writing coach</h2>
                    <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">Click a suggestion above, answer the coach's questions, or ask it to help you write a specific scene.</p>
                @else
                    <h2 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Continue with Claude</h2>
                @endif
                <livewire:story-chat :story="$story" />
            </div>
        @endif

    </div>
</x-layouts::writer>
