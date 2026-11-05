<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Tests\Fixtures;

use Haskimail\Laravel\Mail\InteractsWithHaskimail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class InvoicePaid extends Notification
{
    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = new class extends MailMessage
        {
            use InteractsWithHaskimail;
        };

        return $message->subject('Счёт оплачен')->line('Спасибо!')->haskimailStream('notifications')->haskimailTrackOpens();
    }
}
