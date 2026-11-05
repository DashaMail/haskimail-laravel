<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Webhooks;

use Haskimail\Laravel\Enums\WebhookEventType;
use Haskimail\Laravel\Webhooks\Events\HaskimailWebhookEvent;
use Haskimail\Laravel\Webhooks\Events\LinkClicked;
use Haskimail\Laravel\Webhooks\Events\MessageBounced;
use Haskimail\Laravel\Webhooks\Events\MessageDelivered;
use Haskimail\Laravel\Webhooks\Events\MessageOpened;
use Haskimail\Laravel\Webhooks\Events\SpamComplaintReceived;
use Haskimail\Laravel\Webhooks\Events\SubscriptionChanged;
use Haskimail\Laravel\Webhooks\Events\WebhookReceived;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Dispatches Laravel events for verified webhook payloads, at most once per event.
 *
 * Deduplication is two-phase: a short lock while listeners run, and a "done" marker
 * written only after they succeed. A crashed or killed worker therefore never causes
 * Haskimail's redelivery to be dropped: the lock simply expires.
 */
final class WebhookProcessor
{
    private const CACHE_PREFIX = 'haskimail:webhook:';

    private const LOCK_SECONDS = 120;

    public function __construct(
        private readonly Dispatcher $events,
        private readonly ?CacheFactory $cache = null,
        private readonly ?string $cacheStore = null,
        private readonly int $deduplicateTtl = 0,
    ) {}

    public function process(WebhookEvent $event): ProcessingResult
    {
        $store = $this->cache !== null && $this->deduplicateTtl > 0 ? $this->cache->store($this->cacheStore) : null;

        if ($store === null) {
            $this->dispatch($event);

            return ProcessingResult::Processed;
        }

        $done = self::CACHE_PREFIX.'done:'.$event->fingerprint();
        $lock = self::CACHE_PREFIX.'lock:'.$event->fingerprint();

        // add() is atomic on every cache driver that supports it. The "done" marker is
        // checked while holding the lock, so a concurrent finish cannot be missed.
        if (! $store->add($lock, 1, self::LOCK_SECONDS)) {
            return $store->has($done) ? ProcessingResult::Duplicate : ProcessingResult::InProgress;
        }

        try {
            if ($store->has($done)) {
                return ProcessingResult::Duplicate;
            }

            $this->dispatch($event);
            $store->put($done, 1, $this->deduplicateTtl);
        } finally {
            $store->forget($lock);
        }

        return ProcessingResult::Processed;
    }

    private function dispatch(WebhookEvent $event): void
    {
        $this->events->dispatch(new WebhookReceived($event));

        $specific = self::eventClass($event->type);
        if ($specific !== null) {
            $this->events->dispatch(new $specific($event));
        }
    }

    /**
     * @return class-string<HaskimailWebhookEvent>|null
     */
    private static function eventClass(?WebhookEventType $type): ?string
    {
        return match ($type) {
            WebhookEventType::Delivered => MessageDelivered::class,
            WebhookEventType::Bounced => MessageBounced::class,
            WebhookEventType::Complained => SpamComplaintReceived::class,
            WebhookEventType::Opened => MessageOpened::class,
            WebhookEventType::Clicked => LinkClicked::class,
            WebhookEventType::SubscriptionChange => SubscriptionChanged::class,
            null => null,
        };
    }
}
