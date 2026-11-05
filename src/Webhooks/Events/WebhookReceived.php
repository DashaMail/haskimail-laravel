<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Webhooks\Events;

/**
 * Dispatched for every verified webhook event, including types unknown to this package.
 */
final class WebhookReceived extends HaskimailWebhookEvent {}
