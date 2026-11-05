<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Webhooks\Events;

/**
 * The message bounced (event "bounced"); see $event->bounceCategory.
 */
final class MessageBounced extends HaskimailWebhookEvent {}
