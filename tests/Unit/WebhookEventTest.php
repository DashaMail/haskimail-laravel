<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Tests\Unit;

use Haskimail\Laravel\Enums\BounceCategory;
use Haskimail\Laravel\Enums\SuppressionReason;
use Haskimail\Laravel\Enums\WebhookEventType;
use Haskimail\Laravel\Webhooks\WebhookEvent;
use Haskimail\Laravel\Webhooks\WebhookSignature;
use PHPUnit\Framework\TestCase;

final class WebhookEventTest extends TestCase
{
    /**
     * Bounce payload exactly as documented.
     *
     * @return array<string, mixed>
     */
    public static function bounce(): array
    {
        return [
            'event' => 'bounced',
            'recipient' => 'old-address@example.com',
            'email' => 'old-address@example.com',
            'message_id' => 'a41d7c9e2b6f4e0d8c3b5a1f9e7d2c60',
            'code' => '550',
            'description' => '550 5.1.1 The email account that you tried to reach does not exist',
            'error' => '550 5.1.1 The email account that you tried to reach does not exist',
            'bounce_category' => 'hrd',
            'user_id' => '48213',
            'campaign_id' => 'orders',
            'member_id' => '0',
            'event_time' => '2026-10-07 14:30:15',
            'event_time_utc' => '2026-10-07 11:30:15',
            'secret' => '5f2b0c1d9a7e4c3b8d6f1a2e3c4b5d6e',
            'custom_vars' => '{"order_id":"1042"}',
            'server_id' => 11834,
            'stream_id' => '2081',
        ];
    }

    public function test_it_parses_a_bounce(): void
    {
        $event = WebhookEvent::fromPayload(self::bounce());

        self::assertSame(WebhookEventType::Bounced, $event->type);
        self::assertTrue($event->is(WebhookEventType::Bounced));
        self::assertSame('old-address@example.com', $event->recipient);
        self::assertSame('a41d7c9e2b6f4e0d8c3b5a1f9e7d2c60', $event->messageId);
        self::assertSame('orders', $event->tag);
        self::assertSame(['order_id' => '1042'], $event->metadata);
        self::assertSame(11834, $event->serverId);
        self::assertSame('2081', $event->streamId);
        self::assertSame('550', $event->bounceCode);
        self::assertSame(BounceCategory::Hard, $event->bounceCategory);
        self::assertSame('2026-10-07T11:30:15+00:00', $event->occurredAt?->toIso8601String());
        self::assertSame('48213', $event->get('user_id'));
        self::assertSame(array_diff_key(self::bounce(), ['secret' => true]), $event->toArray(), 'The signature must not be kept.');
        self::assertNull($event->get('secret'));
    }

    public function test_it_falls_back_to_moscow_time(): void
    {
        $payload = self::bounce();
        unset($payload['event_time_utc']);

        self::assertSame('2026-10-07T11:30:15+00:00', WebhookEvent::fromPayload($payload)->occurredAt?->toIso8601String());
    }

    public function test_spam_complaint_in_its_documented_shape(): void
    {
        $event = WebhookEvent::fromPayload([
            'event' => 'COMPLAINED',
            'message_id' => '883953f4-6105-42a2-a16a-77a8eac79483',
            'campaign_id' => 'Test',
            'custom_vars' => ['a_key' => 'a_value'],
            'server_id' => 23,
            'stream_id' => 25,
            'recipient' => 'ivan@example.com',
            'event_time' => '2019-11-05 16:33:54',
        ]);

        self::assertSame(WebhookEventType::Complained, $event->type);
        self::assertSame(['a_key' => 'a_value'], $event->metadata);
        self::assertSame('25', $event->streamId);
        self::assertNull($event->bounceCode);
    }

    public function test_click_and_subscription_change_fields(): void
    {
        $click = WebhookEvent::fromPayload(['event' => 'clicked', 'recipient' => 'a@example.com', 'url_original' => 'https://example.com/o/1', 'url_pers' => 'https://example.com/o/1?u=a']);
        self::assertSame('https://example.com/o/1', $click->originalUrl);
        self::assertSame('https://example.com/o/1?u=a', $click->personalizedUrl);

        $change = WebhookEvent::fromPayload(['event' => 'subscriptionchange', 'recipient' => 'a@example.com', 'message_id' => '', 'SuppressSending' => 'true', 'SuppressionReason' => 'ManualSuppression']);
        self::assertTrue($change->suppressSending);
        self::assertSame(SuppressionReason::ManualSuppression, $change->suppressionReason);
        self::assertNull($change->messageId);
    }

    public function test_hostile_or_malformed_values_do_not_break_parsing(): void
    {
        $event = WebhookEvent::fromPayload([
            'event' => ['array'],
            'recipient' => ['x'],
            'custom_vars' => '{not json',
            'server_id' => 'abc',
            'event_time_utc' => 'yesterday',
            'bounce_category' => 'zzz',
        ]);

        self::assertNull($event->type);
        self::assertSame('', $event->recipient);
        self::assertSame([], $event->metadata);
        self::assertNull($event->serverId);
        self::assertNull($event->occurredAt);
        self::assertNull($event->bounceCategory);
    }

