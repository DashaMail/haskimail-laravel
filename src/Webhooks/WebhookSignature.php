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
 */
final class WebhookSignature
{
    /** An address whose top-level domain is letters only (or punycode), so a message ID cannot be glued onto it. */
    private const RECIPIENT = '/^[^\s@]+@(?:[^\s@.]+\.)+(?:\p{L}{2,63}|xn--[a-z0-9-]{1,59})$/Du';

    private const MESSAGE_ID = '/^(?:[0-9a-f]{32}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})?$/Di';

    public static function compute(string $recipient, string $messageId, #[SensitiveParameter] string $signingKey): string
    {
        return md5($recipient.$messageId.$signingKey);
    }

    /**
     * @param  array<array-key, mixed>  $payload  A single decoded webhook event.
     */
    public static function isValid(array $payload, #[SensitiveParameter] string $signingKey): bool
    {
        if ($signingKey === '') {
            return false;
        }

        $secret = $payload['secret'] ?? null;
        $recipient = $payload['recipient'] ?? null;
        $messageId = $payload['message_id'] ?? '';

        if (! is_string($secret) || $secret === '' || ! is_string($recipient) || ! is_string($messageId)) {
            return false;
        }

        // The vendor's scheme concatenates both fields without a separator. Pinning their
        // shapes (an address; a 32-hex or UUID message ID, or none) prevents moving
        // characters from one field into the other while keeping the same signature.
        if (preg_match(self::RECIPIENT, $recipient) !== 1 || preg_match(self::MESSAGE_ID, $messageId) !== 1) {
            return false;
        }

        // "email" duplicates "recipient" but is not covered by the signature: refuse any mismatch.
        $email = $payload['email'] ?? null;
        if ($email !== null && (! is_string($email) || strcasecmp($email, $recipient) !== 0)) {
            return false;
        }

        return hash_equals(self::compute($recipient, (string) $messageId, $signingKey), strtolower($secret));
    }
}
