<?php

namespace App\Services;

use App\Models\Story;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class StoryCopier
{
    public function html(Story $story, bool $includeImage = false, ?string $baseUrl = null): string
    {
        $title  = trim($story->title ?? 'Untitled Story');
        $author = trim($story->author_name ?? optional($story->user)->name ?? '');

        $raw = $story->content ?? '';
        $raw = preg_split('/^#+\s*Writing Coach.*$/mi', $raw)[0];
        if ($title !== '') {
            $raw = preg_replace('/^#+\s*' . preg_quote($title, '/') . '\s*(?:\n|$)/mi', '', $raw, 1);
        }
        $bodyHtml = (string) Str::markdown(trim($raw));
        // Decode quote entities so pasted text uses real quotes/apostrophes while keeping the paragraph markup.
        $bodyHtml = str_replace(['&quot;', '&#39;', '&#039;', '&apos;'], ['"', "'", "'", "'"], $bodyHtml);

        $style = 'font-family: Arial, Helvetica, sans-serif; font-size: 12pt; line-height: 1.5; color: #000;';

        $html = '<div style="' . $style . '">';

        if ($includeImage && $story->cover_image_path && Storage::disk('public')->exists($story->cover_image_path)) {
            $path = Storage::disk('public')->url($story->cover_image_path);
            $src = $baseUrl !== null ? rtrim($baseUrl, '/') . $path : URL::to($path);
            $html .= '<p style="' . $style . ' margin: 0 0 12pt 0;"><img src="' . $src . '" alt="Cover" style="max-width: 300px; height: auto; display: block;"></p>';
        }

        $html .= '<p style="' . $style . ' font-weight: bold; font-size: 14pt; margin: 0 0 4pt 0;">' . htmlspecialchars($title, ENT_NOQUOTES, 'UTF-8') . '</p>';
        if ($author !== '') {
            $html .= '<p style="' . $style . ' color: #444; margin: 0 0 12pt 0;">by ' . htmlspecialchars($author, ENT_NOQUOTES, 'UTF-8') . '</p>';
        }
        $html .= '<div style="' . $style . '">' . $bodyHtml . '</div>';
        $html .= '</div>';

        return $html;
    }

    public function text(Story $story): string
    {
        $title  = trim($story->title ?? 'Untitled Story');
        $author = trim($story->author_name ?? optional($story->user)->name ?? '');

        $raw = $story->content ?? '';
        $raw = preg_split('/^#+\s*Writing Coach.*$/mi', $raw)[0];
        if ($title !== '') {
            $raw = preg_replace('/^#+\s*' . preg_quote($title, '/') . '\s*(?:\n|$)/mi', '', $raw, 1);
        }
        $bodyHtml = (string) Str::markdown(trim($raw));
        // Paragraph breaks become double newlines in the plain-text fallback.
        $bodyHtml = preg_replace('/<\/p>\s*(?=<p|$)/i', "</p>\n\n", $bodyHtml);
        $body = trim(strip_tags($bodyHtml));
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $body = preg_replace('/\n{3,}/', "\n\n", $body);

        $text = $title . "\n";
        if ($author !== '') {
            $text .= 'by ' . $author . "\n";
        }
        $text .= "\n" . $body;

        return $text;
    }
}
