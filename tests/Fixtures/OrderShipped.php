<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Tests\Fixtures;

use Haskimail\Laravel\Mail\InteractsWithHaskimail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

final class OrderShipped extends Mailable implements ShouldQueue
{
    use InteractsWithHaskimail;
    use Queueable;
    use SerializesModels;

    public function __construct(public string $order = '1042') {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Заказ №{$this->order} отправлен",
            replyTo: ['support@example.com'],
            tags: ['orders'],
            metadata: ['order_id' => $this->order],
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: ['X-Order-Id' => $this->order]);
    }

    public function content(): Content
    {
        return new Content(htmlString: "<p>Заказ {$this->order}</p>");
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [Attachment::fromData(fn (): string => 'PDF', 'invoice.pdf')->withMime('application/pdf')];
    }
}
