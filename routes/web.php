<?php

use App\Http\Controllers\StoryNarrationController;
use App\Http\Controllers\VoiceTranscriptionController;
use App\Jobs\GenerateCoverImage;
use App\Models\Idea;
use App\Models\Story;
use App\Models\SiteSetting;
use App\Models\StoryOriginal;
use App\Models\StoryPreviousVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use App\Ai\Agents\StoryAgent;
use App\Ai\Agents\StoryEditAgent;
use App\Services\StoryCopier;
use App\Services\StoryImprover;

$snapshotPreviousVersion = static function (Story $story): void {
    $original = $story->original;

    StoryPreviousVersion::updateOrCreate(
        ['story_id' => $story->id],
        [
            'user_id' => $story->user_id,
            'title' => $story->title,
            'author_name' => $story->author_name,
            'genre' => $story->genre,
            'content' => $story->content,
            'is_private' => $story->is_private,
            'is_edited' => ! $original || $story->title !== $original->title || $story->content !== $original->content,
        ],
    );
};

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('books.index');
    }
    return redirect()->route('login');
})->name('home');

// Public story viewing (no auth required)
Route::get('stories/{story}', function (Story $story) {
    // Allow if story is public/completed, or if accessed via a valid signed URL
    $isPublic = ! $story->is_private && $story->status === 'completed';
    abort_if(! $isPublic && ! request()->hasValidSignature(), 404);
    return view('pages/books/show-public', compact('story'));
})->name('stories.public.show');

// Public PDF download for shared stories
Route::get('stories/{story}/download/pdf', [\App\Http\Controllers\DownloadController::class, 'downloadPdf'])
    ->name('stories.public.download.pdf');

