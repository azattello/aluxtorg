<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OrderNotificationMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $orderNumber,
        public readonly string $subjectLine,
        public readonly string $bodyText,
        public readonly ?string $statusLabel,
        public readonly ?string $unsubscribeUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order-notification',
            with: [
                'orderNumber' => $this->orderNumber,
                'bodyText' => $this->bodyText,
                'statusLabel' => $this->statusLabel,
                'unsubscribeUrl' => $this->unsubscribeUrl,
            ],
        );
    }
}
