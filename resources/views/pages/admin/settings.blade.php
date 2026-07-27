<x-layouts::writer title="Admin · Settings">
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8">
            <div class="mb-2 flex items-center gap-2">
                <span class="rounded-full bg-violet-100 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-violet-700 dark:bg-violet-900/30 dark:text-violet-300">Admin only</span>
            </div>
            <h1 class="text-3xl font-bold tracking-tight text-gray-900 dark:text-white">Settings</h1>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Manage global application settings.</p>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
            <form method="POST" action="{{ route('admin.settings.update') }}">
                @csrf

                <div class="mb-6">
                    <label for="book_target_count" class="block text-sm font-semibold text-gray-900 dark:text-white">Book target count</label>
                    <p class="text-sm text-gray-500 dark:text-gray-400">The number of stories shown in every user's "My Next Book" (1–30).</p>
                    <input id="book_target_count" name="book_target_count" type="number" min="1" max="30" value="{{ $bookTargetCount }}" class="mt-2 w-32 rounded-xl border border-gray-300 px-4 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-white">
                    @error('book_target_count')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" class="rounded-xl bg-blue-600 px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">Save</button>
            </form>
        </div>
    </div>
</x-layouts::writer>
