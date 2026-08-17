<?php

namespace LiveNetworks\LnStarter\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use LiveNetworks\LnStarter\Models\MagicLoginAttempt;

class MagicLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Authenticatable $user,
        public MagicLoginAttempt $attempt,
        private readonly string $linkToken,
        public readonly string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __(config('ln-starter.auth.mail_subject', 'Magic Link Login')),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'ln-starter::emails.magic-link',
            with: [
                'user'      => $this->user,
                'attempt'   => $this->attempt,
                'code'      => $this->code,
                'link'      => route('auth.magic.link.open', ['token' => $this->linkToken]),
                'expiresIn' => max(1, (int) now()->diffInMinutes($this->attempt->expires_at, false)),
            ],
        );
    }
}
