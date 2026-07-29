@if ($includeImage && $coverImagePath)
    <p style="margin: 0 0 12pt 0;">
        <img src="{{ $message->embed(Storage::disk('public')->path($coverImagePath)) }}" alt="Cover" style="max-width: 300px; height: auto; display: block;">
    </p>
@endif
{!! $html !!}
