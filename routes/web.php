<?php

use App\Jobs\GenerateCoverImage;
use App\Models\Idea;
use App\Models\Story;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('books.index');
    }
    return view('pages/welcome');
})->name('home');

// Public story viewing (no auth required)
Route::get('stories/{story}', function (Story $story) {
    // Allow if story is public and completed
    abort_if($story->is_private || $story->status !== 'completed', 404);
    return view('pages/books/show-public', compact('story'));
})->name('stories.public.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::view('create', 'pages/writer/create')->name('writer.create');
    Route::get('books', function () {
        return view('pages/books/index', [
            'stories' => Story::where('user_id', auth()->id())
                ->latest()
                ->get(),
        ]);
    })->name('books.index');

    Route::get('books/{story}', function (Story $story) {
        abort_if($story->user_id !== auth()->id(), 403);
        return view('pages/books/show', compact('story'));
    })->name('books.show');

    Route::get('books/{story}/edit', function (Story $story) {
        abort_if($story->user_id !== auth()->id(), 403);
        return view('pages/books/edit', compact('story'));
    })->name('books.edit');

    Route::put('books/{story}', function (Story $story, Request $request) {
        abort_if($story->user_id !== auth()->id(), 403);
        $data = $request->validate([
            'title'       => 'nullable|string|max:255',
            'author_name' => 'nullable|string|max:255',
            'genre'       => 'nullable|string|max:100',
            'content'     => 'nullable|string',
            'is_private'  => 'nullable|boolean',
            'cover_image' => 'nullable|image|max:5120', // Max 5MB
        ]);

        // Handle custom cover image upload
        if ($request->hasFile('cover_image')) {
            // Delete old cover if it exists
            if ($story->cover_image_path && !str_starts_with($story->cover_image_path, 'ai-covers/')) {
                Storage::disk('public')->delete($story->cover_image_path);
            }
            // Store new cover
            $path = $request->file('cover_image')->store('covers/' . $story->id, 'public');
            $data['cover_image_path'] = $path;
        }

        $story->update($data);
        return redirect()->route('books.show', $story)->with('success', 'Story saved.');
    })->name('books.update');

    Route::delete('books/{story}', function (Story $story) {
        abort_if($story->user_id !== auth()->id(), 403);
        $story->delete();
        return redirect()->route('books.index');
    })->name('books.destroy');

    Route::post('books/{story}/regenerate-cover', function (Story $story) {
        abort_if($story->user_id !== auth()->id(), 403);
        GenerateCoverImage::dispatch($story);
        return back()->with('success', 'Cover regeneration started.');
    })->name('books.regenerate-cover');

    // Download routes
    Route::get('books/{story}/download/pdf', [\App\Http\Controllers\DownloadController::class, 'downloadPdf'])
        ->name('books.download.pdf');

    Route::get('books/{story}/download/word', [\App\Http\Controllers\DownloadController::class, 'downloadWord'])
        ->name('books.download.word');

    Route::view('templates', 'pages/templates/index')->name('templates.index');

    Route::get('ideas', function () {
        return view('pages/ideas/index');
    })->name('ideas.index');

    Route::get('admin/db', function () {
        abort_if(auth()->user()->email !== 'bswanson@outlook.com', 403);
        return view('pages/admin/db');
    })->name('admin.db');
});

require __DIR__.'/settings.php';
