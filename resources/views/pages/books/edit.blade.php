<x-layouts::writer :title="'Edit: ' . ($story->title ?? 'Story')">
    <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">

        <!-- Back -->
        <a href="{{ route('books.show', $story) }}" wire:navigate
           class="mb-8 inline-flex items-center gap-1.5 text-sm text-gray-400 transition-colors hover:text-gray-600 dark:hover:text-gray-300">
            <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
            </svg>
            Back to story
        </a>

        <h1 class="mb-8 text-2xl font-bold text-gray-900 dark:text-white">Edit Story</h1>

        <form action="{{ route('books.update', $story) }}" method="POST" class="space-y-6">
            @csrf
            @method('PUT')

            @if (session('success'))
                <div class="rounded-lg bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-900/20 dark:text-green-400">
                    {{ session('success') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 dark:bg-red-900/20 dark:text-red-400">
                    {{ $errors->first() }}
                </div>
            @endif

            <!-- Cover Image -->
            <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <h2 class="mb-4 text-sm font-semibold text-gray-700 dark:text-gray-300">Cover Image</h2>
                <div class="flex items-start gap-5">
                    @if ($story->cover_image_path)
                        <img
                            src="{{ Storage::url($story->cover_image_path) }}"
                            alt="Cover"
                            class="h-32 w-24 rounded-lg object-cover shadow"
                        />
                    @else
                        <div class="flex h-32 w-24 items-center justify-center rounded-lg bg-gray-100 dark:bg-zinc-700">
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-8 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
                            </svg>
                        </div>
                    @endif
                    <div class="flex-1">
                        <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
                            Cover images are AI-generated using DALL-E based on your story's title and genre.
                            Regenerating will create a new image and replace the current one.
                        </p>
                        <button type="button" onclick="document.getElementById('regenerate-form').submit()"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-zinc-600 dark:bg-zinc-700 dark:text-gray-300 dark:hover:bg-zinc-600"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                            </svg>
                            Regenerate cover
                        </button>
                    </div>
                </div>
            </div>

            <!-- Title + Genre -->
            <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <h2 class="mb-4 text-sm font-semibold text-gray-700 dark:text-gray-300">Story Details</h2>
                <div class="space-y-4">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                            Title <span class="font-normal text-gray-400">(optional)</span>
                        </label>
                        <input
                            type="text"
                            name="title"
                            value="{{ old('title', $story->title) }}"
                            placeholder="Untitled Story"
                            class="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-800 placeholder-gray-400 focus:border-blue-400 focus:outline-none focus:ring-1 focus:ring-blue-400 dark:border-zinc-600 dark:bg-zinc-700 dark:text-gray-200"
                        />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Author name</label>
                        <input
                            type="text"
                            name="author_name"
                            value="{{ old('author_name', $story->author_name ?? auth()->user()->name) }}"
                            placeholder="{{ auth()->user()->name }}"
                            class="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-800 placeholder-gray-400 focus:border-blue-400 focus:outline-none focus:ring-1 focus:ring-blue-400 dark:border-zinc-600 dark:bg-zinc-700 dark:text-gray-200"
                        />
                        <p class="mt-1 text-xs text-gray-400">Displayed as the author on the story page. Use a pen name if you like.</p>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Genre</label>
                        <select
                            name="genre"
                            class="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-800 focus:border-blue-400 focus:outline-none focus:ring-1 focus:ring-blue-400 dark:border-zinc-600 dark:bg-zinc-700 dark:text-gray-200"
                        >
                            <option value="">No genre</option>
                            <option value="fantasy"           {{ old('genre', $story->genre) === 'fantasy'            ? 'selected' : '' }}>Fantasy</option>
                            <option value="science fiction"   {{ old('genre', $story->genre) === 'science fiction'    ? 'selected' : '' }}>Science Fiction</option>
                            <option value="romance"           {{ old('genre', $story->genre) === 'romance'            ? 'selected' : '' }}>Romance</option>
                            <option value="mystery"           {{ old('genre', $story->genre) === 'mystery'            ? 'selected' : '' }}>Mystery / Thriller</option>
                            <option value="horror"            {{ old('genre', $story->genre) === 'horror'             ? 'selected' : '' }}>Horror</option>
                            <option value="historical fiction"{{ old('genre', $story->genre) === 'historical fiction' ? 'selected' : '' }}>Historical Fiction</option>
                            <option value="non-fiction"       {{ old('genre', $story->genre) === 'non-fiction'        ? 'selected' : '' }}>Non-Fiction</option>
                            <option value="screenplay"        {{ old('genre', $story->genre) === 'screenplay'         ? 'selected' : '' }}>Screenplay</option>
                        </select>
                    </div>

                    {{-- Privacy Toggle --}}
                    <div x-data="{ isPrivate: {{ old('is_private', $story->is_private) ? 'true' : 'false' }} }" class="flex items-center justify-between rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 dark:border-zinc-600 dark:bg-zinc-800">
                        <div>
                            <p class="text-sm font-medium text-gray-700 dark:text-gray-200" x-text="isPrivate ? 'Private story' : 'Public story'"></p>
                            <p class="text-xs text-gray-400" x-text="isPrivate ? 'Only visible to you.' : 'Visible to everyone on the homepage.'"></p>
                        </div>
                        <label class="relative inline-flex cursor-pointer items-center">
                            <input type="checkbox" name="is_private" value="1" x-model="isPrivate" {{ old('is_private', $story->is_private) ? 'checked' : '' }} class="peer sr-only">
                            <div class="peer relative h-6 w-11 rounded-full bg-gray-300 transition-colors after:absolute after:left-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:transition-transform peer-checked:bg-blue-500 peer-checked:after:translate-x-5 dark:bg-zinc-600"></div>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Content -->
            <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Story Content</h2>
                    <span class="text-xs text-gray-400">Markdown supported</span>
                </div>
                <textarea
                    name="content"
                    rows="30"
                    placeholder="Your story content…"
                    class="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 font-mono text-sm leading-relaxed text-gray-800 placeholder-gray-400 focus:border-blue-400 focus:outline-none focus:ring-1 focus:ring-blue-400 dark:border-zinc-600 dark:bg-zinc-700 dark:text-gray-200"
                >{{ old('content', $story->content) }}</textarea>
            </div>

            <!-- Actions -->
            <div class="flex items-center justify-between">
                <button
                    type="submit"
                    class="cursor-pointer rounded-lg bg-blue-500 px-6 py-2.5 text-sm font-medium text-white transition-colors hover:bg-blue-600"
                >
                    Save changes
                </button>
            </div>

        </form>

        <!-- Delete Form (separate from edit form) -->
        <div class="mt-6 flex justify-end">
            <form action="{{ route('books.destroy', $story) }}" method="POST">
                @csrf
                @method('DELETE')
                <button type="submit"
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-red-200 px-4 py-2.5 text-sm font-medium text-red-600 transition-colors hover:bg-red-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-900/20"
                    onclick="return confirm('Are you sure you want to delete this story?')"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                    </svg>
                    Delete story
                </button>
            </form>
        </div>

        <!-- Hidden form for regenerate cover (outside main form) -->
        <form id="regenerate-form" action="{{ route('books.regenerate-cover', $story) }}" method="POST" class="hidden">
            @csrf
        </form>

    </div>
</x-layouts::writer>
