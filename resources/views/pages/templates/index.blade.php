<x-layouts::writer :title="__('Templates')">
    <div class="mx-auto max-w-6xl px-4 py-12 sm:px-6 lg:px-8">

        <!-- Page Header -->
        <div class="mb-8 text-center">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Story Templates</h1>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                Jump-start your writing with a professionally crafted template.
            </p>
        </div>

        <!-- Template Grid -->
        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">

            {{-- Your Voice card — special routing to the guided wizard --}}
            <div class="group flex flex-col rounded-2xl border-2 border-amber-200 bg-amber-50 p-6 shadow-sm transition-shadow hover:shadow-md dark:border-amber-800/50 dark:bg-amber-900/10">
                <div class="mb-4 text-3xl">✍️</div>
                <div class="mb-1 flex items-center gap-2">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Write in Your Voice</h3>
                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/40 dark:text-amber-400">
                        Your Words
                    </span>
                </div>
                <p class="mb-3 text-sm text-gray-600 dark:text-gray-400 flex-1">
                    A guided 3-step wizard that draws your story out of you. The AI polishes spelling &amp; punctuation — but keeps <em>your</em> voice, style, and words.
                </p>
                <p class="mb-5 text-xs text-amber-700 dark:text-amber-400 font-medium">
                    Best for: writers who want a nudge, not a ghostwriter.
                </p>
                <a
                    href="{{ route('writer.create') }}"
                    wire:navigate
                    class="block w-full rounded-lg border border-amber-300 bg-white py-2 text-center text-sm font-medium text-amber-700 transition-colors hover:border-amber-400 hover:bg-amber-50 dark:border-amber-700 dark:bg-zinc-800 dark:text-amber-400 dark:hover:border-amber-500"
                >
                    Start Your Voice Wizard
                </a>
            </div>

            @foreach ([
                [
                    'icon'   => '📖',
                    'title'  => 'Fantasy Novel',
                    'desc'   => 'Epic worlds, magic systems, and hero journeys.',
                    'tag'    => 'Novel',
                    'genre'  => 'fantasy',
                    'format' => 'explore',
                    'prompt' => 'I want to write a fantasy novel featuring epic world-building, a rich magic system, and a classic hero journey with high stakes and memorable characters.',
                ],
                [
                    'icon'   => '🚀',
                    'title'  => 'Sci-Fi Adventure',
                    'desc'   => 'Space exploration, advanced tech, and moral dilemmas.',
                    'tag'    => 'Novel',
                    'genre'  => 'science fiction',
                    'format' => 'explore',
                    'prompt' => 'I want to write a science fiction adventure set in deep space, exploring advanced technology, first contact, and the moral dilemmas that come with humanity\'s expansion into the universe.',
                ],
                [
                    'icon'   => '💘',
                    'title'  => 'Romance Story',
                    'desc'   => 'Compelling love stories with emotional depth.',
                    'tag'    => 'Novel',
                    'genre'  => 'romance',
                    'format' => 'short_story',
                    'prompt' => 'I want to write a romance story about two people who meet under unexpected circumstances, exploring the tension, vulnerability, and emotional depth of falling in love.',
                ],
                [
                    'icon'   => '🔍',
                    'title'  => 'Mystery Thriller',
                    'desc'   => 'Suspenseful plots with unexpected twists.',
                    'tag'    => 'Novel',
                    'genre'  => 'mystery',
                    'format' => 'explore',
                    'prompt' => 'I want to write a mystery thriller with a complex detective protagonist, a seemingly unsolvable crime, red herrings, and an unexpected twist that reframes everything the reader thought they knew.',
                ],
                [
                    'icon'   => '🎃',
                    'title'  => 'Horror',
                    'desc'   => 'Atmospheric dread, psychological terror, and spine-chilling moments.',
                    'tag'    => 'Novel',
                    'genre'  => 'horror',
                    'format' => 'explore',
                    'prompt' => 'I want to write a horror story that builds atmospheric dread and psychological tension. Focus on unsettling imagery, mounting fear, and moments that linger in the reader\'s mind long after finishing.',
                ],
                [
                    'icon'   => '🏛️',
                    'title'  => 'Historical Fiction',
                    'desc'   => 'Rich period settings with compelling characters and authentic detail.',
                    'tag'    => 'Novel',
                    'genre'  => 'historical fiction',
                    'format' => 'explore',
                    'prompt' => 'I want to write historical fiction set in a richly detailed period, featuring compelling characters navigating the authentic challenges, customs, and conflicts of their time.',
                ],
                [
                    'icon'   => '🎬',
                    'title'  => 'Screenplay',
                    'desc'   => 'Structured scripts for film and television.',
                    'tag'    => 'Script',
                    'genre'  => 'screenplay',
                    'format' => 'outline',
                    'prompt' => 'I want to write a screenplay for a feature film or TV pilot. Help me develop a compelling premise, strong characters, and a properly structured three-act narrative suitable for the screen.',
                ],
                [
                    'icon'   => '📚',
                    'title'  => 'Non-Fiction',
                    'desc'   => 'Research-backed narratives and personal stories.',
                    'tag'    => 'Non-Fiction',
                    'genre'  => 'non-fiction',
                    'format' => 'outline',
                    'prompt' => 'I want to write a non-fiction book combining research, interviews, and personal narrative to tell a compelling true story that informs and moves the reader.',
                ],
                [
                    'icon'   => '🎙️',
                    'title'  => 'The Rest of the Story',
                    'desc'   => 'Paul Harvey-style narrative: true stories with a hidden twist revealed at the end.',
                    'tag'    => 'Narrative',
                    'genre'  => 'historical fiction',
                    'format' => 'short_story',
                    'prompt' => 'Write an original story in the style of Paul Harvey\'s "The Rest of the Story." Choose a surprising true event or a fascinating real person from history — someone whose identity or key secret makes the story land like a gut-punch once revealed. Tell the story in Paul Harvey\'s signature voice: short, punchy sentences. Dramatic pauses. Build the narrative from an unexpected angle — never name the subject directly. Use "our man," "this young woman," "the stranger," or similar. Let the details accumulate. Let the reader lean in. Hold the reveal until the very last moment. Then land it. End with: "And now you know... the rest of the story." The tone should be warm, wry, and full of wonder — as if the whole world is stranger and more beautiful than we ever imagined.',
                ],
            ] as $t)
                <div class="group flex flex-col rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-shadow hover:shadow-md dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="mb-4 text-3xl">{{ $t['icon'] }}</div>
                    <div class="mb-1 flex items-center gap-2">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $t['title'] }}</h3>
                        <span class="rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-600 dark:bg-blue-900/30 dark:text-blue-400">
                            {{ $t['tag'] }}
                        </span>
                    </div>
                    <p class="mb-5 text-sm text-gray-500 dark:text-gray-400 flex-1">{{ $t['desc'] }}</p>
                    <a
                        href="{{ route('writer.create') }}?{{ http_build_query(['prompt' => $t['prompt'], 'genre' => $t['genre'], 'format' => $t['format']]) }}"
                        wire:navigate
                        class="block w-full rounded-lg border border-gray-200 py-2 text-center text-sm font-medium text-gray-700 transition-colors hover:border-blue-400 hover:text-blue-600 dark:border-zinc-600 dark:text-gray-300 dark:hover:border-blue-500 dark:hover:text-blue-400"
                    >
                        Use Template
                    </a>
                </div>
            @endforeach

        </div>
    </div>
</x-layouts::writer>
