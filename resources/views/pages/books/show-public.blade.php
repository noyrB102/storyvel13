<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
        <title>{{ $story->title ?? 'Story' }} - StoryVel</title>
        <style>
            @media print {
                @page { margin: 0.6in 0.6in 0.6in 1.25in; }
            }
        </style>
    </head>
    <body class="min-h-screen bg-gray-50 dark:bg-zinc-950">
        <!-- Public Header -->
        <header class="border-b border-gray-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mx-auto max-w-6xl px-4 py-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between">
                    <a href="{{ route('home') }}" class="flex items-center gap-2">
                        <x-app-logo-icon class="size-7 fill-current text-blue-500" />
                        <span class="text-lg font-semibold text-gray-900 dark:text-white">StoryVel</span>
                    </a>

                    <div class="flex items-center gap-3">
                        @auth
                            <a href="{{ route('books.index') }}" class="text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">
                                My Stories
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">
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

        <main class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">
            <!-- Back link -->
            <a href="{{ route('home') }}" wire:navigate
               class="no-print mb-8 inline-flex items-center gap-1.5 text-sm text-gray-400 transition-colors hover:text-gray-600 dark:hover:text-gray-300">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                </svg>
                All Stories
            </a>

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
                        by {{ $story->author_name }}
                    </p>
                @endif

                <div class="mb-4 h-1 w-20 rounded-full bg-gradient-to-r from-blue-500 to-blue-300 dark:from-blue-400 dark:to-blue-600"></div>


            </div>

            <!-- Divider -->
            <hr class="mb-8 border-gray-200 dark:border-zinc-700" />

            <!-- Full Story Content -->
            <div class="rounded-2xl border border-gray-200 bg-white p-8 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                @if ($story->content)
                    @php
                        $publicContent = $story->content;
                        if ($story->title) {
                            $publicContent = preg_replace('/^#+\s*' . preg_quote($story->title, '/') . '\s*(?:\n|$)/mi', '', $publicContent, 1);
                        }
                        $publicContent = preg_split('/^#+\s*Writing Coach.*$/mi', $publicContent)[0];
                        $publicContent = rtrim($publicContent);
                    @endphp
                    {{-- Print-only title/author (hidden on screen, visible when printing) --}}
                    <div class="print-only-title">{{ $story->title ?? 'My Story' }}</div>
                    <div class="print-only-author">{{ $story->author_name ?? '' }}</div>
                    <article class="story-content prose prose-base prose-gray mx-auto max-w-prose dark:prose-invert
                                prose-headings:font-bold prose-headings:text-gray-900 prose-headings:tracking-tight
                                prose-p:text-gray-700 prose-p:leading-[1.8] prose-p:my-10
                                prose-strong:text-gray-900 prose-strong:font-semibold
                                prose-blockquote:border-l-4 prose-blockquote:border-blue-400 prose-blockquote:pl-4 prose-blockquote:italic
                                prose-a:text-blue-600 prose-a:no-underline hover:prose-a:underline
                                dark:prose-headings:text-white dark:prose-p:text-gray-300 dark:prose-strong:text-white
                                dark:prose-blockquote:border-blue-500">
                        {!! Str::markdown($publicContent) !!}
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
                @else
                    <p class="py-8 text-sm text-gray-400">No content available.</p>
                @endif
            </div>

            <!-- Actions -->
            <div class="actions-row mt-6 flex items-center justify-between">
                <button onclick="window.print()"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-blue-500 px-5 py-2.5 text-sm font-medium text-white transition-colors hover:bg-blue-600 cursor-pointer">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0 2.904-5.863 2.025.497 2.025.497m-5.864 3.916L15.9 5.476m5.75 6.64h1.125a2.25 2.25 0 0 1 2.25 2.25v1.125m0-3.375c0-.621-.504-1.125-1.125-1.125m-9.375 1.125a2.25 2.25 0 0 1 2.25 2.25v1.125m-1.125 3.375h9.375c.621 0 1.125-.504 1.125-1.125m0-6.75c0-.621-.504-1.125-1.125-1.125m-13.5 1.125a2.25 2.25 0 0 1 2.25 2.25v1.125m0-6.75 2.904-5.863 2.025.497M3 15.75h12.375c.621 0 1.125-.504 1.125-1.125V11.25a2.25 2.25 0 0 1-2.25-2.25v-1.125m0-3.375c0-.621.504-1.125 1.125-1.125M3.375 19.125h17.25c.621 0 1.125-.504 1.125-1.125v-7.5c0-.621-.504-1.125-1.125-1.125M3.375 15.75v3.375c0 .621.504 1.125 1.125 1.125m17.25 0h17.25" />
                    </svg>
                    Print Story
                </button>

                <div class="text-xs text-gray-400">
                    {{ str_word_count($story->content ?? '') }} words
                </div>
            </div>
        </main>

        <!-- Footer -->
        <footer class="border-t border-gray-200 bg-white py-8 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mx-auto max-w-6xl px-4 text-center text-sm text-gray-500 dark:text-gray-400 sm:px-6 lg:px-8">
                <p>&copy; {{ date('Y') }} StoryVel. Create and share your stories.</p>
            </div>
        </footer>

    <style>
        @media print {
            @page {
                size: letter portrait;
                margin: 0.6in 0.6in 0.6in 1.25in;
            }
            body {
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
                font-family: Arial, Helvetica, sans-serif !important;
                font-size: 12pt !important;
                line-height: 1.6 !important;
                color: #1f2937 !important;
                background: #fff !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            /* Hide public header, footer, divider, actions and UI chrome */
            header, footer, hr, .no-print, .actions-row {
                display: none !important;
            }
            /* Remove layout constraints so @page margins control the page */
            main {
                max-width: none !important;
                width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            /* Remove card borders and padding */
            .rounded-2xl, .border, .shadow-sm {
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
                margin: 0 auto 12pt auto !important;
                padding: 0 !important;
                max-width: 100% !important;
                border-radius: 1rem !important;
                overflow: hidden !important;
            }
            .cover-image-wrap img {
                display: block !important;
                margin: 0 auto 12pt auto !important;
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
            }
            /* Story body text — 12pt Arial */
            .story-content {
                display: block !important;
                width: 100% !important;
                max-width: none !important;
                margin: 0 !important;
                padding: 0 !important;
                font-family: Arial, Helvetica, sans-serif !important;
                font-size: 12pt !important;
                line-height: 1.6 !important;
                color: #1f2937 !important;
            }
            .story-content p,
            .story-content li,
            .story-content blockquote {
                font-family: Arial, Helvetica, sans-serif !important;
                font-size: 12pt !important;
                line-height: 1.6 !important;
                margin-bottom: 0.8em !important;
                color: #1f2937 !important;
            }
            .story-content > p:last-child {
                margin-bottom: 0 !important;
            }
            .story-content > p:first-of-type {
                margin-bottom: 0.8em !important;
                font-size: 1em !important;
            }
            .story-content h1,
            .story-content h2,
            .story-content h3 {
                font-size: 14pt !important;
                font-weight: bold !important;
                margin: 1.2em 0 0.4em !important;
                color: #111827 !important;
                page-break-after: avoid;
            }
            .story-content blockquote {
                border-left: 4px solid #3b82f6 !important;
                padding-left: 1em !important;
                color: #4b5563 !important;
                font-style: italic !important;
                margin: 1.2em 0 !important;
            }
            /* Print-only title/author block */
            .print-only-title {
                display: block !important;
                font-size: 26pt !important;
                font-weight: 900 !important;
                color: #111827 !important;
                margin: 0 0 6px 0 !important;
                line-height: 1.2 !important;
                page-break-after: avoid;
            }
            .print-only-author {
                display: block !important;
                font-size: 12pt !important;
                color: #6b7280 !important;
                margin-bottom: 18pt !important;
            }
        }
        /* Hidden on screen, shown only when printing */
        .print-only-title,
        .print-only-author {
            display: none;
        }
    </style>

        @fluxScripts
    </body>
</html>
