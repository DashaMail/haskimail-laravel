<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Webhooks\Http\Controllers;

use Haskimail\Laravel\Webhooks\Jobs\ProcessWebhookEvent;
use Haskimail\Laravel\Webhooks\ProcessingResult;
use Haskimail\Laravel\Webhooks\WebhookEvent;
use Haskimail\Laravel\Webhooks\WebhookProcessor;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives Haskimail event webhooks.
 *
 * 2xx = processed, queued or duplicate; 400 = malformed payload; 5xx (a failing
 * listener, or the event is being processed elsewhere) = Haskimail retries roughly
 * once a minute, up to 60 times. Haskimail waits at most 10 seconds and never
 * retries a timeout: enable haskimail.webhooks.queue for slow listeners.
 */
final class WebhookController
{
    /** Haskimail sends one event per request; lists are accepted but bounded. */
    public const MAX_EVENTS = 100;

    public function __invoke(Request $request, WebhookProcessor $processor, Config $config, Bus $bus): Response
    {
        $payload = json_decode($request->getContent(), true, 64);

        if (! is_array($payload) || $payload === []) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $events = array_is_list($payload) ? $payload : [$payload];

        if (count($events) > self::MAX_EVENTS) {
            return new Response('', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        foreach ($events as $event) {
            if (! is_array($event)) {
                return new Response('', Response::HTTP_BAD_REQUEST);
            }
        }

        if ((bool) $config->get('haskimail.webhooks.queue.enabled', false)) {
            $connection = $config->get('haskimail.webhooks.queue.connection');
            $queue = $config->get('haskimail.webhooks.queue.queue');

            foreach ($events as $event) {
                unset($event['secret']);

                $bus->dispatch((new ProcessWebhookEvent($event))
                    ->onConnection(is_string($connection) && $connection !== '' ? $connection : null)
                    ->onQueue(is_string($queue) && $queue !== '' ? $queue : null));
            }

            return new Response('', Response::HTTP_OK);
        }

        $retryLater = false;

        foreach ($events as $event) {
            if ($processor->process(WebhookEvent::fromPayload($event)) === ProcessingResult::InProgress) {
                $retryLater = true;
            }
        }

        // Another worker is handling one of these events right now: ask Haskimail to
        // redeliver; events already processed will be skipped as duplicates.
        return new Response('', $retryLater ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_OK);
    }
}
