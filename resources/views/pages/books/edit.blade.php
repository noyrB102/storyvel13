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

        <form action="{{ route('books.update', $story) }}" method="POST" enctype="multipart/form-data" class="space-y-6">
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

            <!-- AI Story Editor -->
            <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800"
                 x-data="{
                    instruction: '',
                    status: '',
                    undoContent: null,
                    undoTimer: null,
                    speaking: false,
                    fitOnePage: false,
                    storyPreview: {{ json_encode(old('content', $story->content)) }},
                    csrfToken: '{{ csrf_token() }}',
                    aiEditUrl: '{{ route('books.ai-edit', $story) }}',
                    restoreUrl: '{{ route('books.restore', $story) }}',
                    async submit(type) {
                        if (!this.instruction.trim()) return;
                        this.status = 'loading';
                        window.speechSynthesis.cancel();
                        this.speaking = false;
                        const textarea = document.getElementById('story-content-textarea');
                        this.undoContent = textarea ? textarea.value : null;
                        try {
                            const res = await fetch(this.aiEditUrl, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken },
                                body: JSON.stringify({ type: type, instruction: this.instruction, fit_one_page: this.fitOnePage })
                            });
                            if (!res.ok) { this.status = 'error'; return; }
                            const data = await res.json();
                            if (textarea) textarea.value = data.content;
                            this.storyPreview = data.content;
                            window.dispatchEvent(new CustomEvent('story-updated', { detail: data.content }));
                            this.status = 'saved';
                            this.instruction = '';
                            clearTimeout(this.undoTimer);
                            this.undoTimer = setTimeout(() => { this.undoContent = null; }, 60000);
                        } catch { this.status = 'error'; }
                    },
                    async undo() {
                        if (this.undoContent === null) return;
                        const previous = this.undoContent;
                        const textarea = document.getElementById('story-content-textarea');
                        if (textarea) textarea.value = previous;
                        this.storyPreview = previous;
                        this.undoContent = null;
                        this.status = '';
                        window.speechSynthesis.cancel();
                        this.speaking = false;
                        clearTimeout(this.undoTimer);
                        try {
                            await fetch(this.restoreUrl, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken },
                                body: JSON.stringify({ content: previous })
                            });
                        } catch {}
                    },
                    readAloud() {
                        window.speechSynthesis.cancel();
                        const u = new SpeechSynthesisUtterance(this.storyPreview.replace(/#+\s/g, '').replace(/\*\*/g, ''));
                        u.rate = 0.9;
                        u.onend = () => { this.speaking = false; };
                        window.speechSynthesis.speak(u);
                        this.speaking = true;
                    },
                    stopReading() { window.speechSynthesis.cancel(); this.speaking = false; }
                 }">

                <h2 class="mb-1 text-sm font-semibold text-gray-700 dark:text-gray-300">Edit Your Story</h2>
                <p class="mb-4 text-xs text-gray-400">Tell the AI what you'd like to change — no typing into the story needed.</p>

                {{-- Status messages --}}
                <div x-show="status === 'saved'" class="mb-4 flex items-center justify-between rounded-xl bg-green-50 px-4 py-3 dark:bg-green-900/20">
                    <span class="text-sm font-medium text-green-700 dark:text-green-400">✅ Done! Your story has been updated.</span>
                    <button type="button" @click="undo()" x-show="undoContent !== null"
                        class="ml-4 rounded-lg border border-green-300 px-3 py-1 text-xs font-semibold text-green-700 hover:bg-green-100 dark:border-green-600 dark:text-green-400">
                        ↩ Undo
                    </button>
                </div>
                <div x-show="status === 'error'" class="mb-4 rounded-xl bg-red-50 px-4 py-3 text-sm font-medium text-red-600 dark:bg-red-900/20 dark:text-red-400">
                    ❌ Something went wrong — please try again.
                </div>

                {{-- AI Edit input -- always visible --}}
                <div class="mb-5">
                    <p class="mb-2 text-lg font-medium text-gray-700 dark:text-gray-300 flex items-center gap-2">
                        <span class="text-2xl">✏️</span>
                        What would you like to change?
                    </p>
                    <p class="mb-3 text-sm text-gray-400">Example: <em>"Reduce words by 200"</em>, <em>"Change spelling of something"</em>, <em>"Make my story longer"</em>, or <em>"Add more details"</em></p>
                    
                    {{-- Quick action chips --}}
                    <div class="mb-4 flex flex-wrap gap-2">
                        <button type="button" @click="fitOnePage = false; instruction = 'Make my story shorter'"
                            class="rounded-full border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-zinc-600 dark:bg-zinc-700 dark:text-gray-300">
                            Make it shorter
                        </button>
                        <button type="button" @click="fitOnePage = false; instruction = 'Add more details to my story'"
                            class="rounded-full border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-zinc-600 dark:bg-zinc-700 dark:text-gray-300">
                            Add more detail
                        </button>
                        <button type="button" @click="fitOnePage = false; instruction = 'Make my story longer'"
                            class="rounded-full border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-zinc-600 dark:bg-zinc-700 dark:text-gray-300">
                            Make it longer
                        </button>
                        <button type="button" @click="fitOnePage = true; instruction = 'Make this story fit on one printed page (about 300–750 words)'"
                            class="rounded-full border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-zinc-600 dark:bg-zinc-700 dark:text-gray-300">
                            📄 Fit 1 page
                        </button>
                    </div>
                    
                    <textarea x-model="instruction" rows="4"
                        autocapitalize="sentences" autocorrect="on" spellcheck="true"
                        class="w-full rounded-xl border border-purple-300 bg-white px-4 py-4 text-lg text-gray-800 focus:border-purple-400 focus:outline-none focus:ring-1 focus:ring-purple-400 dark:border-purple-600 dark:bg-zinc-800 dark:text-gray-200"></textarea>
                    <button type="button" @click="submit('add_remove')" :disabled="status === 'loading' || !instruction.trim()"
                        class="mt-3 w-full rounded-xl bg-purple-500 px-4 py-4 text-lg font-bold text-white disabled:opacity-50 hover:bg-purple-600"
                        x-text="status === 'loading' ? '⏳ Making the change…' : '✅ Make This Change'">
                    </button>
                </div>

                {{-- Always-present hidden textarea for form submit — AI edits update this directly --}}
                <textarea id="story-content-textarea" name="content" class="sr-only">{{ old('content', $story->content) }}</textarea>

                {{-- Read Aloud row --}}
                <div class="flex gap-2">
                    <button type="button" x-show="!speaking" @click="readAloud()"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-purple-100 border border-purple-300 px-4 py-2.5 text-sm font-semibold text-purple-700 hover:bg-purple-200 dark:bg-purple-900/30 dark:text-purple-300">
                        🔊 Read Aloud
                    </button>
                    <button type="button" x-show="speaking" @click="stopReading()"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-purple-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-purple-700">
                        ⏹ Stop
                    </button>
                </div>

                {{-- View/Edit story text row --}}
                <div class="mt-3" x-data="{ open: false }"
                     x-on:story-updated.window="$el.querySelector('textarea') && ($el.querySelector('textarea').value = $event.detail)">
                    <button type="button" @click="open = !open"
                        class="flex w-full items-center justify-center gap-2 rounded-xl border border-gray-200 px-4 py-2.5 text-sm text-gray-500 hover:bg-gray-50 dark:border-zinc-600 dark:text-gray-400 dark:hover:bg-zinc-700">
                        <span x-text="open ? '▲ Hide story text editor' : '📄 View or manually edit the full story text'"></span>
                    </button>
                    <div x-show="open" x-transition class="mt-3">
                        <p class="mb-2 text-xs text-gray-400">Tip: use the AI panel above for easier editing. Changes here are saved when you tap "Save changes" below.</p>
                        <textarea
                            id="story-manual-textarea"
                            @input="document.getElementById('story-content-textarea').value = $event.target.value"
                            rows="30"
                            placeholder="Your story content…"
                            class="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 font-mono text-sm leading-relaxed text-gray-800 placeholder-gray-400 focus:border-blue-400 focus:outline-none focus:ring-1 focus:ring-blue-400 dark:border-zinc-600 dark:bg-zinc-700 dark:text-gray-200"
                        >{{ old('content', $story->content) }}</textarea>
                    </div>
                </div>
            </div>

            <!-- Cover Image -->
            <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <h2 class="mb-4 text-sm font-semibold text-gray-700 dark:text-gray-300">Cover Image</h2>
                <div class="flex items-start gap-5"
                     x-data="{
                         thumbnail: '{{ $story->cover_image_path ? Storage::url($story->cover_image_path) . '?v=' . Storage::disk('public')->lastModified($story->cover_image_path) : '' }}',
                         initialVersion: {{ $story->cover_image_path && Storage::disk('public')->exists($story->cover_image_path) ? Storage::disk('public')->lastModified($story->cover_image_path) : 0 }},
                         polling: {{ (session('success') && str_contains(strtolower(session('success')), 'regeneration')) ? 'true' : 'false' }},
                         async checkCover() {
                             const res = await fetch('{{ route('books.cover-status', $story) }}');
                             const data = await res.json();
                             if (!data.url || !data.version) return;
                             if (data.version !== this.initialVersion) {
                                 const newSrc = data.url + '?v=' + data.version;
                                 this.thumbnail = newSrc;
                                 this.initialVersion = data.version;
                                 window.dispatchEvent(new CustomEvent('cover-updated', { detail: newSrc }));
                             }
                         }
                     }"
                     x-init="
                         if (polling) {
                             const interval = setInterval(() => checkCover(), 5000);
                             setTimeout(() => clearInterval(interval), 120000);
                         }
                     "
                     @cover-updated.window="thumbnail = $event.detail">
                    <template x-if="thumbnail">
                        <img :src="thumbnail" alt="Cover" class="h-32 w-24 rounded-2xl object-contain" />
                    </template>
                    <template x-if="!thumbnail">
                        <div class="flex h-32 w-24 items-center justify-center rounded-2xl bg-gray-100 dark:bg-zinc-700">
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-8 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
                            </svg>
                        </div>
                    </template>
                    <div class="flex-1">
                        <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
                            Cover images can be AI-generated using DALL-E, or you can upload your own photo from your phone.
                        </p>

                        {{-- Upload custom cover — auto-saves immediately on select --}}
                        <div class="mb-4"
                             x-data="{
                                status: '',
                                preview: '{{ $story->cover_image_path ? Storage::disk('public')->url($story->cover_image_path) . '?v=' . Storage::disk('public')->lastModified($story->cover_image_path) : '' }}',
                                async handleFile(e) {
                                    const file = e.target.files[0];
                                    if (!file) return;
                                    this.status = 'saving';
                                    const MAX = 1.4 * 1024 * 1024;
                                    const getB64 = (f) => new Promise(resolve => {
                                        if (f.size <= MAX) {
                                            const r = new FileReader();
                                            r.onload = ev => resolve(ev.target.result);
                                            r.readAsDataURL(f);
                                            return;
                                        }
                                        const img = new Image();
                                        const url = URL.createObjectURL(f);
                                        img.onload = () => {
                                            URL.revokeObjectURL(url);
                                            let w = img.width, h = img.height, q = 0.85;
                                            const canvas = document.createElement('canvas');
                                            const try_ = () => {
                                                canvas.width = w; canvas.height = h;
                                                canvas.getContext('2d').drawImage(img, 0, 0, w, h);
                                                canvas.toBlob(b => {
                                                    if (!b) { resolve(null); return; }
                                                    if (b.size > MAX && q > 0.3) { q -= 0.1; try_(); return; }
                                                    if (b.size > MAX) { w = Math.round(w*0.85); h = Math.round(h*0.85); q = 0.75; try_(); return; }
                                                    const r = new FileReader();
                                                    r.onload = ev => resolve(ev.target.result);
                                                    r.readAsDataURL(b);
                                                }, 'image/jpeg', q);
                                            };
                                            try_();
                                        };
                                        img.src = url;
                                    });
                                    const b64 = await getB64(file);
                                    if (!b64) { this.status = 'error'; return; }
                                    this.preview = b64;
                                    window.dispatchEvent(new CustomEvent('cover-updated', { detail: b64 }));
                                    const fd = new FormData();
                                    fd.append('_method', 'PUT');
                                    fd.append('_token', document.querySelector('meta[name=csrf-token]')?.content || '{{ csrf_token() }}');
                                    fd.append('cover_image_b64', b64);
                                    try {
                                        const res = await fetch('{{ route('books.update', $story) }}', { method: 'POST', body: fd });
                                        this.status = res.ok ? 'saved' : 'error';
                                    } catch { this.status = 'error'; }
                                }
                             }">
                            <label class="mb-2 block text-xs font-medium text-gray-600 dark:text-gray-400">Upload your own photo</label>
                            {{-- Live preview --}}
                            <div x-show="preview" class="mb-3 overflow-hidden rounded-2xl">
                                <img :src="preview" class="h-36 w-full object-contain" alt="Cover preview">
                            </div>
                            <label class="flex w-full cursor-pointer items-center justify-center gap-2 rounded-xl border-2 border-dashed border-blue-300 bg-blue-50 px-4 py-4 text-sm font-semibold text-blue-600 hover:bg-blue-100 active:bg-blue-200 dark:border-blue-700 dark:bg-blue-900/20 dark:text-blue-400">
                                <svg xmlns="http://www.w3.org/2000/svg" class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
                                </svg>
                                <span x-text="status === 'saving' ? 'Saving photo…' : '📷 Tap to choose a photo'"></span>
                                <input type="file" accept="image/*" class="sr-only" @change="handleFile($event)" :disabled="status === 'saving'" />
                            </label>
                            <p x-show="status === 'saving'" class="mt-2 text-center text-sm font-medium text-blue-600">⏳ Saving your photo…</p>
                            <p x-show="status === 'saved'" class="mt-2 text-center text-sm font-medium text-green-600">✅ Cover photo saved!</p>
                            <p x-show="status === 'error'" class="mt-2 text-center text-sm font-medium text-red-500">❌ Something went wrong — please try again</p>
                        </div>

                        <div class="flex items-center gap-2">
                            <span class="text-xs text-gray-400">— or —</span>
                        </div>

                        {{-- Regenerate AI cover --}}
                        <div x-data="{ generating: {{ (session('success') && str_contains(strtolower(session('success')), 'regeneration')) ? 'true' : 'false' }} }"
                             x-init="if (generating) { setTimeout(() => { generating = false; }, 120000); }"
                             @cover-updated.window="generating = false">
                            <button type="button"
                                @click="generating = true; setTimeout(() => { generating = false; }, 120000); document.getElementById('regenerate-form').submit()"
                                :disabled="generating"
                                class="mt-3 inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 disabled:opacity-60 disabled:cursor-not-allowed dark:border-zinc-600 dark:bg-zinc-700 dark:text-gray-300 dark:hover:bg-zinc-600"
                            >
                                <svg x-show="!generating" xmlns="http://www.w3.org/2000/svg" class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                                </svg>
                                <svg x-show="generating" class="size-3.5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                                <span x-text="generating ? 'Generating cover… (this takes ~30 sec)' : 'Regenerate AI cover'"></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            
            <!-- Title + Story Details -->
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
                    <div class="hidden">
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

                <button type="submit" class="mt-2 w-full cursor-pointer rounded-lg bg-blue-500 px-6 py-3 text-sm font-medium text-white transition-colors hover:bg-blue-600">
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
