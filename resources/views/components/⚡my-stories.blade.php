<?php

use App\Models\Story;
use Illuminate\Support\Str;
use Livewire\Component;

new class extends Component
{
    public function with(): array
    {
        return [
            'stories' => Story::where('user_id', auth()->id())->latest()->get(),
        ];
    }

    public function hasPendingStories(): bool
    {
        return Story::where('user_id', auth()->id())
            ->where(function ($query) {
                $query->whereNull('cover_image_path')
                    ->orWhere('status', '!=', 'completed');
            })
            ->exists();
    }

    /**
     * Build the rich-text (12pt Arial) HTML payload used when copying a story
     * to the clipboard for pasting into an email. Excludes any image.
     */
    public function copyHtml(Story $story): string
    {
        abort_if($story->user_id !== auth()->id(), 403);

        $title  = trim($story->title ?? 'Untitled Story');
        $author = trim($story->author_name ?? optional($story->user)->name ?? '');

        $raw = $story->content ?? '';
        // Drop any trailing "Writing Coach" note section.
        $raw = preg_split('/^#+\s*Writing Coach.*$/mi', $raw)[0];
        // Remove a leading markdown heading that duplicates the title.
        if ($title !== '') {
            $raw = preg_replace('/^#+\s*' . preg_quote($title, '/') . '\s*(?:\n|$)/mi', '', $raw, 1);
        }
        $bodyHtml = (string) Str::markdown(trim($raw));
        // Decode entities so pasted content shows real quotes, then re-encode only < > &
        // to keep the HTML valid while avoiding &quot; fragments in the pasted output.
        $bodyHtml = htmlspecialchars(html_entity_decode($bodyHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_NOQUOTES, 'UTF-8');

        $style = 'font-family: Arial, Helvetica, sans-serif; font-size: 12pt; line-height: 1.5; color: #000;';

        $html = '<div style="' . $style . '">';
        $html .= '<p style="' . $style . ' font-weight: bold; font-size: 14pt; margin: 0 0 4pt 0;">' . htmlspecialchars($title, ENT_NOQUOTES, 'UTF-8') . '</p>';
        if ($author !== '') {
            $html .= '<p style="' . $style . ' color: #444; margin: 0 0 12pt 0;">by ' . htmlspecialchars($author, ENT_NOQUOTES, 'UTF-8') . '</p>';
        }
        $html .= '<div style="' . $style . '">' . $bodyHtml . '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Plain-text fallback for the clipboard copy.
     */
    public function copyText(Story $story): string
    {
        abort_if($story->user_id !== auth()->id(), 403);

        $title  = trim($story->title ?? 'Untitled Story');
        $author = trim($story->author_name ?? optional($story->user)->name ?? '');

        $raw = $story->content ?? '';
        $raw = preg_split('/^#+\s*Writing Coach.*$/mi', $raw)[0];
        if ($title !== '') {
            $raw = preg_replace('/^#+\s*' . preg_quote($title, '/') . '\s*(?:\n|$)/mi', '', $raw, 1);
        }
        $body = trim(strip_tags((string) Str::markdown(trim($raw))));
        // Decode any HTML entities so plain text doesn't contain &quot; fragments.
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $text = $title . "\n";
        if ($author !== '') {
            $text .= 'by ' . $author . "\n";
        }
        $text .= "\n" . $body;

        return $text;
    }
};

?>

<div
    @if ($this->hasPendingStories())
        wire:poll.5s
    @endif
>
    {{-- ===== MOBILE SIMPLIFIED VIEW ===== --}}
    <div class="md:hidden flex flex-col items-center justify-start min-h-[80vh] px-6 pt-10 pb-8 text-center">
        <p class="text-2xl font-bold text-gray-900 dark:text-white mb-1">
            Hello, {{ auth()->user()->name }} 👋
        </p>
        <p class="text-base text-gray-500 dark:text-gray-400 mb-10">What would you like to do?</p>

        <div class="w-full max-w-sm flex flex-col gap-4">
            <a href="{{ route('writer.create') }}" wire:navigate
               class="flex items-center justify-center gap-3 rounded-2xl bg-blue-600 px-6 py-6 text-xl font-bold text-white shadow-lg active:bg-blue-700">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-7" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Start a New Story
            </a>

            @if ($stories->isEmpty())
                <div class="rounded-2xl border-2 border-dashed border-gray-200 bg-gray-50 px-6 py-8 text-gray-400 dark:border-zinc-700 dark:bg-zinc-800">
                    <p class="text-base">You haven't written any stories yet.</p>
                    <p class="text-sm mt-1">Tap above to write your first one!</p>
                </div>
            @endif
        </div>

        {{-- Mobile story list (scrolled to) --}}
        @if ($stories->isNotEmpty())
            <div id="my-stories" class="w-full max-w-sm mt-10 text-left">
                <h2 class="mb-4 text-lg font-bold text-gray-800 dark:text-white">My Stories</h2>
                <div class="flex flex-col gap-3">
                    @foreach ($stories as $story)
                        <a href="{{ route('books.show', $story) }}" wire:navigate
                           class="flex items-center gap-4 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                            <div class="flex size-12 shrink-0 items-center justify-center rounded-2xl {{ $story->cover_image_path ? '' : 'bg-blue-50 dark:bg-zinc-700' }}">
                                @if ($story->cover_image_path)
                                    <img src="{{ Storage::url($story->cover_image_path) }}?v={{ Storage::disk('public')->lastModified($story->cover_image_path) }}" class="size-12 rounded-2xl object-contain" />
                                @else
                                    <svg xmlns="http://www.w3.org/2000/svg" class="size-6 text-blue-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                                    </svg>
                                @endif
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-base font-semibold text-gray-900 dark:text-white">{{ $story->title ?? 'Untitled Story' }}</p>
                                <p class="text-sm text-gray-400">{{ $story->created_at->format('M j, Y') }}</p>
                            </div>
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-5 shrink-0 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                            </svg>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- My Next Book section (mobile) --}}
        <livewire:my-book />
    </div>

    {{-- ===== DESKTOP VIEW (unchanged) ===== --}}
    <div class="hidden md:block mx-auto max-w-6xl px-4 py-12 sm:px-6 lg:px-8">

        <!-- Page Header -->
        <div class="mb-8 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white">My Stories</h1>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $stories->count() }} {{ Str::plural('story', $stories->count()) }}</p>
            </div>
            <a
                href="{{ route('writer.create') }}"
                class="flex items-center gap-2 rounded-lg bg-blue-500 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition-colors hover:bg-blue-600"
                wire:navigate
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                New Story
            </a>
        </div>

        @if (session('success'))
            <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-400">
                {{ session('success') }}
            </div>
        @endif

        @if ($stories->isEmpty())
            <!-- Empty State -->
            <div class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-gray-300 bg-white py-24 text-center dark:border-zinc-700 dark:bg-zinc-800">
                <div class="mb-4 flex size-16 items-center justify-center rounded-full bg-blue-50 dark:bg-zinc-700">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-8 text-blue-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                    </svg>
                </div>
                <h3 class="mb-1 text-base font-semibold text-gray-900 dark:text-white">No stories yet</h3>
                <p class="mb-6 max-w-xs text-sm text-gray-500 dark:text-gray-400">
                    Start writing your first book or story with AI assistance.
                </p>
                <a
                    href="{{ route('writer.create') }}"
                    class="rounded-lg bg-blue-500 px-5 py-2.5 text-sm font-medium text-white transition-colors hover:bg-blue-600"
                    wire:navigate
                >
                    Create your first story
                </a>
            </div>
        @else
            <!-- Stories Grid -->
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($stories as $story)
                    <div class="group relative flex flex-col overflow-hidden rounded-3xl border border-gray-200 bg-white shadow-sm transition-shadow hover:shadow-md dark:border-zinc-700 dark:bg-zinc-800">
                        <a
                            href="{{ route('books.show', $story) }}"
                            wire:navigate
                            class="flex flex-1 flex-col"
                        >
                            <!-- Cover Image -->
                            <div class="relative h-44 w-full overflow-hidden">
                                @if ($story->cover_image_path)
                                    <img
                                        src="{{ Storage::url($story->cover_image_path) }}?v={{ Storage::disk('public')->lastModified($story->cover_image_path) }}"
                                        alt="{{ $story->title ?? 'Story cover' }}"
                                        class="h-full w-full object-contain transition-transform duration-300 group-hover:scale-105"
                                    />
                                @else
                                    <div class="flex h-full items-center justify-center bg-gradient-to-br from-blue-50 to-indigo-100 dark:from-zinc-700 dark:to-zinc-600">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="size-12 text-blue-200 dark:text-zinc-500" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                                        </svg>
                                    </div>
                                @endif

                                <!-- Status badge -->
                                @if ($story->status !== 'completed')
                                    <span class="absolute right-3 top-3 rounded-full px-2.5 py-0.5 text-xs font-medium
                                        {{ $story->status === 'generating' ? 'bg-yellow-100 text-yellow-700' : '' }}
                                        {{ $story->status === 'pending'    ? 'bg-gray-100 text-gray-600'   : '' }}
                                        {{ $story->status === 'failed'     ? 'bg-red-100 text-red-600'     : '' }}
                                    ">
                                        {{ ucfirst($story->status) }}
                                    </span>
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
                    </div>
                @endforeach
            </div>
        @endif

        {{-- My Next Book section (desktop) --}}
        <livewire:my-book />

    </div>
</div>
