<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
        <title>{{ $story->title ?? 'Story' }} - StoryVel</title>
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
               class="mb-8 inline-flex items-center gap-1.5 text-sm text-gray-400 transition-colors hover:text-gray-600 dark:hover:text-gray-300">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                </svg>
                All Stories
            </a>

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
                        by {{ $story->author_name }}
                    </p>
                @endif

                <div class="mb-4 h-1 w-20 rounded-full bg-gradient-to-r from-blue-500 to-blue-300 dark:from-blue-400 dark:to-blue-600"></div>

                <p class="text-sm italic text-gray-500 dark:text-gray-400 line-clamp-2">
                    {{ Str::limit($story->prompt, 200) }}
                </p>
            </div>

            <!-- Divider -->
            <hr class="mb-8 border-gray-200 dark:border-zinc-700" />

            <!-- Full Story Content -->
            <div class="rounded-2xl border border-gray-200 bg-white p-8 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                @if ($story->content)
                    <article class="story-content prose prose-base prose-gray mx-auto max-w-prose dark:prose-invert
                                prose-headings:font-bold prose-headings:text-gray-900 prose-headings:tracking-tight
                                prose-p:text-gray-700 prose-p:leading-[1.8] prose-p:my-10
                                prose-strong:text-gray-900 prose-strong:font-semibold
                                prose-blockquote:border-l-4 prose-blockquote:border-blue-400 prose-blockquote:pl-4 prose-blockquote:italic
                                prose-a:text-blue-600 prose-a:no-underline hover:prose-a:underline
                                dark:prose-headings:text-white dark:prose-p:text-gray-300 dark:prose-strong:text-white
                                dark:prose-blockquote:border-blue-500">
                        {!! Str::markdown($story->content) !!}
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
                @else
                    <p class="py-8 text-sm text-gray-400">No content available.</p>
                @endif
            </div>

            <!-- Actions -->
            <div class="mt-6 flex items-center justify-between">
                @auth
                    <a href="{{ route('writer.create') }}" wire:navigate
                       class="rounded-lg bg-blue-500 px-5 py-2.5 text-sm font-medium text-white transition-colors hover:bg-blue-600">
                        Write your own
                    </a>
                @else
                    <a href="{{ route('login') }}?redirect={{ route('writer.create') }}"
                       class="rounded-lg bg-blue-500 px-5 py-2.5 text-sm font-medium text-white transition-colors hover:bg-blue-600">
                        Write your own
                    </a>
                @endauth

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

        @fluxScripts
    </body>
</html>
