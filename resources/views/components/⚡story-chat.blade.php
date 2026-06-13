<?php

use App\Ai\Agents\StoryAgent;
use App\Models\Story;
use App\Models\StoryMessage;
use Livewire\Component;

new class extends Component
{
    public Story $story;
    public string $input = '';
    public bool $thinking = false;

    public function setInput(string $text): void
    {
        $this->input = $text;
    }

    public function mount(Story $story): void
    {
        $this->story = $story;
    }

    public function acceptChanges(int $messageId): void
    {
        $message = \App\Models\StoryMessage::find($messageId);
        if (! $message || $message->story_id !== $this->story->id || $message->role !== 'assistant') {
            return;
        }
        if ($message->accepted_at || $message->declined_at) {
            return;
        }

        $separator = $this->story->content ? "\n\n---\n\n" : '';
        $this->story->update(['content' => $this->story->content . $separator . $message->content]);
        $message->update(['accepted_at' => now()]);
        $this->story->refresh();
        $this->dispatch('story-content-updated');
    }

    public function declineChanges(int $messageId): void
    {
        $message = \App\Models\StoryMessage::find($messageId);
        if (! $message || $message->story_id !== $this->story->id || $message->role !== 'assistant') {
            return;
        }
        if ($message->accepted_at || $message->declined_at) {
            return;
        }

        $message->update(['declined_at' => now()]);
    }

    public function done(): void
    {
        $this->dispatch('story-complete');
        $this->redirect(route('books.index'));
    }

    public function send(): void
    {
        $this->validate(['input' => 'required|min:2']);

        $userMessage = trim($this->input);
        $this->input = '';
        $this->thinking = true;

        StoryMessage::create([
            'story_id' => $this->story->id,
            'role'     => 'user',
            'content'  => $userMessage,
        ]);

        try {
            $response = (new StoryAgent($this->story->fresh()))->prompt($userMessage);

            StoryMessage::create([
                'story_id' => $this->story->id,
                'role'     => 'assistant',
                'content'  => $response->text,
            ]);
        } catch (\Throwable $e) {
            StoryMessage::create([
                'story_id' => $this->story->id,
                'role'     => 'assistant',
                'content'  => 'Sorry, something went wrong. Please try again.',
            ]);
        }

        $this->thinking = false;
        $this->story->refresh();
        $this->dispatch('chat-updated');
    }
};
?>

<div
    x-data
    x-on:chat-updated.window="$nextTick(() => { const el = $refs.messages; if (el) el.scrollTop = el.scrollHeight; })"
    class="flex flex-col rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800"
