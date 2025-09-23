<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class MagicLinkMail extends \Illuminate\Mail\Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $email,
        public string $purpose,
        public string $url
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Magic Link',
            from: new Address(config('mail.from.address'), config('mail.from.name')),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.magic_link',
            with: [
                'email'   => $this->email,
                'purpose' => $this->purpose,
                'url'     => $this->url,
            ],
        );
    }
}
