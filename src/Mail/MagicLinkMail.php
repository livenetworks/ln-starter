<?php

namespace LiveNetworks\LnStarter\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use LiveNetworks\LnStarter\Models\MagicLinkToken;

class MagicLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Authenticatable $user,
        public MagicLinkToken $token,
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
                'token'     => $this->token,
                'link'      => route('auth.magic.show', ['token' => $this->token->token]),
                'expiresIn' => $this->token->expires_at->diffInMinutes(now()),
            ],
        );
    }
}
