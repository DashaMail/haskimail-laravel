<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Webhooks\Events;

/**
 * The recipient opened the message (event "opened"); fired for every open.
 */
final class MessageOpened extends HaskimailWebhookEvent {}
