<?php

namespace App\Mail;

use App\Models\Story;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class SendStory extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Story $story,
        public string $htmlContent,
        public string $textContent,
        public bool $includeImage = false,
    ) {}

    public function build()
    {
        $email = $this
            ->from(config('mail.from.address', 'bswanson@outlook.com'), config('mail.from.name', 'Bryon Swanson'))
            ->subject($this->story->title ?? 'Story')
            ->view('emails.story', [
                'html' => $this->htmlContent,
                'includeImage' => $this->includeImage,
                'coverImagePath' => $this->story->cover_image_path,
            ])
            ->text('emails.story-plain', ['text' => $this->textContent]);

        return $email;
    }
}
