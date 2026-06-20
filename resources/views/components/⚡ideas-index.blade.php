<?php

use App\Models\Idea;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $filterStatus = 'all';

    // Form fields
    public ?int $editingId = null;
    public bool $showForm = false;
    public string $title = '';
    public string $content = '';
    public string $genre = '';
    public string $status = 'draft';
    public int $priority = 0;

    // Quick add
    public string $quickContent = '';

    protected function rules(): array
    {
        return [
            'title'   => 'nullable|string|max:255',
            'content' => 'required|string|min:5',
            'genre'   => 'nullable|string|max:100',
            'status'  => 'required|in:draft,developing,ready,archived',
            'priority'=> 'integer|min:0|max:3',
        ];
    }

    public function getIdeasProperty()
    {
        $query = Idea::forUser(auth()->id())
            ->when($this->filterStatus !== 'all', fn($q) => $q->where('status', $this->filterStatus))
            ->when($this->search, fn($q) => $q->where(function($sq) {
                $sq->where('title', 'like', "%{$this->search}%")
                   ->orWhere('content', 'like', "%{$this->search}%");
            }))
            ->byPriority();

        return $query->get();
    }

    public function quickAdd(): void
    {
        if (empty(trim($this->quickContent))) {
            return;
        }

        Idea::create([
            'user_id'  => auth()->id(),
            'title'    => null,
            'content'  => $this->quickContent,
            'status'   => 'draft',
            'genre'    => '',
            'priority' => 0,
        ]);

        $this->quickContent = '';
        $this->dispatch('idea-saved');
    }

    public function startEdit(Idea $idea): void
    {
        abort_if($idea->user_id !== auth()->id(), 403);

        $this->editingId = $idea->id;
        $this->title = $idea->title ?? '';
        $this->content = $idea->content;
        $this->genre = $idea->genre ?? '';
        $this->status = $idea->status;
        $this->priority = $idea->priority;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate();

        if ($this->editingId) {
            $idea = Idea::findOrFail($this->editingId);
            abort_if($idea->user_id !== auth()->id(), 403);
            $idea->update([
                'title'    => $this->title ?: null,
                'content'  => $this->content,
                'genre'    => $this->genre ?: null,
                'status'   => $this->status,
                'priority' => $this->priority,
            ]);
        } else {
            Idea::create([
                'user_id'  => auth()->id(),
                'title'    => $this->title ?: null,
                'content'  => $this->content,
                'genre'    => $this->genre ?: null,
                'status'   => $this->status,
                'priority' => $this->priority,
            ]);
        }

        $this->resetForm();
        $this->dispatch('idea-saved');
    }

    public function delete(Idea $idea): void
    {
        abort_if($idea->user_id !== auth()->id(), 403);
        $idea->delete();
    }

    public function toggleStar(Idea $idea): void
    {
        abort_if($idea->user_id !== auth()->id(), 403);
        $idea->update(['priority' => $idea->priority > 0 ? 0 : 1]);
    }

    public function archive(Idea $idea): void
    {
        abort_if($idea->user_id !== auth()->id(), 403);
        $newStatus = $idea->status === 'archived' ? 'draft' : 'archived';
        $idea->update(['status' => $newStatus]);
    }

    public function resetForm(): void
    {
        $this->editingId = null;
        $this->showForm = false;
        $this->title = '';
        $this->content = '';
        $this->genre = '';
        $this->status = 'draft';
        $this->priority = 0;
        $this->quickContent = '';
    }

    public function startStory(Idea $idea): void
    {
        abort_if($idea->user_id !== auth()->id(), 403);
        $this->redirect(route('writer.create', [
            'prompt' => $idea->content,
            'title'  => $idea->title,
            'genre'  => $idea->genre,
        ]), navigate: true);
    }
};
?>

