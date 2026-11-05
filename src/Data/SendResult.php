<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Data;

use Carbon\CarbonImmutable;
use Haskimail\Laravel\Enums\ErrorCode;
use Haskimail\Laravel\Support\Time;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Result of accepting a single message, as returned by POST /email and
 * as each item of POST /email/batch.
 *
 * @implements Arrayable<string, mixed>
 */
final class SendResult implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly string $to,
        public readonly ?string $messageId,
        public readonly ?CarbonImmutable $submittedAt,
        public readonly int $errorCode,
        public readonly string $message,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $messageId = $data['MessageID'] ?? null;

        return new self(
            to: is_scalar($data['To'] ?? null) ? (string) $data['To'] : '',
            messageId: is_scalar($messageId) && (string) $messageId !== '' ? (string) $messageId : null,
            submittedAt: Time::parseMoscow($data['SubmittedAt'] ?? null),
            errorCode: is_numeric($data['ErrorCode'] ?? null) ? (int) $data['ErrorCode'] : -1,
            message: is_scalar($data['Message'] ?? null) ? (string) $data['Message'] : '',
        );
    }

    public function successful(): bool
    {
        return $this->errorCode === ErrorCode::Success->value;
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }

    /**
     * Whether a rejected message may succeed if sent again later (ErrorCode 36 or 100).
     */
    public function isRetryable(): bool
    {
        return $this->failed() && (ErrorCode::tryFrom($this->errorCode)?->isRetryable() ?? false);
    }

    /**
     * One ID per recipient: the API returns comma-separated IDs, in "To" order, for multi-recipient messages.
     *
     * @return list<string>
     */
    public function messageIds(): array
    {
        if ($this->messageId === null) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $this->messageId)), static fn (string $id): bool => $id !== ''));
    }

    /**
     * @return array{To: string, MessageID: string|null, SubmittedAt: string|null, ErrorCode: int, Message: string}
     */
    public function toArray(): array
    {
        return [
            'To' => $this->to,
            'MessageID' => $this->messageId,
            'SubmittedAt' => $this->submittedAt?->format('Y-m-d H:i:s'),
            'ErrorCode' => $this->errorCode,
            'Message' => $this->message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
