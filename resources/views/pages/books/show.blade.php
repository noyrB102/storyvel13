<x-layouts::writer :title="$story->title ?? 'Story'">
    @push('styles')
        <style>
            @media print {
                @page { margin: 0.6in 0.6in 0.4in 1.25in; }
            }
        </style>
    @endpush
    <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">

        <!-- Top bar: back + actions -->
        <div class="no-print mb-8 flex items-center justify-between gap-4">
            <a href="{{ route('books.index') }}" wire:navigate
               class="inline-flex items-center gap-1.5 text-sm text-gray-400 transition-colors hover:text-gray-600 dark:hover:text-gray-300">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                </svg>
                My Stories
            </a>

            <div class="flex items-center gap-2">
                <a href="{{ route('books.edit', $story) }}" wire:navigate
                   class="inline-flex items-center gap-1.5 rounded-lg bg-blue-500 px-4 py-2.5 text-sm font-medium text-white transition-colors hover:bg-blue-600"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
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

                <form action="{{ route('books.destroy', $story) }}" method="POST" class="hidden sm:block"
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
            <div class="cover-image-wrap mb-8 flex justify-center">
                <div class="w-fit overflow-hidden rounded-3xl">
                    <img
                        src="{{ Storage::url($story->cover_image_path) }}?v={{ Storage::disk('public')->lastModified($story->cover_image_path) }}"
                        alt="{{ $story->title ?? 'Story cover' }}"
                        class="h-64 w-auto max-w-full object-contain sm:h-80"
                    />
                </div>
            </div>
        @endif

        <!-- Header -->
        <div class="mb-8 no-print">
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

        </div>

        <!-- Divider -->
        <hr class="no-print mb-8 border-gray-200 dark:border-zinc-700" />

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

            // Remove the first heading that duplicates the story title so it appears once
            if ($story->title && $storyBody) {
                $storyBody = preg_replace('/^#+\s*' . preg_quote($story->title, '/') . '\s*(?:\n|$)/mi', '', $storyBody, 1);
                $storyBody = trim($storyBody);
            }
        @endphp

        <!-- Read to Me (Text-to-Speech) -->
        @if ($storyBody)
        <div class="mb-6"
             x-data="{
                speaking: false,
                paused: false,
                utterance: null,
                start() {
                    const titleEl = document.getElementById('tts-title');
                    const bodyEl  = document.getElementById('story-text-content');
                    const title   = titleEl ? titleEl.innerText.trim() : '';
                    const body    = bodyEl  ? bodyEl.innerText.trim()  : '';
                    const text    = (title ? title + '. \n\n' : '') + body;
                    if (!text) return;
                    window.speechSynthesis.cancel();
                    const u = new SpeechSynthesisUtterance(text);
                    u.rate = 0.9;
                    u.pitch = 1;
                    u.onend = () => { this.speaking = false; this.paused = false; };
                    window.speechSynthesis.speak(u);
                    this.utterance = u;
                    this.speaking = true; this.paused = false;
                },
                pause() {
                    window.speechSynthesis.pause();
                    this.paused = true;
                },
                resume() {
                    window.speechSynthesis.resume();
                    this.paused = false;
                },
                stop() {
                    window.speechSynthesis.cancel();
                    this.speaking = false; this.paused = false;
                }
             }">
            <template x-if="!speaking">
                <div>
                    <button @click="start()"
                        class="flex w-full items-center justify-center gap-3 rounded-2xl bg-purple-600 px-6 py-4 text-lg font-bold text-white shadow-md transition-colors hover:bg-purple-700 active:bg-purple-800">
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.114 5.636a9 9 0 0 1 0 12.728M16.463 8.288a5.25 5.25 0 0 1 0 7.424M6.75 8.25l4.72-4.72a.75.75 0 0 1 1.28.53v15.88a.75.75 0 0 1-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.009 9.009 0 0 1 2.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75Z" />
                        </svg>
                        🔊 Read This Story to Me
                    </button>
                    <p class="volume-tip mt-2 text-center text-sm text-gray-400">📢 Make sure your phone volume is turned up!</p>
                </div>
            </template>
            <template x-if="speaking">
                <div class="flex items-center gap-3">
                    <template x-if="!paused">
                        <button @click="pause()"
                            class="flex flex-1 items-center justify-center gap-2 rounded-2xl bg-purple-100 border-2 border-purple-400 px-4 py-3 text-base font-semibold text-purple-700">
                            ⏸ Pause
                        </button>
                    </template>
                    <template x-if="paused">
                        <button @click="resume()"
                            class="flex flex-1 items-center justify-center gap-2 rounded-2xl bg-purple-600 px-4 py-3 text-base font-semibold text-white">
                            ▶ Resume
                        </button>
                    </template>
                    <button @click="stop()"
                        class="flex items-center justify-center gap-2 rounded-2xl border-2 border-red-300 bg-red-50 px-4 py-3 text-base font-semibold text-red-600">
                        ⏹ Stop
                    </button>
                </div>
            </template>
        </div>
        @endif

        <!-- Full Story Content -->
        <div class="rounded-2xl border border-gray-200 bg-white p-8 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
            @if ($storyBody)
                {{-- Hidden title for TTS --}}
                <span id="tts-title" class="sr-only">{{ $story->title ?? '' }}</span>
                {{-- Print-only title/author (hidden on screen, visible when printing) --}}
                <div class="print-only-title">{{ $story->title ?? 'My Story' }}</div>
                <div class="print-only-author">{{ $story->author_name ?? '' }}</div>
                <article id="story-text-content" class="story-content prose prose-base prose-gray mx-auto max-w-prose dark:prose-invert
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
                    .story-content {
                        font-family: Arial, Helvetica, sans-serif !important;
                        font-size: 16pt !important;
                        line-height: 1.7 !important;
                    }
                    .story-content p,
                    .story-content li,
                    .story-content blockquote {
                        font-family: Arial, Helvetica, sans-serif !important;
                        font-size: 16pt !important;
                    }
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
        <div class="no-print mt-6 flex items-center justify-end">
            @if ($story->isCompleted())
                <div class="text-xs text-gray-400">
                    {{ str_word_count($story->content) }} words
                </div>
            @endif
        </div>

        <!-- Writing Coach Quick Actions -->
        @if ($story->isCompleted())
            <div class="hidden mt-8 rounded-2xl border-2 border-amber-200 bg-amber-50 p-5 dark:border-amber-800/50 dark:bg-amber-900/10">
                <div class="flex items-center gap-2 mb-4">
                    <span class="text-xl">✍️</span>
                    <div>
                        <h3 class="text-base font-semibold text-amber-800 dark:text-amber-300">Work on Your Story</h3>
                        <p class="text-sm text-amber-600 dark:text-amber-400">Tap a button — your writing coach responds instantly</p>
                    </div>
                </div>
                <div class="space-y-2">
                    <button
                        type="button"
                        onclick="sendToCoach('What\'s already working well in my story, and what one thing should I improve first?')"
                        class="flex w-full items-center gap-3 rounded-xl bg-white border-2 border-amber-200 px-4 py-3.5 text-left text-base font-medium text-amber-800 transition-colors hover:bg-amber-100 active:bg-amber-200 cursor-pointer dark:bg-zinc-800 dark:text-amber-300 dark:border-amber-700"
                    >
                        <span class="text-xl shrink-0">🔍</span> What’s working &amp; what to improve?
                    </button>
                    <button
                        type="button"
                        onclick="sendToCoach('The story feels too short or incomplete. Please continue writing it, keeping the same style and voice.')"
                        class="flex w-full items-center gap-3 rounded-xl bg-white border-2 border-amber-200 px-4 py-3.5 text-left text-base font-medium text-amber-800 transition-colors hover:bg-amber-100 active:bg-amber-200 cursor-pointer dark:bg-zinc-800 dark:text-amber-300 dark:border-amber-700"
                    >
                        <span class="text-xl shrink-0">✏️</span> Keep writing — the story needs more
                    </button>
                    <button
                        type="button"
                        onclick="sendToCoach('Please rewrite the ending of my story to make it more satisfying and complete.')"
                        class="flex w-full items-center gap-3 rounded-xl bg-white border-2 border-amber-200 px-4 py-3.5 text-left text-base font-medium text-amber-800 transition-colors hover:bg-amber-100 active:bg-amber-200 cursor-pointer dark:bg-zinc-800 dark:text-amber-300 dark:border-amber-700"
                    >
                        <span class="text-xl shrink-0">🏁</span> Make the ending better
                    </button>
                    <button
                        type="button"
                        onclick="sendToCoach('Please clean up any spelling, grammar, or awkward sentences in my story, keeping my voice intact.')"
                        class="flex w-full items-center gap-3 rounded-xl bg-white border-2 border-amber-200 px-4 py-3.5 text-left text-base font-medium text-amber-800 transition-colors hover:bg-amber-100 active:bg-amber-200 cursor-pointer dark:bg-zinc-800 dark:text-amber-300 dark:border-amber-700"
                    >
                        <span class="text-xl shrink-0">✨</span> Clean up spelling &amp; grammar
                    </button>
                </div>
            </div>
        @endif

        <!-- Fix a Specific Thing -->
        @if ($story->isCompleted())
        <div class="hidden mt-3" x-data="{ open: false, request: '', sent: false }">
            <template x-if="!open && !sent">
                <button @click="open = true"
                    class="flex w-full items-center justify-center gap-3 rounded-2xl border-2 border-orange-300 bg-orange-50 px-6 py-4 text-lg font-bold text-orange-700 shadow-sm transition-colors hover:bg-orange-100 active:bg-orange-200">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" />
                    </svg>
                    ✏️ Fix a Specific Thing
                </button>
            </template>
            <template x-if="open">
                <div class="rounded-2xl border-2 border-orange-200 bg-orange-50 p-5 space-y-3">
                    <p class="text-base font-semibold text-orange-800">📝 What needs fixing?</p>
                    <p class="text-sm text-orange-600">Speak or type it — e.g. “Change the name Herman to Harold” or “Make the ending happier”</p>
                    <textarea
                        x-model="request"
                        rows="3"
                        placeholder="🎤 Tap here and say what to change..."
                        class="w-full rounded-xl border border-orange-200 bg-white px-4 py-3 text-base text-gray-800 focus:border-orange-400 focus:outline-none focus:ring-2 focus:ring-orange-200"
                    ></textarea>
                    <div class="grid grid-cols-2 gap-3">
                        <button @click="open = false; request = ''" class="rounded-xl border-2 border-gray-300 bg-white px-4 py-3 text-base font-semibold text-gray-600">
                            ← Cancel
                        </button>
                        <button
                            @click="
                                if (request.trim()) {
                                    sendToCoach('Please fix this in my story: ' + request);
                                    sent = true;
                                    open = false;
                                    request = '';
                                }
                            "
                            class="rounded-xl bg-orange-500 px-4 py-3 text-base font-bold text-white hover:bg-orange-600 active:bg-orange-700">
                            Send →
                        </button>
                    </div>
                </div>
            </template>
            <template x-if="sent">
                <div class="rounded-2xl border-2 border-green-200 bg-green-50 p-4 text-center">
                    <p class="text-base font-semibold text-green-700">✅ Sent! Scroll down to see the coach’s response.</p>
                    <button @click="sent = false" class="mt-2 text-sm text-green-600 underline">Fix something else</button>
                </div>
            </template>
        </div>
        @endif

        <!-- Share + Done buttons -->
        @if ($story->isCompleted())
            <div class="mt-8 space-y-3">

                {{-- Done button --}}
                <a href="{{ route('books.index') }}" class="flex w-full items-center justify-center gap-2 rounded-xl bg-green-500 px-6 py-4 text-lg font-semibold text-white shadow-md transition-colors hover:bg-green-600">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                    </svg>
                    I'm Happy with My Story — Done!
                </a>

                {{-- Share section --}}
                @php
                    $shareUrl = $story->is_private
                        ? \Illuminate\Support\Facades\URL::signedRoute('stories.public.show', $story, now()->addDays(30))
                        : route('stories.public.show', $story);
                @endphp
                <p class="text-center text-base font-semibold text-gray-600 dark:text-gray-400">📤 Share this story:</p>
                <div>
                    <a
                        href="{{ $shareUrl }}"
                        onclick="
                            if (navigator.share) {
                                event.preventDefault();
                                navigator.share({
                                    title: '{{ addslashes($story->title ?? 'My Story') }}',
                                    url: '{{ $shareUrl }}'
                                });
                            }
                        "
                        class="flex w-full items-center justify-center gap-2 rounded-xl bg-blue-500 px-4 py-4 text-base font-semibold text-white shadow transition-colors hover:bg-blue-600 cursor-pointer"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 1 0 0 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186 9.566-5.314m-9.566 7.5 9.566 5.314m0 0a2.25 2.25 0 1 0 3.935 2.186 2.25 2.25 0 0 0-3.935-2.186Zm0-12.814a2.25 2.25 0 1 0 3.933-2.185 2.25 2.25 0 0 0-3.933 2.185Z" />
                        </svg>
                        Share
                    </a>
                </div>

                {{-- Print button --}}
                <button onclick="printStory(event)" class="flex w-full items-center justify-center gap-2 rounded-xl bg-gray-700 px-6 py-4 text-lg font-semibold text-white shadow-md transition-colors hover:bg-gray-800 cursor-pointer">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0 2.904-5.863 2.025.497 2.025.497m-5.864 3.916L15.9 5.476m5.75 6.64h1.125a2.25 2.25 0 0 1 2.25 2.25v1.125m0-3.375c0-.621-.504-1.125-1.125-1.125m-9.375 1.125a2.25 2.25 0 0 1 2.25 2.25v1.125m-1.125 3.375h9.375c.621 0 1.125-.504 1.125-1.125m0-6.75c0-.621-.504-1.125-1.125-1.125m-13.5 1.125a2.25 2.25 0 0 1 2.25 2.25v1.125m0-6.75 2.904-5.863 2.025.497M3 15.75h12.375c.621 0 1.125-.504 1.125-1.125V11.25a2.25 2.25 0 0 1-2.25-2.25v-1.125m0-3.375c0-.621.504-1.125 1.125-1.125M3.375 19.125h17.25c.621 0 1.125-.504 1.125-1.125v-7.5c0-.621-.504-1.125-1.125-1.125M3.375 15.75v3.375c0 .621.504 1.125 1.125 1.125m17.25-3.375v3.375c0 .621-.504 1.125-1.125 1.125m-17.25 0h17.25" />
                    </svg>
                    Print This Story
                </button>

                {{-- Edit button --}}
                <a href="{{ route('books.edit', $story) }}" wire:navigate
                   class="flex w-full items-center justify-center gap-2 rounded-xl bg-blue-500 px-6 py-4 text-lg font-semibold text-white shadow-md transition-colors hover:bg-blue-600"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" />
                    </svg>
                    Edit
                </a>
            </div>
        @endif

        <!-- Continue the conversation -->
        @if ($story->isCompleted())
            <div
                id="story-chat-section"
                class="hidden mt-6"
                x-data
                x-on:story-content-updated.window="window.location.reload()"
            >
                @if ($story->format === 'author_voice')
                    <h2 class="mb-1 text-lg font-semibold text-gray-900 dark:text-white">Continue with your writing coach</h2>
                    <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">Click a suggestion above, answer the coach's questions, or ask it to help you write a specific scene.</p>
                @else
                    <h2 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Continue with your Writing Coach</h2>
                @endif
                <livewire:story-chat :story="$story" />
            </div>
        @endif

    </div>

    <script>
        function sendToCoach(text) {
            window.dispatchEvent(new CustomEvent('coach-suggestion', { detail: { text: text } }));
            setTimeout(() => {
                document.getElementById('story-chat-section')?.scrollIntoView({ behavior: 'smooth' });
            }, 100);
        }
    </script>

    {{-- Print styles: matches Share/Word/PDF layout --}}
    <style>
        @media print {
            @page {
                size: letter portrait;
                margin: 0.6in 0.6in 0.4in 1.25in;
            }
            body {
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
                font-family: Arial, Helvetica, sans-serif !important;
                font-size: 11pt !important;
                line-height: 1.5 !important;
                color: #1f2937 !important;
                background: #fff !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            /* Hide all navigation and UI chrome */
            header, nav, footer, button, .volume-tip, .mic-reminder,
            .no-print, #story-chat-section, [class*="mt-8"]:has(a),
            [class*="mt-6"]:has(button), .rounded-2xl:has(button) {
                display: none !important;
            }
            /* Remove layout constraints so @page margins control the page */
            .mx-auto.max-w-3xl {
                max-width: none !important;
                width: 100% !important;
                padding-left: 0 !important;
                padding-right: 0 !important;
                margin-left: 0 !important;
                margin-right: 0 !important;
            }
            /* Remove card borders and padding */
            .rounded-2xl, .border, .shadow {
                border: none !important;
                box-shadow: none !important;
                padding: 0 !important;
                background: transparent !important;
            }
            /* Cover image: top center, small enough to help fit on one page, printed in color */
            .cover-image-wrap,
            .cover-image-wrap > div {
                display: block !important;
                text-align: center !important;
                margin: 0 auto 6pt auto !important;
                padding: 0 !important;
                max-width: 100% !important;
                border-radius: 1rem !important;
                overflow: hidden !important;
                -webkit-mask-image: -webkit-radial-gradient(white, black) !important;
            }
            .cover-image-wrap img {
                display: block !important;
                margin: 0 auto 6pt auto !important;
                max-height: 1.6in !important;
                width: auto !important;
                object-fit: contain !important;
                border-radius: 1rem !important;
                print-color-adjust: exact !important;
                -webkit-print-color-adjust: exact !important;
                color-adjust: exact !important;
                forced-color-adjust: none !important;
                filter: none !important;
                -webkit-filter: none !important;
                page-break-after: avoid;
                -webkit-mask-image: -webkit-radial-gradient(white, black) !important;
            }
            /* Story body text — 11pt Arial */
            #story-text-content {
                display: block !important;
                width: 100% !important;
                max-width: none !important;
                margin: 0 !important;
                padding: 0 !important;
                font-family: Arial, Helvetica, sans-serif !important;
                font-size: 11pt !important;
                line-height: 1.5 !important;
                color: #1f2937 !important;
            }
            #story-text-content p {
                font-family: Arial, Helvetica, sans-serif !important;
                font-size: 11pt !important;
                line-height: 1.5 !important;
                margin: 0 0 0.8em 0 !important;
                color: #1f2937 !important;
            }
            #story-text-content > p:last-child {
                margin-bottom: 0 !important;
            }
            #story-text-content > p:first-of-type {
                margin-bottom: 0.8em !important;
                font-size: 1em !important;
            }
            #story-text-content > p:first-of-type::first-letter {
                float: none !important;
                font-size: inherit !important;
                line-height: inherit !important;
                margin: 0 !important;
            }
            #story-text-content h1,
            #story-text-content h2,
            #story-text-content h3 {
                font-size: 12pt !important;
                font-weight: bold !important;
                margin: 1.2em 0 0.4em !important;
                color: #111827 !important;
                page-break-after: avoid;
            }
            #story-text-content blockquote {
                border-left: 4px solid #3b82f6 !important;
                padding-left: 1em !important;
                color: #4b5563 !important;
                font-style: italic !important;
                margin: 1.2em 0 !important;
            }
            /* Print-only title/author block */
            .print-only-title {
                display: block !important;
                font-size: 22pt !important;
                font-weight: 700 !important;
                color: #111827 !important;
                margin: 0 0 6px 0 !important;
                line-height: 1.2 !important;
                page-break-after: avoid;
            }
            .print-only-author {
                display: block !important;
                font-size: 11pt !important;
                color: #6b7280 !important;
                margin-bottom: 0.8em !important;
            }
        }
        /* Hidden on screen, shown only when printing */
        .print-only-title,
        .print-only-author {
            display: none;
        }
    </style>

<script>
    document.addEventListener('livewire:navigating', () => { window.speechSynthesis.cancel(); });
    window.addEventListener('pagehide', () => { window.speechSynthesis.cancel(); });

    async function printStory(event) {
        const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
        // On iOS, share the PDF file directly so the Share Sheet surfaces "Print" immediately.
        if (isIOS && navigator.canShare) {
            event.preventDefault();
            try {
                const res = await fetch('{{ route('books.download.pdf', $story) }}?inline=1');
                const blob = await res.blob();
                const file = new File([blob], '{{ Str::slug($story->title ?? 'story') }}.pdf', { type: 'application/pdf' });
                if (navigator.canShare({ files: [file] })) {
                    await navigator.share({ files: [file], title: @json($story->title ?? 'My Story') });
                    return;
                }
            } catch (e) { /* fall through */ }
            // Fallback: open the PDF inline so the user can print from the viewer.
            window.location.href = '{{ route('books.download.pdf', $story) }}?inline=1';
            return;
        }
        window.print();
    }
</script>
</x-layouts::writer>
