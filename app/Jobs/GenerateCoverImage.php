<?php

namespace App\Jobs;

use App\Models\Story;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Ai\Image;

class GenerateCoverImage implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function __construct(public Story $story) {}

    public function handle(): void
    {
        $title = $this->story->title ?? 'a personal memory';
        $authorName = $this->story->author_name ?? $this->story->user?->name ?? '';

        // Use the generated story content for context, falling back to the user's prompt.
        $source = trim($this->story->content ?: $this->story->prompt ?: '');
        $summary = $source !== '' ? substr(str_replace(["\n", "\r"], ' ', $source), 0, 1000) : '';

        // Extract gender hints from pronouns in the story text
        $genderHint = '';
        if ($source !== '') {
            $lower = strtolower($source);
            $heCount = preg_match_all('/\b(he|him|his|himself)\b/', $lower, $m);
            $sheCount = preg_match_all('/\b(she|her|hers|herself)\b/', $lower, $m2);
            if ($heCount > $sheCount * 2) {
                $genderHint = 'IMPORTANT: The main character/narrator is male. Any people shown must be male unless the story explicitly mentions women. ';
            } elseif ($sheCount > $heCount * 2) {
                $genderHint = 'IMPORTANT: The main character/narrator is female. Any people shown must be female unless the story explicitly mentions men. ';
            } elseif ($heCount > $sheCount) {
                $genderHint = 'IMPORTANT: The story is primarily about a male narrator. Show male figures prominently. ';
            } elseif ($sheCount > $heCount) {
                $genderHint = 'IMPORTANT: The story is primarily about a female narrator. Show female figures prominently. ';
            }

        }

        // Use author name as gender signal (always applies, strengthens or provides baseline)
        if ($authorName !== '') {
            $maleNames = ['james','john','robert','michael','william','david','richard','joseph','thomas','charles','christopher','daniel','matthew','anthony','mark','donald','steven','paul','andrew','joshua','kenneth','kevin','brian','george','timothy','ronald','edward','jason','jeffrey','ryan','jacob','gary','nicholas','eric','jonathan','stephen','larry','justin','scott','brandon','benjamin','samuel','raymond','gregory','frank','alexander','patrick','jack','dennis','jerry','tyler','aaron','henry','douglas','peter','adam','nathan','zachary','walter','kyle','harold','carl','arthur','gerald','roger','keith','lawrence','albert','terry','joe','sean','austin','bruce','ralph','roy','eugene','randy','wayne','philip','harry','vincent','bobby','dylan','johnny','billy','howard','alan','russell','ethan','bryon','bryan'];
            $femaleNames = ['mary','patricia','jennifer','linda','barbara','elizabeth','susan','jessica','sarah','karen','lisa','nancy','betty','margaret','sandra','ashley','dorothy','kimberly','emily','donna','michelle','carol','amanda','melissa','deborah','stephanie','rebecca','sharon','laura','cynthia','kathleen','amy','angela','shirley','anna','brenda','pamela','emma','nicole','helen','samantha','katherine','christine','debra','rachel','carolyn','janet','catherine','maria','heather','diane','ruth','julie','olivia','joyce','virginia','victoria','kelly','lauren','christina','joan','evelyn','judith','megan','andrea','cheryl','hannah','jacqueline','martha','gloria','teresa','ann','sara','madison','frances','kathryn','janice','jean','abigail','alice','judy','sophia','grace','denise','amber','doris','marilyn','danielle','beverly','isabella','theresa','diana','natalie','brittany','charlotte','marie','kayla','alexis','lori'];
            $first = strtolower(explode(' ', trim($authorName))[0]);
            if (in_array($first, $maleNames)) {
                $genderHint = 'IMPORTANT: The author/narrator is male (named ' . $authorName . '). The main person in the image must be male. Do NOT show women as the main figure. ';
            } elseif (in_array($first, $femaleNames)) {
                $genderHint = 'IMPORTANT: The author/narrator is female (named ' . $authorName . '). The main person in the image must be female. Do NOT show men as the main figure. ';
            }
        }

        $prompt = "A warm, light, photorealistic documentary-style photograph for a true personal memoir. "
            . "Soft natural daylight, gentle nostalgic mood, real-world setting, no text, no fantasy, no dark dramatic lighting, no book cover typography. "
            . "Evoke the memory: '{$title}'. ";

        if ($authorName !== '') {
            $prompt .= "Written by {$authorName}. ";
        }

        if ($genderHint !== '') {
            $prompt .= $genderHint;
        }

        if ($summary !== '') {
            $prompt .= "Story context: {$summary}";
        }

        $image = Image::of($prompt)
            ->landscape()
            ->quality('high')
            ->generate();

        $path = $image->storePubliclyAs(
            'covers/' . $this->story->id . '.png',
            disk: 'public'
        );

        $this->story->update([
            'cover_image_path' => $path,
            'updated_at' => now(),
        ]);
    }
}
