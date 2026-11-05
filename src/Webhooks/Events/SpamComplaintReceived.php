<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Webhooks\Events;

/**
 * The recipient marked the message as spam (event "complained").
 */
final class SpamComplaintReceived extends HaskimailWebhookEvent {}
