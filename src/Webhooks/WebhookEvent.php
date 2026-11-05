<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Webhooks;

use Carbon\CarbonImmutable;
use Haskimail\Laravel\Enums\BounceCategory;
use Haskimail\Laravel\Enums\SuppressionReason;
use Haskimail\Laravel\Enums\WebhookEventType;
use Haskimail\Laravel\Support\Time;
use Illuminate\Contracts\Support\Arrayable;
use JsonException;

/**
 * Normalized, immutable view of one Haskimail event webhook.
 *
 * Every documented field is exposed with a proper type; the untouched payload
 * remains available through $payload / get().
 *
 * @implements Arrayable<array-key, mixed>
 */
final class WebhookEvent implements Arrayable
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(
        public readonly ?WebhookEventType $type,
        public readonly string $rawType,
        public readonly string $recipient,
        public readonly ?string $messageId,
        public readonly ?string $tag,
        public readonly array $metadata,
        public readonly ?int $serverId,
        public readonly ?string $streamId,
        public readonly ?CarbonImmutable $occurredAt,
        public readonly ?string $bounceCode,
        public readonly ?string $bounceDescription,
        public readonly ?BounceCategory $bounceCategory,
        public readonly ?string $originalUrl,
        public readonly ?string $personalizedUrl,
        public readonly ?bool $suppressSending,
        public readonly ?SuppressionReason $suppressionReason,
        public readonly array $payload,
    ) {}

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        // The signature is a credential derived from the signing key: never keep it around
        // where it could end up in logs.
        unset($payload['secret']);

        $recipient = self::string($payload, 'recipient') ?? self::string($payload, 'email') ?? '';

        return new self(
            type: WebhookEventType::fromPayload($payload['event'] ?? null),
            rawType: self::string($payload, 'event') ?? '',
            recipient: $recipient,
            messageId: self::string($payload, 'message_id'),
            tag: self::string($payload, 'campaign_id'),
            metadata: self::metadata($payload['custom_vars'] ?? null),
            serverId: is_numeric($payload['server_id'] ?? null) ? (int) $payload['server_id'] : null,
            streamId: self::string($payload, 'stream_id'),
            occurredAt: (Time::parseUtc($payload['event_time_utc'] ?? null) ?? Time::parseMoscow($payload['event_time'] ?? null))?->utc(),
            bounceCode: self::string($payload, 'code'),
            bounceDescription: self::string($payload, 'description') ?? self::string($payload, 'error'),
            bounceCategory: BounceCategory::tryFrom(strtolower(self::string($payload, 'bounce_category') ?? '')),
            originalUrl: self::string($payload, 'url_original'),
            personalizedUrl: self::string($payload, 'url_pers'),
            suppressSending: self::bool($payload['SuppressSending'] ?? null),
            suppressionReason: SuppressionReason::tryFrom(self::string($payload, 'SuppressionReason') ?? ''),
            payload: $payload,
        );
    }

    public function is(WebhookEventType $type): bool
    {
        return $this->type === $type;
    }

    /**
     * Read any raw payload field (Laravel "dot" notation is not applied).
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->payload) ? $this->payload[$key] : $default;
    }

    /**
     * Stable identity used to drop duplicate deliveries, as recommended by the documentation.
     */
    public function fingerprint(): string
    {
        return hash('sha256', implode("\0", [
            strtolower($this->rawType),
            $this->messageId ?? '',
            strtolower($this->recipient),
            self::string($this->payload, 'event_time_utc') ?? self::string($this->payload, 'event_time') ?? '',
            $this->originalUrl ?? '',
        ]));
    }

    /**
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private static function string(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }

        $value = (string) $value;

        return $value === '' ? null : $value;
    }

    private static function bool(mixed $value): ?bool
    {
        return match (true) {
            is_bool($value) => $value,
            $value === 'true', $value === '1', $value === 1 => true,
            $value === 'false', $value === '0', $value === 0 => false,
            default => null,
        };
    }

    /**
     * "custom_vars" holds the message Metadata as a JSON string (sometimes as an object).
     *
     * @return array<string, mixed>
     */
    private static function metadata(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            try {
                $value = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return [];
            }
        }

        if (! is_array($value) || array_is_list($value)) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
