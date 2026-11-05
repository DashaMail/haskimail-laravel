<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Enums;

/**
 * Values of the "event" field of Haskimail event webhooks.
 */
enum WebhookEventType: string
{
    case Delivered = 'delivered';
    case Bounced = 'bounced';
    case Complained = 'complained';
    case Opened = 'opened';
    case Clicked = 'clicked';
    case SubscriptionChange = 'subscriptionchange';

    /**
     * Case-insensitive lookup: the spam complaint webhook is documented as "COMPLAINED".
     */
    public static function fromPayload(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom(strtolower(trim($value))) : null;
    }
}
