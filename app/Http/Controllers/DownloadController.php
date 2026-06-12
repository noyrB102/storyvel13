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
        abort_if($story->user_id !== auth()->id(), 403);

        $html = $this->buildHtml($story);

        // Use dompdf for PDF generation
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        $filename = $this->sanitizeFilename($story->title ?? 'story') . '.pdf';
        $content = $dompdf->output();

        return response()->make($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => strlen($content),
            'Content-Encoding' => 'identity',
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }

    public function downloadWord(Story $story): Response
    {
        abort_if($story->user_id !== auth()->id(), 403);

        $phpWord = new PhpWord();
        $section = $phpWord->addSection();

        // Add title
        $section->addTitle($story->title ?? 'Untitled Story', 1);

        // Add author
        if ($story->author_name) {
            $section->addText($story->author_name, ['italic' => true, 'size' => 12]);
            $section->addTextBreak();
        }

        // Add genre and date
        $meta = [];
        if ($story->genre) {
            $meta[] = ucfirst($story->genre);
        }
        $meta[] = $story->created_at->format('F j, Y');
        $section->addText(implode(' | ', $meta), ['size' => 10, 'color' => '666666']);
        $section->addTextBreak();

        // Add prompt tagline
        if ($story->prompt) {
            $section->addText(
                \Illuminate\Support\Str::limit($story->prompt, 320),
                ['italic' => true, 'size' => 10, 'color' => '6b7280']
            );
        }
        $section->addTextBreak(2);

        // Add cover image if exists
        if ($story->cover_image_path && Storage::exists($story->cover_image_path)) {
            $imagePath = Storage::path($story->cover_image_path);
            $section->addImage($imagePath, ['width' => 450, 'alignment' => 'center']);
            $section->addTextBreak(2);
        }

        // Add content
        if ($story->content) {
            // Convert markdown paragraphs to Word paragraphs
            $paragraphs = preg_split('/\n\s*\n/', $story->content);
            foreach ($paragraphs as $paragraph) {
                $paragraph = trim($paragraph);
                if (empty($paragraph)) {
                    continue;
                }
                // Check if it's a heading
                if (preg_match('/^#{1,6}\s+(.+)$/', $paragraph, $matches)) {
                    $level = strlen($matches[0]) - strlen($matches[1]) - 1;
                    $section->addTitle($matches[1], min($level, 6));
                } else {
                    $textRun = $section->addTextRun(['size' => 12, 'name' => 'Georgia']);
                    // Split on inline markdown: **bold**, *italic*, `code`
                    $tokens = preg_split('/(\*\*.*?\*\*|\*.*?\*|__.*?__|_.*?_|`.*?`)/', $paragraph, -1, PREG_SPLIT_DELIM_CAPTURE);
                    foreach ($tokens as $token) {
                        if (preg_match('/^(\*\*|__)(.+)\1$/', $token, $m)) {
                            $textRun->addText($m[2], ['size' => 12, 'name' => 'Georgia', 'bold' => true]);
                        } elseif (preg_match('/^(\*|_)(.+)\1$/', $token, $m)) {
                            $textRun->addText($m[2], ['size' => 12, 'name' => 'Georgia', 'italic' => true]);
                        } elseif (preg_match('/^`(.+)`$/', $token, $m)) {
                            $textRun->addText($m[1], ['size' => 11, 'name' => 'Courier New']);
                        } elseif ($token !== '') {
                            $textRun->addText($token, ['size' => 12, 'name' => 'Georgia']);
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
        $date  = $story->created_at->format('M j, Y');

        $authorHtml = $story->author_name
            ? '<div class="author">' . htmlspecialchars($story->author_name) . '</div>'
            : '';

        $genreHtml = $story->genre
            ? '<span class="genre-pill">' . htmlspecialchars(ucfirst($story->genre)) . '</span>'
            : '';
        $metaHtml = '<div class="meta-row">' . $genreHtml . '<span class="meta-date">' . $date . '</span></div>';

        $promptHtml = $story->prompt
            ? '<p class="prompt">' . htmlspecialchars(\Illuminate\Support\Str::limit($story->prompt, 320)) . '</p>'
            : '';

        $coverImageHtml = '';
        if ($story->cover_image_path && Storage::exists($story->cover_image_path)) {
            $imageData      = base64_encode(Storage::get($story->cover_image_path));
            $mimeType       = Storage::mimeType($story->cover_image_path);
            $coverImageHtml = '<div class="cover-image"><img src="data:' . $mimeType . ';base64,' . $imageData . '"></div>';
        }

        $content = $story->content
            ? (string) \Illuminate\Support\Str::markdown($story->content)
            : '<p>No content available.</p>';

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{$title}</title>
    <style>
        @page { margin: 0; }
        body {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 13pt;
            line-height: 2;
            color: #1f2937;
            margin: 0;
            padding: 0;
            background: #fff;
        }
        .cover-image img {
            width: 100%;
            height: 260px;
            object-fit: cover;
            display: block;
        }
        .header {
            padding: 40px 56px 0 56px;
        }
        .meta-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        .genre-pill {
            background: #eff6ff;
            color: #2563eb;
            font-size: 8.5pt;
            font-family: Arial, sans-serif;
            font-weight: bold;
            padding: 2px 10px;
            border-radius: 20px;
        }
        .meta-date {
            font-size: 9pt;
            color: #9ca3af;
            font-family: Arial, sans-serif;
        }
        h1 {
            font-size: 28pt;
            font-weight: 900;
            margin: 0 0 8px 0;
            color: #111827;
            line-height: 1.2;
            letter-spacing: -0.5px;
        }
        .author {
            font-size: 11pt;
            color: #6b7280;
            font-style: normal;
            margin-bottom: 8px;
        }
        .accent-bar {
            width: 56px;
            height: 4px;
            background: linear-gradient(to right, #3b82f6, #93c5fd);
            border-radius: 2px;
            margin: 10px 0 14px 0;
        }
        .prompt {
            font-size: 10pt;
            font-style: italic;
            color: #6b7280;
            margin: 0 0 0 0;
            line-height: 1.6;
        }
        .divider {
            border: none;
            border-top: 1px solid #e5e7eb;
            margin: 20px 48px;
        }
        .content {
            padding: 0 56px 56px 56px;
            text-align: left;
        }
        .content p {
            margin-bottom: 1.6em;
            color: #1f2937;
        }
        .content h1, .content h2, .content h3 {
            font-size: 14pt;
            font-weight: bold;
            margin: 1.8em 0 0.5em;
            color: #111827;
        }
        .content hr {
            border: none;
            border-top: 1px solid #d1d5db;
            margin: 2em 0;
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
        {$metaHtml}
        <h1>{$title}</h1>
        {$authorHtml}
        <div class="accent-bar"></div>
        {$promptHtml}
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
