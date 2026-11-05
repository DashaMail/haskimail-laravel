<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Webhooks\Jobs;

use Haskimail\Laravel\Webhooks\ProcessingResult;
use Haskimail\Laravel\Webhooks\WebhookEvent;
use Haskimail\Laravel\Webhooks\WebhookProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Processes a verified webhook event on the queue, so the endpoint can answer
 * Haskimail immediately: it waits at most 10 seconds and does not retry timeouts.
 */
final class ProcessWebhookEvent implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 10;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 300, 900];

    /**
     * @param  array<array-key, mixed>  $payload  Verified payload, without its signature.
     */
    public function __construct(public readonly array $payload) {}

    public function handle(WebhookProcessor $processor): void
    {
        if ($processor->process(WebhookEvent::fromPayload($this->payload)) === ProcessingResult::InProgress) {
            $this->release(30);
        }
    }
}
