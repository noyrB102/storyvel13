<?php

namespace App\Http\Controllers;

use App\Models\Story;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadController extends Controller
{
    public function downloadPdf(Story $story): Response
    {
        $isOwner = auth()->check() && $story->user_id === auth()->id();
        $isPublic = ! $story->is_private && $story->status === 'completed';
        $hasValidSignature = request()->hasValidSignature();
        abort_if(! $isOwner && ! $isPublic && ! $hasValidSignature, 403);

        $html = $this->buildHtml($story);

        // Use dompdf for PDF generation
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        $filename = $this->sanitizeFilename($story->title ?? 'story') . '.pdf';
        $content = $dompdf->output();
        $wantsInline = request()->boolean('inline');
        $disposition = ($isOwner && ! $wantsInline) ? 'attachment' : 'inline';

        return response()->make($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition . '; filename="' . $filename . '"',
            'Content-Length' => strlen($content),
            'Content-Encoding' => 'identity',
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }

    public function downloadWord(Story $story): Response
    {
        abort_if($story->user_id !== auth()->id(), 403);

        $phpWord = new PhpWord();
        $phpWord->setDefaultFontName('Arial');
        $phpWord->setDefaultFontSize(11);
        $section = $phpWord->addSection([
            'marginLeft'   => 1800, // 1.25in (1440 twips per inch)
            'marginRight'  => 864,  // 0.6in
            'marginTop'    => 864,  // 0.6in
            'marginBottom' => 576,  // 0.4in
        ]);

        // Define reusable styles
        $phpWord->addFontStyle('TitleFont', ['name' => 'Arial', 'bold' => true, 'size' => 22, 'color' => '111827']);
        $phpWord->addParagraphStyle('TitlePara', ['spaceAfter' => 120]);
        $phpWord->addFontStyle('AuthorFont', ['name' => 'Arial', 'italic' => true, 'size' => 11, 'color' => '6b7280']);
        $phpWord->addFontStyle('BodyFont', ['name' => 'Arial', 'size' => 11, 'color' => '1f2937']);
        $phpWord->addFontStyle('BodyBold', ['name' => 'Arial', 'size' => 11, 'bold' => true, 'color' => '1f2937']);
        $phpWord->addFontStyle('BodyItalic', ['name' => 'Arial', 'size' => 11, 'italic' => true, 'color' => '1f2937']);
        $phpWord->addFontStyle('CodeFont', ['name' => 'Courier New', 'size' => 10, 'color' => '1f2937']);

        // Add cover image first if exists (small, centered)
        if ($story->cover_image_path && Storage::disk('public')->exists($story->cover_image_path)) {
            $imagePath = Storage::disk('public')->path($story->cover_image_path);
            $section->addImage($imagePath, ['width' => 180, 'alignment' => 'center']);
            $section->addTextBreak(1);
        }

        // Add title
        $section->addText($story->title ?? 'Untitled Story', 'TitleFont', 'TitlePara');

        // Add author
        if ($story->author_name) {
            $section->addText($story->author_name, 'AuthorFont');
            $section->addTextBreak(1);
        }

        // Add content (strip Writing Coach section)
        $wordContent = $story->content ?? '';
        $wordContent = preg_split('/^#+\s*Writing Coach.*$/mi', $wordContent)[0];
        $wordContent = rtrim($wordContent);

        if ($wordContent) {
            // Convert markdown paragraphs to Word paragraphs
            $paragraphs = preg_split('/\n\s*\n/', $wordContent);
            $titleSkipped = false;
            foreach ($paragraphs as $paragraph) {
                $paragraph = trim($paragraph);
                if (empty($paragraph)) {
                    continue;
                }
                // Skip the first heading that matches the story title so the title appears once
                if (! $titleSkipped && $story->title && preg_match('/^#{1,6}\s+' . preg_quote($story->title, '/') . '\s*$/i', $paragraph)) {
                    $titleSkipped = true;
                    continue;
                }
                // Check if it's a heading
                if (preg_match('/^#{1,6}\s+(.+)$/', $paragraph, $matches)) {
                    $level = strlen($matches[0]) - strlen($matches[1]) - 1;
                    $section->addTitle($matches[1], min($level, 6));
                } else {
                    $textRun = $section->addTextRun(['name' => 'Arial', 'size' => 11, 'color' => '1f2937']);
                    // Split on inline markdown: **bold**, *italic*, `code`
                    $tokens = preg_split('/(\*\*.*?\*\*|\*.*?\*|__.*?__|_.*?_|`.*?`)/', $paragraph, -1, PREG_SPLIT_DELIM_CAPTURE);
                    foreach ($tokens as $token) {
                        if (preg_match('/^(\*\*|__)(.+)\1$/', $token, $m)) {
                            $textRun->addText($m[2], 'BodyBold');
                        } elseif (preg_match('/^(\*|_)(.+)\1$/', $token, $m)) {
                            $textRun->addText($m[2], 'BodyItalic');
                        } elseif (preg_match('/^`(.+)`$/', $token, $m)) {
                            $textRun->addText($m[1], 'CodeFont');
                        } elseif ($token !== '') {
                            $textRun->addText($token, 'BodyFont');
                        }
                    }
                }
                $section->addTextBreak();
            }
        }

        $filename = $this->sanitizeFilename($story->title ?? 'story') . '.docx';
        $tempPath = tempnam(sys_get_temp_dir(), 'story_') . '.docx';

        $objWriter = IOFactory::createWriter($phpWord, 'Word2007');
        $objWriter->save($tempPath);

        $content = file_get_contents($tempPath);
        unlink($tempPath);

        return response()->make($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => strlen($content),
            'Content-Encoding' => 'identity',
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }

    private function buildHtml(Story $story): string
    {
        $title = htmlspecialchars($story->title ?? 'Untitled Story');

        $authorHtml = $story->author_name
            ? '<div class="author">' . htmlspecialchars($story->author_name) . '</div>'
            : '';

        $coverImageHtml = '';
        if ($story->cover_image_path && Storage::disk('public')->exists($story->cover_image_path)) {
            $imageData      = base64_encode(Storage::disk('public')->get($story->cover_image_path));
            $mimeType       = Storage::disk('public')->mimeType($story->cover_image_path);
            $coverImageHtml = '<div class="cover-image"><img src="data:' . $mimeType . ';base64,' . $imageData . '"></div>';
        }

        $rawContent = $story->content ?? '';
        // Remove the first heading that duplicates the story title so the title appears once
        if ($story->title) {
            $rawContent = preg_replace('/^#+\s*' . preg_quote($story->title, '/') . '\s*(?:\n|$)/mi', '', $rawContent, 1);
        }
        $rawContent = preg_split('/^#+\s*Writing Coach.*$/mi', $rawContent)[0];
        $rawContent = rtrim($rawContent);

        $content = $rawContent
            ? (string) \Illuminate\Support\Str::markdown($rawContent)
            : '<p>No content available.</p>';

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{$title}</title>
    <style>
        @page { margin: 0.6in 0.6in 0.4in 1.25in; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11pt;
            line-height: 1.5;
            color: #1f2937;
            margin: 0;
            padding: 0;
            background: #fff;
        }
        .cover-image {
            text-align: center;
            margin-bottom: 12pt;
            border-radius: 1rem;
            overflow: hidden;
        }
        .cover-image img {
            max-height: 1.6in;
            width: auto;
            object-fit: contain;
            display: block;
            margin: 0 auto;
            border-radius: 1rem;
        }
        .header {
            padding: 0;
        }
        h1 {
            font-size: 22pt;
            font-weight: 700;
            margin: 0 0 6px 0;
            color: #111827;
            line-height: 1.2;
            letter-spacing: -0.5px;
        }
        .author {
            font-size: 11pt;
            color: #6b7280;
            font-style: normal;
            margin-bottom: 0.8em;
        }
        .divider {
            border: none;
            border-top: 1px solid #e5e7eb;
            margin: 18pt 0;
        }
        .content {
            padding: 0 0 20px 0;
            text-align: left;
        }
        .content p {
            margin-bottom: 0.8em;
            color: #1f2937;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11pt;
        }
        .content h1, .content h2, .content h3 {
            font-size: 12pt;
            font-weight: bold;
            margin: 1.2em 0 0.4em;
            color: #111827;
        }
        .content hr {
            border: none;
            border-top: 1px solid #d1d5db;
            margin: 1.5em 0;
        }
        .content em { font-style: italic; }
        .content strong { font-weight: bold; color: #111827; }
        .content blockquote {
            border-left: 4px solid #3b82f6;
            padding-left: 1em;
            color: #4b5563;
            font-style: italic;
            margin: 1.2em 0;
        }
    </style>
</head>
<body>
    {$coverImageHtml}
    <div class="header">
        <h1>{$title}</h1>
        {$authorHtml}
    </div>
    <hr class="divider">
    <div class="content">{$content}</div>
</body>
</html>
HTML;
    }

    private function sanitizeFilename(string $filename): string
    {
        // Remove special characters
        $filename = preg_replace('/[^a-zA-Z0-9\s-]/', '', $filename);
        // Replace spaces with hyphens
        $filename = preg_replace('/\s+/', '-', $filename);
        // Limit length
        return substr($filename, 0, 100);
    }
}
