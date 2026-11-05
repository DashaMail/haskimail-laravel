<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Webhooks\Events;

use Haskimail\Laravel\Webhooks\WebhookEvent;

/**
 * Base class of every Laravel event dispatched for a Haskimail webhook.
 */
abstract class HaskimailWebhookEvent
{
    final public function __construct(public readonly WebhookEvent $event) {}
}
