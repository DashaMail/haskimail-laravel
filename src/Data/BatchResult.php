<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Data;

use ArrayIterator;
use Countable;
use Illuminate\Contracts\Support\Arrayable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * Per-message results of a batch request, in the same order as the request.
 *
 * The API answers HTTP 200 even if some messages were rejected, so always check
 * failed() / allSuccessful().
 *
 * @implements IteratorAggregate<int, SendResult>
 * @implements Arrayable<int, array<string, mixed>>
 */
final class BatchResult implements Arrayable, Countable, IteratorAggregate, JsonSerializable
{
    /**
     * @param  list<SendResult>  $results
     */
    public function __construct(public readonly array $results) {}

    /**
     * @param  array<array-key, mixed>  $items
     */
    public static function fromArray(array $items): self
    {
        $results = [];

        foreach (array_values($items) as $item) {
            $results[] = SendResult::fromArray(is_array($item) ? $item : []);
        }

        return new self($results);
    }

    /**
     * Messages Haskimail accepted for delivery.
     *
     * @return array<int, SendResult> Keys preserve the position of each message in the request.
     */
    public function accepted(): array
    {
        return array_filter($this->results, static fn (SendResult $r): bool => $r->successful());
    }

    /**
     * Messages Haskimail rejected.
     *
     * @return array<int, SendResult> Keys preserve the position of each message in the request.
     */
    public function rejected(): array
    {
        return array_filter($this->results, static fn (SendResult $r): bool => $r->failed());
    }

    /**
     * Rejected messages that may succeed if sent again later (ErrorCode 36 or 100).
     *
     * @return array<int, SendResult> Keys preserve the position of each message in the request.
     */
    public function retryable(): array
    {
        return array_filter($this->results, static fn (SendResult $r): bool => $r->isRetryable());
    }

    public function allAccepted(): bool
    {
        return $this->rejected() === [];
    }

    public function hasRejections(): bool
    {
        return ! $this->allAccepted();
    }

    public function get(int $index): ?SendResult
    {
        return $this->results[$index] ?? null;
    }

    public function count(): int
    {
        return count($this->results);
    }

    /**
     * @return Traversable<int, SendResult>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->results);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn (SendResult $r): array => $r->toArray(), $this->results);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
