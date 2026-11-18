<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * E-mail da loja: um único Mailable que renderiza resources/views/emails/{template}.blade.php
 * dentro do layout da marca (emails/layout).
 */
class StoreMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $template,
        public string $subjectLine,
        public array $data = [],
        public ?string $unsubscribeUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        $reply = setting('mail.reply_to');

        return new Envelope(
            from: new Address((string) config('mail.from.address'), (string) config('mail.from.name')),
            replyTo: $reply ? [new Address($reply)] : [],
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            // cart_recovery_1/2/3 usam o mesmo modelo (o texto muda pelo passo)
            view: 'emails.'.preg_replace('/_\d+$/', '', $this->template),
            with: $this->data + ['subject' => $this->subjectLine, 'unsubscribeUrl' => $this->unsubscribeUrl],
        );
    }

    public function headers(): \Illuminate\Mail\Mailables\Headers
    {
        // Descadastro em 1 clique (Gmail/Yahoo exigem em e-mails de marketing)
        return new \Illuminate\Mail\Mailables\Headers(text: $this->unsubscribeUrl ? [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ] : []);
    }
}