>
    {{-- Header --}}
    <div class="flex items-center gap-2 border-b border-gray-100 px-5 py-4 dark:border-zinc-700">
        <div class="flex size-7 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900/40">
            <svg xmlns="http://www.w3.org/2000/svg" class="size-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" />
            </svg>
        </div>
        <div>
            <p class="text-sm font-semibold text-gray-800 dark:text-white">Continue with your Writing Coach</p>
            <p class="text-xs text-gray-400">Answer questions, add detail, or ask your coach to keep writing</p>
        </div>
    </div>

    {{-- Messages --}}
    <div
        x-ref="messages"
        class="flex max-h-[32rem] flex-col gap-4 overflow-y-auto p-5"
        x-init="$el.scrollTop = $el.scrollHeight"
    >
        @forelse ($story->messages as $message)
            <div class="flex {{ $message->role === 'user' ? 'justify-end' : 'justify-start' }} gap-2">
                @if ($message->role === 'assistant')
                    <div class="flex size-7 shrink-0 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900/40 mt-0.5">
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-3.5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" />
                        </svg>
                    </div>
                @endif

                <div class="max-w-[85%] rounded-2xl px-4 py-3 text-sm leading-relaxed
                    {{ $message->role === 'user'
                        ? 'rounded-tr-sm bg-blue-500 text-white'
                        : 'rounded-tl-sm bg-gray-100 text-gray-800 dark:bg-zinc-700 dark:text-gray-200' }}">
                    @if ($message->role === 'assistant')
                        <div class="prose prose-sm dark:prose-invert max-w-none
                                    prose-p:my-1 prose-headings:mt-2 prose-headings:mb-1
                                    prose-p:text-gray-800 dark:prose-p:text-gray-200">
                            {!! Str::markdown($message->content) !!}
                        </div>
                        {{-- Accept / Decline actions --}}
                        @if ($message->accepted_at)
                            <div class="mt-3 flex items-center gap-1.5 rounded-lg bg-green-50 px-3 py-2 text-xs text-green-700 dark:bg-green-900/20 dark:text-green-400">
                                <svg xmlns="http://www.w3.org/2000/svg" class="size-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                <span><strong>Accepted</strong> — this response was appended to your story on {{ $message->accepted_at->format('M j \a\t g:i a') }}.</span>
                            </div>
                        @elseif ($message->declined_at)
                            <div class="mt-3 flex items-center gap-1.5 rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-500 dark:bg-zinc-700/50 dark:text-gray-400">
                                <svg xmlns="http://www.w3.org/2000/svg" class="size-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                <span><strong>Declined</strong> — this response was not added to your story. You can keep chatting to ask Claude to try again.</span>
                            </div>
                        @else
                            <div class="mt-3 space-y-2">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Do you want to add Claude&rsquo;s response above to your story?</p>
                                <div class="flex items-center gap-2">
                                    <button
                                        wire:click="acceptChanges({{ $message->id }})"
                                        wire:loading.attr="disabled"
                                        title="Append this response to the end of your story"
                                        class="inline-flex items-center gap-1.5 rounded-lg bg-green-500 px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-green-600 disabled:opacity-50"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                        Yes, add to story
                                    </button>
                                    <button
                                        wire:click="declineChanges({{ $message->id }})"
                                        wire:loading.attr="disabled"
                                        title="Discard this response — your story won't be changed"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-600 transition-colors hover:bg-gray-50 disabled:opacity-50 dark:border-zinc-600 dark:bg-zinc-700 dark:text-gray-300 dark:hover:bg-zinc-600"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                        No, discard
                                    </button>
                                </div>
                            </div>
                        @endif
                    @else
                        {{ $message->content }}
                    @endif
                </div>
            </div>
        @empty
            <div class="py-6 text-center text-sm text-gray-400 dark:text-gray-500">
                No conversation yet — start by answering Claude&rsquo;s questions above or ask it to continue writing.
            </div>
        @endforelse

        @if ($thinking)
            <div class="flex justify-start gap-2">
                <div class="flex size-7 shrink-0 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900/40 mt-0.5">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-3.5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" />
                    </svg>
                </div>
                <div class="rounded-2xl rounded-tl-sm bg-gray-100 px-4 py-3 dark:bg-zinc-700">
                    <div class="flex items-center gap-1">
                        <span class="size-1.5 rounded-full bg-gray-400 animate-bounce [animation-delay:-0.3s]"></span>
                        <span class="size-1.5 rounded-full bg-gray-400 animate-bounce [animation-delay:-0.15s]"></span>
                        <span class="size-1.5 rounded-full bg-gray-400 animate-bounce"></span>
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- Input --}}
    <div class="border-t border-gray-100 p-4 dark:border-zinc-700 space-y-3">
        <form wire:submit="send" class="flex items-end gap-3">
            <textarea
                wire:model="input"
                rows="3"
                placeholder="🎤 Tap here to speak or type your response…"
                class="flex-1 resize-none rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-base text-gray-800 placeholder-gray-400 focus:border-blue-400 focus:outline-none focus:ring-1 focus:ring-blue-400 dark:border-zinc-600 dark:bg-zinc-700 dark:text-gray-200"
                x-on:keydown.cmd.enter="$wire.send()"
                x-on:keydown.ctrl.enter="$wire.send()"
                x-on:coach-suggestion.window="$wire.setInput($event.detail.text); $el.focus()"
                wire:loading.attr="disabled"
            ></textarea>
            <button
                type="submit"
                wire:loading.attr="disabled"
                class="flex size-14 shrink-0 items-center justify-center rounded-xl bg-blue-500 text-white transition-colors hover:bg-blue-600 disabled:opacity-50 cursor-pointer"
            >
                <svg wire:loading.remove wire:target="send" xmlns="http://www.w3.org/2000/svg" class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                </svg>
                <svg wire:loading wire:target="send" class="size-6 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 12 0 12 12h4c0 2.239-.611 4.326-1.636 5.955L12 16.5V20H8v-3.5L5.636 17.955A9.953 9.953 0 014 12z"></path>
                </svg>
            </button>
        </form>

        <p class="text-center text-xs text-gray-400">💡 Tap a suggestion above, or type your own response</p>
    </div>
</div>