<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Webhooks\Events;

/**
 * The recipient's mail server accepted the message (event "delivered").
 */
final class MessageDelivered extends HaskimailWebhookEvent {}
