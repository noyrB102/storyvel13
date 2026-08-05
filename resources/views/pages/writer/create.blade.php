<x-layouts::writer :title="__('Create')">
    <div class="flex min-h-[calc(100vh-4rem)] flex-col items-center justify-center px-4 py-16">
        <div class="mb-6 w-full max-w-2xl">
            <a href="{{ route('books.index') }}" wire:navigate
               class="inline-flex items-center gap-1.5 text-sm text-gray-400 transition-colors hover:text-gray-600 dark:hover:text-gray-300">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                </svg>
                Back to Home page<br>(My Stories)
            </a>
        </div>
        <livewire:create-story />
    </div>
</x-layouts::writer>
