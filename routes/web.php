<?php

use App\Jobs\GenerateCoverImage;
use App\Models\Idea;
use App\Models\Story;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use App\Ai\Agents\StoryAgent;

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

        // Handle custom cover image upload (raw file)
        if ($request->hasFile('cover_image')) {
            if ($story->cover_image_path && !str_starts_with($story->cover_image_path, 'ai-covers/')) {
                Storage::disk('public')->delete($story->cover_image_path);
            }
            $path = $request->file('cover_image')->store('covers/' . $story->id, 'public');
            $data['cover_image_path'] = $path;
        } elseif ($request->filled('cover_image_b64')) {
            // Client-side compressed image sent as base64
            $b64 = $request->input('cover_image_b64');
            if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $b64, $matches)) {
                $imageData = base64_decode($matches[2]);
                $ext       = in_array($matches[1], ['jpeg', 'jpg', 'png', 'webp']) ? $matches[1] : 'jpeg';
                $filename  = 'covers/' . $story->id . '/' . uniqid() . '.' . $ext;
                if ($story->cover_image_path && !str_starts_with($story->cover_image_path, 'ai-covers/')) {
                    Storage::disk('public')->delete($story->cover_image_path);
                }
                Storage::disk('public')->put($filename, $imageData);
                $data['cover_image_path'] = $filename;
            }
        }

        $story->update($data);
        return redirect()->route('books.show', $story)->with('success', 'Story saved.');
    })->name('books.update');

    Route::delete('books/{story}', function (Story $story) {
        abort_if($story->user_id !== auth()->id(), 403);
        $story->delete();
        return redirect()->route('books.index');
    })->name('books.destroy');

    Route::post('books/{story}/ai-edit', function (Story $story, Request $request) {
        abort_if($story->user_id !== auth()->id(), 403);
        $request->validate([
            'type'        => 'required|in:fix,add_remove,expand',
            'instruction' => 'required|string|max:1000',
        ]);

        $type        = $request->input('type');
        $instruction = $request->input('instruction');
        $content     = $story->content ?? '';

        $prompts = [
            'fix' => "You are an editor. The user wants to fix something in their story. Apply ONLY this change and return the COMPLETE revised story with no extra commentary, explanation, or markdown code fences — just the story text.\n\nChange requested: {$instruction}\n\nStory to edit:\n\n{$content}",
            'add_remove' => "You are an editor. The user wants to add or remove something in their story. Apply ONLY this change and return the COMPLETE revised story with no extra commentary, explanation, or markdown code fences — just the story text.\n\nChange requested: {$instruction}\n\nStory to edit:\n\n{$content}",
            'expand' => "You are a creative writing assistant. The user wants to expand or enhance their story. Apply ONLY this change and return the COMPLETE revised story with no extra commentary, explanation, or markdown code fences — just the story text.\n\nExpansion requested: {$instruction}\n\nStory to expand:\n\n{$content}",
        ];

        $response   = (new StoryAgent($story))->prompt($prompts[$type]);
        $newContent = trim($response->text);

        $story->update(['content' => $newContent]);

        return response()->json(['content' => $newContent]);
    })->name('books.ai-edit');

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