    public function test_unknown_event_types_are_kept_raw(): void
    {
        $event = WebhookEvent::fromPayload(['event' => 'future_event', 'recipient' => 'a@example.com']);

        self::assertNull($event->type);
        self::assertSame('future_event', $event->rawType);
    }

    public function test_fingerprint_identifies_duplicates_only(): void
    {
        $a = WebhookEvent::fromPayload(self::bounce());
        $b = WebhookEvent::fromPayload(['secret' => 'other'] + self::bounce());
        $c = WebhookEvent::fromPayload(['event_time_utc' => '2026-10-07 11:31:00'] + self::bounce());

        self::assertSame($a->fingerprint(), $b->fingerprint());
        self::assertNotSame($a->fingerprint(), $c->fingerprint());
    }

    public function test_signature_matches_the_documented_algorithm(): void
    {
        $payload = ['secret' => md5('old-address@example.com'.'a41d7c9e2b6f4e0d8c3b5a1f9e7d2c60'.'key')] + self::bounce();

        self::assertTrue(WebhookSignature::isValid($payload, 'key'));
        self::assertTrue(WebhookSignature::isValid(['secret' => strtoupper($payload['secret'])] + $payload, 'key'));
        self::assertFalse(WebhookSignature::isValid($payload, 'other-key'));
        self::assertFalse(WebhookSignature::isValid($payload, ''));
        self::assertFalse(WebhookSignature::isValid(['recipient' => 'attacker@example.com'] + $payload, 'key'));
        self::assertFalse(WebhookSignature::isValid(array_diff_key($payload, ['secret' => 1]), 'key'));
        self::assertFalse(WebhookSignature::isValid(['secret' => ['x']] + $payload, 'key'));
    }

    public function test_valid_but_unrecognized_shape_is_distinguished_from_a_forgery(): void
    {
        $recipient = 'ivan@example.com';
        $messageId = 'a-new-format-not-a-uuid';
        $key = 'key';
        $payload = ['recipient' => $recipient, 'message_id' => $messageId, 'secret' => md5($recipient.$messageId.$key)];

        // Signature is valid but the message_id shape is not recognized yet.
        self::assertSame(WebhookSignature::UNSUPPORTED_FORMAT, WebhookSignature::verify($payload, $key));
        self::assertFalse(WebhookSignature::isValid($payload, $key));

        // A wrong key is a plain forgery, whatever the message_id shape.
        $payload['secret'] = md5($recipient.$messageId.'wrong');
        self::assertSame(WebhookSignature::INVALID_SIGNATURE, WebhookSignature::verify($payload, $key));
    }

    public function test_signature_for_events_without_message(): void
    {
        $payload = ['recipient' => 'a@example.com', 'message_id' => '', 'secret' => md5('a@example.com'.'k')];

        self::assertTrue(WebhookSignature::isValid($payload, 'k'));
    }

    public function test_signature_cannot_be_replayed_onto_another_address(): void
    {
        $id = 'a41d7c9e2b6f4e0d8c3b5a1f9e7d2c60';
        $captured = ['recipient' => 'bob@corp.de', 'message_id' => $id, 'secret' => md5('bob@corp.de'.$id.'k')];

        // Shifting characters between the concatenated fields keeps the md5 input identical.
        self::assertFalse(WebhookSignature::isValid(['recipient' => '', 'message_id' => 'bob@corp.de'.$id, 'email' => 'victim@example.com', 'secret' => $captured['secret']], 'k'));
        self::assertFalse(WebhookSignature::isValid(['recipient' => 'bob@corp.d', 'message_id' => 'e'.$id, 'secret' => $captured['secret']], 'k'));
        self::assertFalse(WebhookSignature::isValid(['message_id' => 100] + $captured, 'k'));
        // "email" is unsigned and must match "recipient".
        self::assertFalse(WebhookSignature::isValid($captured + ['email' => 'victim@example.com'], 'k'));
        self::assertTrue(WebhookSignature::isValid($captured + ['email' => 'BOB@corp.de'], 'k'));
    }

    public function test_message_id_cannot_be_glued_onto_the_recipient(): void
    {
        $id = 'a41d7c9e2b6f4e0d8c3b5a1f9e7d2c60';
        $secret = md5('ivan@example.com'.$id.'k');

        self::assertFalse(WebhookSignature::isValid(['recipient' => 'ivan@example.com'.$id, 'message_id' => '', 'secret' => $secret], 'k'));
        self::assertFalse(WebhookSignature::isValid(['recipient' => 'ivan@example.com'.$id, 'secret' => $secret], 'k'));
        self::assertFalse(WebhookSignature::isValid(['recipient' => "ivan@example.com\n", 'message_id' => $id, 'secret' => md5("ivan@example.com\n".$id.'k')], 'k'));
        self::assertTrue(WebhookSignature::isValid(['recipient' => 'info@пример.рф', 'message_id' => $id, 'secret' => md5('info@пример.рф'.$id.'k')], 'k'));
        self::assertTrue(WebhookSignature::isValid(['recipient' => 'info@xn--e1afmkfd.xn--p1ai', 'message_id' => $id, 'secret' => md5('info@xn--e1afmkfd.xn--p1ai'.$id.'k')], 'k'));
    }
}
