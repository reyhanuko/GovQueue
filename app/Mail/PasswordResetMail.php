<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * The one-time link a client follows to choose a new password.
 *
 * Queued, like the appointment mail: a slow or unreachable mail server must not
 * turn "I forgot my password" into an error page for someone standing at a
 * counter.
 *
 * The link is signed rather than random, so a token cannot be guessed from a
 * partially-known URL, and it is generated here rather than stored so there is no
 * reset token sitting in the database to leak.
 */
class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset your GovQueue password',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.password-reset',
            with: [
                'user' => $this->user,
                'url' => $this->resetUrl(),
                'minutes' => config('auth.passwords.users.expire'),
            ],
        );
    }

    /**
     * Signed, temporary URL for the reset form.
     */
    public function resetUrl(): string
    {
        return URL::temporarySignedRoute(
            'password.reset',
            now()->addMinutes((int) config('auth.passwords.users.expire')),
            [
                'token' => $this->token,
                'email' => $this->user->email,
            ],
        );
    }
}
