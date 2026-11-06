<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Webhooks;

use SensitiveParameter;

/**
 * Verifies the "secret" field of Haskimail event webhooks:
 * md5(recipient . message_id . signing key), see
 * https://haskimail.ru/developer/webhooks/webhooks-overview.
 *
 * The scheme is defined by Haskimail and only binds the recipient and message ID:
 * other fields (event type, time, URLs) are not covered. Combine it with HTTP Basic
 * credentials for full request authentication.
 *
 * The check distinguishes two failures so the endpoint can answer differently:
 *
 *  - {@see self::INVALID_SIGNATURE} — the secret is missing or does not match the
 *    key/fields. Haskimail never retries 4xx, and a forged request must not be retried,
 *    so this maps to 403.
 *  - {@see self::UNSUPPORTED_FORMAT} — the signature itself is valid, but the payload
 *    does not have the expected shape (e.g. a new message_id format). Rejecting it with
 *    403 would lose real events forever; it maps to 503 so Haskimail keeps redelivering
 *    while the SDK is updated.
 */
final class WebhookSignature
{
    /** Signature matched: the event is authentic and in the supported shape. */
    public const VALID = 'valid';

    /** Missing or wrong signature: refuse permanently (HTTP 403). */
    public const INVALID_SIGNATURE = 'invalid_signature';

    /** Valid signature but unrecognized field shape: refuse retryably (HTTP 503). */
    public const UNSUPPORTED_FORMAT = 'unsupported_format';

    /** An address whose top-level domain is letters only (or punycode), so a message ID cannot be glued onto it. */
    private const RECIPIENT = '/^[^\s@]+@(?:[^\s@.]+\.)+(?:\p{L}{2,63}|xn--[a-z0-9-]{1,59})$/Du';

    private const MESSAGE_ID = '/^(?:[0-9a-f]{32}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})?$/Di';

    public static function compute(string $recipient, string $messageId, #[SensitiveParameter] string $signingKey): string
    {
        return md5($recipient.$messageId.$signingKey);
    }

    /**
     * Verify a single decoded webhook event.
     *
     * @param  array<array-key, mixed>  $payload
     * @return self::* one of {@see self::VALID}, {@see self::INVALID_SIGNATURE}, {@see self::UNSUPPORTED_FORMAT}
     */
    public static function verify(array $payload, #[SensitiveParameter] string $signingKey): string
    {
        if ($signingKey === '') {
            return self::INVALID_SIGNATURE;
        }

        $secret = $payload['secret'] ?? null;
        $recipient = $payload['recipient'] ?? null;
        $messageId = $payload['message_id'] ?? '';

        // A missing, empty or non-string secret can never authenticate the request, and a
        // non-string recipient/message_id cannot be part of Haskimail's string concatenation.
        if (! is_string($secret) || $secret === '' || ! is_string($recipient) || ! is_string($messageId)) {
            return self::INVALID_SIGNATURE;
        }

        if (! hash_equals(self::compute($recipient, $messageId, $signingKey), strtolower($secret))) {
            return self::INVALID_SIGNATURE;
        }

        // The vendor's scheme concatenates the fields without a separator. Pinning the
        // recipient's shape (an address) prevents moving characters from one field into the
        // other while keeping the same signature — a replay/forgery, so it stays 403.
        if (preg_match(self::RECIPIENT, $recipient) !== 1) {
            return self::INVALID_SIGNATURE;
        }

        // "email" duplicates "recipient" but is not covered by the signature: refuse any mismatch.
        $email = $payload['email'] ?? null;
        if ($email !== null && (! is_string($email) || strcasecmp($email, $recipient) !== 0)) {
            return self::INVALID_SIGNATURE;
        }

        // A valid signature with an unrecognized message_id shape is a *new* Haskimail format,
        // not a forgery: report it as unsupported so the caller can ask Haskimail to redeliver.
        if (preg_match(self::MESSAGE_ID, $messageId) !== 1) {
            return self::UNSUPPORTED_FORMAT;
        }

        return self::VALID;
    }

    /**
     * @param  array<array-key, mixed>  $payload  A single decoded webhook event.
     */
    public static function isValid(array $payload, #[SensitiveParameter] string $signingKey): bool
    {
        return self::verify($payload, $signingKey) === self::VALID;
    }
}