<div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
    {{-- Header --}}
    <div class="mb-8">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Ideas & Scratchpad</h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Capture story concepts — from fleeting thoughts to detailed outlines. Come back anytime to develop them.
        </p>
    </div>

    {{-- Quick Add Bar --}}
    <div class="mb-8 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800/40 dark:bg-amber-900/10">
        <form wire:submit="quickAdd" class="flex flex-col gap-3">
            <div>
                <label class="mb-1 block text-xs font-medium text-amber-700 dark:text-amber-400">
                    Quick capture — jot down a thought
                </label>
                <textarea
                    wire:model="quickContent"
                    rows="2"
                    placeholder="A story about... (just start typing, details come later)"
                    class="w-full resize-none rounded-lg border border-amber-200 bg-white px-3 py-2 text-sm text-gray-800 placeholder-gray-400 focus:border-amber-400 focus:outline-none dark:border-amber-800 dark:bg-zinc-800 dark:text-gray-200"
                ></textarea>
            </div>
            <button
                type="submit"
                wire:loading.attr="disabled"
                class="flex w-full items-center justify-center gap-1.5 rounded-lg bg-amber-500 px-4 py-3 text-sm font-medium text-white transition-colors hover:bg-amber-600 disabled:opacity-60"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                <span wire:loading.remove wire:target="quickAdd">Save Idea</span>
                <span wire:loading wire:target="quickAdd">Saving...</span>
            </button>
        </form>
    </div>

    {{-- Filters & Add Full --}}
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <select
                wire:model.live="filterStatus"
                class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 focus:border-blue-400 focus:outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-gray-200"
            >
                <option value="all">All Ideas</option>
                <option value="draft">Drafts</option>
                <option value="developing">Developing</option>
                <option value="ready">Ready to Write</option>
                <option value="archived">Archived</option>
            </select>
            <input
                type="text"
                wire:model.live.debounce.200ms="search"
                placeholder="Search ideas..."
                class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 placeholder-gray-400 focus:border-blue-400 focus:outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-gray-200"
            />
        </div>
        <button
            wire:click="$set('showForm', true)"
            class="flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-gray-300"
        >
            <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            New Full Idea
        </button>
    </div>

    {{-- Full Form (when adding/editing) --}}
    @if ($showForm)
        <div class="mb-8 rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-800" x-data x-init="$el.scrollIntoView({behavior:'smooth'})">
            <h3 class="mb-4 text-sm font-semibold text-gray-900 dark:text-white">
                {{ $editingId ? 'Edit Idea' : 'New Idea' }}
            </h3>
            <form wire:submit="save" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">Title <span class="text-gray-400">(optional)</span></label>
                        <input
                            type="text"
                            wire:model="title"
                            placeholder="Give it a name..."
                            class="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-800 focus:border-blue-400 focus:outline-none dark:border-zinc-600 dark:bg-zinc-700 dark:text-gray-200"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">Genre <span class="text-gray-400">(optional)</span></label>
                        <input
                            type="text"
                            wire:model="genre"
                            placeholder="e.g., Literary Fiction"
                            class="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-800 focus:border-blue-400 focus:outline-none dark:border-zinc-600 dark:bg-zinc-700 dark:text-gray-200"
                        />
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">Idea Details <span class="text-red-400">*</span></label>
                    <textarea
                        wire:model="content"
                        rows="5"
                        placeholder="Describe your story idea. What happens? Who is in it? What's the feeling you want? The more you write now, the easier it will be later..."
                        class="w-full resize-none rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-800 placeholder-gray-400 focus:border-blue-400 focus:outline-none dark:border-zinc-600 dark:bg-zinc-700 dark:text-gray-200"
                    ></textarea>
                    @error('content')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">Status</label>
                        <select
                            wire:model="status"
                            class="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-800 focus:border-blue-400 focus:outline-none dark:border-zinc-600 dark:bg-zinc-700 dark:text-gray-200"
                        >
                            <option value="draft">Draft — just captured</option>
                            <option value="developing">Developing — working on it</option>
                            <option value="ready">Ready — time to write</option>
                            <option value="archived">Archived — shelved for now</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">Priority</label>
                        <div class="flex items-center gap-4">
                            <label class="flex items-center gap-2">
                                <input type="radio" wire:model="priority" :value="0" class="text-blue-500 focus:ring-blue-400" />
                                <span class="text-sm text-gray-700 dark:text-gray-300">Normal</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="radio" wire:model="priority" :value="1" class="text-amber-500 focus:ring-amber-400" />
                                <span class="text-sm text-gray-700 dark:text-gray-300">Starred ⭐</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-2">
                    <button
                        type="button"
                        wire:click="resetForm"
                        class="rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-600 transition-colors hover:bg-gray-50 dark:border-zinc-600 dark:bg-zinc-700 dark:text-gray-300"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        class="rounded-lg bg-blue-500 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-600 disabled:opacity-60"
                    >
                        <span wire:loading.remove wire:target="save">Save Idea</span>
                        <span wire:loading wire:target="save">Saving...</span>
                    </button>
                </div>
            </form>
        </div>
    @endif

    {{-- Ideas Grid --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" wire:poll.30s="">
        @forelse ($this->ideas as $idea)
            <div class="group flex flex-col rounded-xl border {{ $idea->priority > 0 ? 'border-amber-300 bg-amber-50/50 dark:border-amber-700 dark:bg-amber-900/10' : 'border-gray-200 bg-white dark:border-zinc-700 dark:bg-zinc-800' }} p-4 shadow-sm transition-shadow hover:shadow-md">
                {{-- Header --}}
                <div class="mb-3 flex items-start justify-between gap-2">
                    <div class="flex items-center gap-2">
                        @if ($idea->priority > 0)
                            <span class="text-amber-500">⭐</span>
                        @endif
                        @if ($idea->genre)
                            <span class="rounded-full bg-blue-50 px-2 py-0.5 text-xs text-blue-600 dark:bg-blue-900/30 dark:text-blue-400">
                                {{ $idea->genre }}
                            </span>
                        @endif
                    </div>
                    <div class="flex items-center gap-1 opacity-0 transition-opacity group-hover:opacity-100">
                        <button
                            wire:click="toggleStar({{ $idea->id }})"
                            title="{{ $idea->priority > 0 ? 'Unstar' : 'Star' }}"
                            class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-amber-500 dark:hover:bg-zinc-700"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="{{ $idea->priority > 0 ? 'currentColor' : 'none' }}" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z" />
                            </svg>
                        </button>
                        <button
                            wire:click="startEdit({{ $idea->id }})"
                            title="Edit"
                            class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-zinc-700 dark:hover:text-gray-300"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" />
                            </svg>
                        </button>
                        <button
                            wire:click="archive({{ $idea->id }})"
                            title="{{ $idea->status === 'archived' ? 'Unarchive' : 'Archive' }}"
                            class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-zinc-700 dark:hover:text-gray-300"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="{{ $idea->status === 'archived' ? 'currentColor' : 'none' }}" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z" />
                            </svg>
                        </button>
                        <button
                            wire:click="delete({{ $idea->id }})"
                            wire:confirm="Delete this idea?"
                            title="Delete"
                            class="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-500 dark:hover:bg-red-900/20"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                            </svg>
                        </button>
                    </div>
                </div>

                {{-- Content --}}
                <div class="mb-3 flex-1">
                    @if ($idea->title)
                        <h4 class="mb-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $idea->title }}</h4>
                    @endif
                    <p class="line-clamp-4 text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                        {{ $idea->excerpt(200) }}
                    </p>
                </div>

                {{-- Footer --}}
                <div class="flex items-center justify-between border-t border-gray-100 pt-3 dark:border-zinc-700">
                    <div class="flex items-center gap-2">
                        <span class="rounded px-1.5 py-0.5 text-xs font-medium
                            {{ match($idea->status) {
                                'draft' => 'bg-gray-100 text-gray-600 dark:bg-zinc-700 dark:text-gray-400',
                                'developing' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
                                'ready' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
                                'archived' => 'bg-gray-200 text-gray-500 dark:bg-zinc-700 dark:text-gray-500',
                                default => 'bg-gray-100 text-gray-600',
                            } }}">
                            {{ ucfirst($idea->status) }}
                        </span>
                        <span class="text-xs text-gray-400">{{ $idea->updated_at->diffForHumans() }}</span>
                    </div>
                    <button
                        wire:click="startStory({{ $idea->id }})"
                        class="rounded-lg bg-blue-500 px-2.5 py-1.5 text-xs font-medium text-white transition-colors hover:bg-blue-600"
                    >
                        Start Story →
                    </button>
                </div>
            </div>
        @empty
            <div class="col-span-full flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 bg-gray-50 py-16 text-center dark:border-zinc-700 dark:bg-zinc-800">
                <div class="mb-3 text-4xl">💡</div>
                <h3 class="text-sm font-medium text-gray-900 dark:text-white">No ideas yet</h3>
                <p class="mt-1 max-w-xs text-sm text-gray-500 dark:text-gray-400">
                    Use the quick capture box above to jot down your first story concept.
                </p>
            </div>
        @endforelse
    </div>
</div>