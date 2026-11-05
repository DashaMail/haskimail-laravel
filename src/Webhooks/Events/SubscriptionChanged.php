<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Webhooks\Events;

/**
 * The recipient was added to or removed from the suppression list (event "subscriptionchange").
 */
final class SubscriptionChanged extends HaskimailWebhookEvent {}