Route::middleware(['auth', 'verified'])->group(function () use ($snapshotPreviousVersion) {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::view('create', 'pages/writer/create')->name('writer.create');
    Route::view('books', 'pages/books/index')->name('books.index');

    Route::get('books/recently-deleted', function () {
        $deletedStories = Story::onlyTrashed()
            ->where('user_id', auth()->id())
            ->latest('deleted_at')
            ->get();
        $originals = StoryOriginal::where('user_id', auth()->id())
            ->whereNull('story_id')
            ->latest()
            ->get();

        return view('pages/books/recently-deleted', compact('deletedStories', 'originals'));
    })->name('books.recently-deleted');

    Route::post('books/recently-deleted/{story}/restore', function ($story) {
        $story = Story::onlyTrashed()->findOrFail($story);
        abort_if($story->user_id !== auth()->id(), 403);

        $story->restore();

        return redirect()->route('books.show', $story)->with('success', 'Story restored.');
    })->name('books.recently-deleted.restore');

    Route::delete('books/recently-deleted/{story}', function ($story) {
        $story = Story::onlyTrashed()->findOrFail($story);
        abort_if($story->user_id !== auth()->id(), 403);

        DB::transaction(function () use ($story) {
            $story->original?->delete();
            $story->forceDelete();
        });

        return back()->with('success', 'Story permanently deleted.');
    })->name('books.recently-deleted.destroy');

    Route::post('books/recently-deleted/originals/{original}/restore', function (StoryOriginal $original) {
        abort_if($original->user_id !== auth()->id() || $original->story_id !== null, 403);

        $story = DB::transaction(function () use ($original) {
            $story = Story::create([
                'user_id' => $original->user_id,
                'title' => $original->title,
                'author_name' => auth()->user()->name,
                'prompt' => '',
                'content' => $original->content,
                'status' => 'completed',
                'is_private' => true,
                'format' => $original->format,
            ]);

            $original->update(['story_id' => $story->id]);

            return $story;
        });

        return redirect()->route('books.show', $story)->with('success', 'Story restored from its saved original.');
    })->name('books.recently-deleted.originals.restore');

    Route::delete('books/recently-deleted/originals/{original}', function (StoryOriginal $original) {
        abort_if($original->user_id !== auth()->id() || $original->story_id !== null, 403);

        $original->delete();

        return back()->with('success', 'Story permanently deleted.');
    })->name('books.recently-deleted.originals.destroy');

    Route::get('books/{story}', function (Story $story) {
        abort_if($story->user_id !== auth()->id(), 403);
        return view('pages/books/show', compact('story'));
    })->name('books.show');

    Route::get('books/{story}/copy', function (Story $story, Request $request) {
        abort_if($story->user_id !== auth()->id() && !auth()->user()?->isAdmin(), 403);

        $includeImage = (bool) $request->query('include_image', false);
        $copier = app(StoryCopier::class);

        return response()->json([
            'html' => $copier->html($story, $includeImage, $request->getSchemeAndHttpHost()),
            'text' => $copier->text($story),
        ]);
    })->name('books.copy');

    Route::get('books/{story}/cover-thumb', function (Story $story) {
        abort_if($story->user_id !== auth()->id() && !auth()->user()?->isAdmin(), 403);

        if (! $story->cover_image_path || ! Storage::disk('public')->exists($story->cover_image_path)) {
            abort(404);
        }

        $contents = Storage::disk('public')->get($story->cover_image_path);
        $imagick = new \Imagick();
        $imagick->readImageBlob($contents);
        $imagick->setImageFormat('png');
        $imagick->thumbnailImage(400, 400, true);
        $png = $imagick->getImageBlob();
        $imagick->clear();

        return response($png, 200, ['Content-Type' => 'image/png']);
    })->name('books.cover.thumb');

    Route::get('books/{story}/edit', function (Story $story) {
        abort_if($story->user_id !== auth()->id(), 403);
        abort_if($story->email_sent_at !== null, 403, 'This story is locked because it has been emailed for printing.');
        abort_if($story->isInDraftBook(), 403, 'This story is locked because it is in My Next Book.');
        return view('pages/books/edit', compact('story'));
    })->name('books.edit');

    Route::get('books/{story}/narration', StoryNarrationController::class)
        ->middleware('throttle:10,1')
        ->name('books.narration');

    Route::post('voice/transcribe', VoiceTranscriptionController::class)
        ->middleware('throttle:20,1')
        ->name('voice.transcribe');

    Route::put('books/{story}', function (Story $story, Request $request) use ($snapshotPreviousVersion) {
        abort_if($story->user_id !== auth()->id(), 403);
        abort_if($story->email_sent_at !== null, 403, 'This story is locked because it has been emailed for printing.');
        abort_if($story->isInDraftBook(), 403, 'This story is locked because it is in My Next Book.');
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

        $trackableFields = ['title', 'author_name', 'genre', 'content', 'is_private'];
        $hasTrackableChange = collect($trackableFields)->contains(
            fn (string $field) => array_key_exists($field, $data) && $data[$field] != $story->getAttribute($field),
        );

        if ($hasTrackableChange) {
            $snapshotPreviousVersion($story);
        }

        $story->update($data);
        return redirect()->route('books.show', $story)->with('success', 'Story saved.');
    })->name('books.update');

    Route::delete('books/{story}', function (Story $story) {
        abort_if($story->user_id !== auth()->id(), 403);
        abort_if($story->email_sent_at !== null, 403, 'This story is locked because it has been emailed for printing.');
        $story->books()->detach();
        $story->delete();

        return redirect()->route('books.index')->with('success', 'Story moved to Recently Deleted.');
    })->name('books.destroy');

    Route::post('books/{story}/ai-edit', function (Story $story, Request $request) use ($snapshotPreviousVersion) {
        abort_if($story->user_id !== auth()->id(), 403);
        abort_if($story->email_sent_at !== null, 403, 'This story is locked because it has been emailed for printing.');
        $request->validate([
            'type'          => 'required|in:fix,add_remove,expand',
            'instruction'   => 'required|string|max:1000',
            'fit_one_page'  => 'nullable|boolean',
        ]);

        $type        = $request->input('type');
        $instruction = $request->input('instruction');
        $content     = $story->content ?? '';
        $fitOnePage  = $request->boolean('fit_one_page');

        $lengthRule = $fitOnePage
            ? "The user has chosen 'Fit 1 page'. Keep the finished story to approximately 300–750 words so it fits on a single printed page. Tighten elsewhere as needed and never let it grow beyond one printed page. "
            : "Keep the finished story to approximately 300–750 words so it fits on a single printed page. Tighten elsewhere as needed and never let it grow beyond one printed page. ";

        $separator = '|||SUMMARY|||';

        $summaryInstruction = "After the story, on a new line write exactly: {$separator} followed by one or two plain, friendly sentences (no jargon) describing what you changed and why it improves the story. Example: \"{$separator} I softened the formal language so it sounds more like you're telling this to a friend.\"";

        $prompts = [
            'fix' => "You are an editor. The user wants to fix something in their story. Apply ONLY this change and return the COMPLETE revised story. {$lengthRule}\n\n{$summaryInstruction}\n\nChange requested: {$instruction}\n\nStory to edit:\n\n{$content}",
            'add_remove' => "You are an editor. The user wants to add or remove something in their story. Apply ONLY this change and return the COMPLETE revised story. {$lengthRule}\n\n{$summaryInstruction}\n\nChange requested: {$instruction}\n\nStory to edit:\n\n{$content}",
            'expand' => "You are a creative writing assistant. The user wants to enrich or enhance their story. Apply ONLY this change and return the COMPLETE revised story. Enhance the writing (stronger detail, imagery, or flow) while keeping the finished story to approximately 300–750 words so it still fits on a single printed page — tighten elsewhere as needed.\n\n{$summaryInstruction}\n\nEnhancement requested: {$instruction}\n\nStory to enhance:\n\n{$content}",
        ];

        try {
            $response = (new StoryEditAgent())->prompt($prompts[$type]);
        } catch (ProviderOverloadedException) {
            return response()->json(['error' => 'The writing helper is busy right now. Please try again in a minute.'], 503);
        }

        $raw = $response->text;

        if (str_contains($raw, $separator)) {
            [$newContent, $changeSummary] = explode($separator, $raw, 2);
            $newContent    = trim($newContent);
            $changeSummary = trim($changeSummary);
        } else {
            $newContent    = trim($raw);
            $changeSummary = '';
        }

        if ($newContent !== $story->content) {
            $snapshotPreviousVersion($story);
            $story->update(['content' => $newContent]);
        }

        return response()->json([
            'content' => $newContent,
            'summary' => $changeSummary,
            'hasUndoLastEdit' => StoryPreviousVersion::where('story_id', $story->id)->value('is_edited') ?? false,
        ]);
    })->name('books.ai-edit');

    Route::post('books/{story}/ai-review', function (Story $story) {
        abort_if($story->user_id !== auth()->id(), 403);
        abort_if($story->email_sent_at !== null, 403, 'This story is locked because it has been emailed for printing.');

        $content = $story->content ?? '';
        if (! $content) {
            return response()->json(['error' => 'No story content to review.'], 422);
        }

        try {
            $data = app(StoryImprover::class)->review($content);
        } catch (ProviderOverloadedException) {
            return response()->json(['error' => 'The writing helper is busy right now. Please try again in a minute.'], 503);
        }

        if (! $data || ! isset($data['voice'])) {
            return response()->json(['error' => 'Could not parse AI response. Please try again.'], 500);
        }

        return response()->json($data);
    })->name('books.ai-review');

    Route::post('books/{story}/ai-reimprove', function (Story $story, Request $request) use ($snapshotPreviousVersion) {
        abort_if($story->user_id !== auth()->id(), 403);
        abort_if($story->email_sent_at !== null, 403, 'This story is locked because it has been emailed for printing.');

        $content = $story->content ?? '';
        if (! $content) {
            return response()->json(['error' => 'No story content to improve.'], 422);
        }

        $extraContext = $request->input('extra_context');

        try {
            $newContent = (new StoryImprover())->improve($content, $extraContext !== null ? trim($extraContext) : null);
        } catch (ProviderOverloadedException) {
            return response()->json(['error' => 'The writing helper is busy right now. Please try again in a minute.'], 503);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Something went wrong — please try again.'], 500);
        }

        if ($newContent !== $content) {
            $snapshotPreviousVersion($story);
            $story->update(['content' => $newContent]);
        }

        return response()->json([
            'content' => $newContent,
            'summary' => 'The AI re-reviewed your story and made it stronger across voice, detail, ending, and length.',
            'hasUndoLastEdit' => StoryPreviousVersion::where('story_id', $story->id)->value('is_edited') ?? false,
        ]);
    })->name('books.ai-reimprove');

    Route::post('books/{story}/undo-last-edit', function (Story $story) use ($snapshotPreviousVersion) {
        abort_if($story->user_id !== auth()->id(), 403);
        abort_if($story->email_sent_at !== null, 403, 'This story is locked because it has been emailed for printing.');
        $previous = $story->previousVersion;
        abort_if(! $previous || ! $previous->is_edited, 404);

        DB::transaction(function () use ($story, $previous, $snapshotPreviousVersion) {
            $snapshotPreviousVersion($story);
            $story->update([
                'title' => $previous->title,
                'author_name' => $previous->author_name,
                'genre' => $previous->genre,
                'content' => $previous->content,
                'is_private' => $previous->is_private,
            ]);
        });

        return back()->with('success', 'Your last edit has been undone.');
    })->name('books.undo-last-edit');

    Route::post('books/{story}/restore-original', function (Story $story) use ($snapshotPreviousVersion) {
        abort_if($story->user_id !== auth()->id(), 403);
        abort_if($story->email_sent_at !== null, 403, 'This story is locked because it has been emailed for printing.');
        $original = $story->original;
        abort_if(! $original, 404);

        if ($story->content !== $original->content || $story->title !== $original->title) {
            $snapshotPreviousVersion($story);
            $story->update(['content' => $original->content, 'title' => $original->title]);
        }

        return back()->with('success', 'Your original story has been restored.');
    })->name('books.restore-original');

    Route::post('books/{story}/restore', function (Story $story, Request $request) use ($snapshotPreviousVersion) {
        abort_if($story->user_id !== auth()->id(), 403);
        $request->validate(['content' => 'required|string']);
        $content = $request->input('content');

        if ($content !== $story->content) {
            $snapshotPreviousVersion($story);
            $story->update(['content' => $content]);
        }

        return response()->json(['ok' => true]);
    })->name('books.restore');

    Route::post('books/{story}/regenerate-cover', function (Story $story) {
        abort_if($story->user_id !== auth()->id(), 403);
        GenerateCoverImage::dispatch($story);
        if (request()->expectsJson() || request()->ajax()) {
            return response()->json(['ok' => true]);
        }
        return back()->with('success', 'Cover regeneration started.');
    })->name('books.regenerate-cover');

    Route::get('books/{story}/cover-status', function (Story $story) {
        abort_if($story->user_id !== auth()->id(), 403);
        if (! $story->cover_image_path) {
            return response()->json(['url' => null, 'version' => null]);
        }
        $story->refresh();
        return response()->json([
            'url' => Storage::disk('public')->url($story->cover_image_path),
            'version' => $story->updated_at->timestamp,
        ]);
    })->name('books.cover-status');

    // Download routes
    Route::get('books/{story}/download/pdf', [\App\Http\Controllers\DownloadController::class, 'downloadPdf'])
        ->name('books.download.pdf');

    Route::get('books/{story}/download/word', [\App\Http\Controllers\DownloadController::class, 'downloadWord'])
        ->name('books.download.word');

    Route::view('templates', 'pages/templates/index')->name('templates.index');

    Route::get('ideas', function () {
        return view('pages/ideas/index');
    })->name('ideas.index');

    Route::get('admin/stories', function (Request $request) {
        abort_unless(auth()->user()->isAdmin(), 403);

        $search = trim((string) $request->query('search'));
        $stories = Story::with('user')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('author_name', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($query) use ($search) {
                            $query->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->latest()
            ->paginate(24)
            ->withQueryString();

        return view('pages/admin/stories/index', compact('stories', 'search'));
    })->name('admin.stories.index');

    Route::get('admin/stories/{story}', function (Story $story) {
        abort_unless(auth()->user()->isAdmin(), 403);

        return view('pages/admin/stories/show', ['story' => $story->load('user')]);
    })->name('admin.stories.show');

    Route::post('admin/stories/{story}/unlock', function (Story $story) {
        abort_unless(auth()->user()->isAdmin(), 403);

        $story->email_sent_at = null;
        $story->save();

        return back()->with('success', 'Story unlocked.');
    })->name('admin.stories.unlock');

    Route::post('admin/stories/{story}/lock', function (Story $story) {
        abort_unless(auth()->user()->isAdmin(), 403);

        $story->email_sent_at = now();
        $story->save();

        return back()->with('success', 'Story locked.');
    })->name('admin.stories.lock');

    Route::get('admin/db', function () {
        abort_unless(auth()->user()->isAdmin(), 403);
        return view('pages/admin/db');
    })->name('admin.db');

    Route::get('admin/settings', function () {
        abort_unless(auth()->user()->isAdmin(), 403);

        return view('pages/admin/settings', [
            'bookTargetCount' => (int) SiteSetting::get('book_target_count', 8),
        ]);
    })->name('admin.settings');

    Route::post('admin/settings', function (Request $request) {
        abort_unless(auth()->user()->isAdmin(), 403);

        $validated = $request->validate([
            'book_target_count' => 'required|integer|min:1|max:30',
        ]);

        SiteSetting::set('book_target_count', $validated['book_target_count']);

        return back()->with('success', 'Book target count saved.');
    })->name('admin.settings.update');
});

require __DIR__.'/settings.php';
