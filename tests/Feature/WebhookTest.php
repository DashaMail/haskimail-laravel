<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Tests\Feature;

use Haskimail\Laravel\Enums\BounceCategory;
use Haskimail\Laravel\Tests\TestCase;
use Haskimail\Laravel\Tests\Unit\WebhookEventTest;
use Haskimail\Laravel\Webhooks\Events\LinkClicked;
use Haskimail\Laravel\Webhooks\Events\MessageBounced;
use Haskimail\Laravel\Webhooks\Events\MessageDelivered;
use Haskimail\Laravel\Webhooks\Events\SpamComplaintReceived;
use Haskimail\Laravel\Webhooks\Events\WebhookReceived;
use Haskimail\Laravel\Webhooks\Jobs\ProcessWebhookEvent;
use Haskimail\Laravel\Webhooks\WebhookSignature;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use RuntimeException;

final class WebhookTest extends TestCase
{
    private const KEY = 'webhook-signing-key';

    private const EVENTS = [WebhookReceived::class, MessageBounced::class, MessageDelivered::class, LinkClicked::class];

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('haskimail.webhooks.enabled', true);
        $app['config']->set('haskimail.webhooks.signing_key', self::KEY);
        $app['config']->set('cache.default', 'array');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function signed(array $overrides = []): array
    {
        $payload = $overrides + WebhookEventTest::bounce();
        $payload['secret'] = WebhookSignature::compute((string) $payload['recipient'], (string) $payload['message_id'], self::KEY);

        return $payload;
    }

    public function test_a_signed_event_dispatches_generic_and_specific_events(): void
    {
        Event::fake(self::EVENTS);

        $this->postJson('/haskimail/webhook', self::signed())->assertOk();

        Event::assertDispatched(WebhookReceived::class, 1);
        Event::assertDispatched(MessageBounced::class, fn (MessageBounced $e): bool => $e->event->bounceCategory === BounceCategory::Hard
            && $e->event->metadata === ['order_id' => '1042']);
        Event::assertNotDispatched(MessageDelivered::class);
    }

    public function test_the_route_is_named_and_csrf_free(): void
    {
        self::assertTrue(Route::has('haskimail.webhook'));
        self::assertNotContains('web', Route::getRoutes()->getByName('haskimail.webhook')?->gatherMiddleware() ?? ['web']);
    }

    public function test_invalid_signatures_are_rejected_without_retry(): void
    {
        Event::fake(self::EVENTS);
        $payload = self::signed();
        $payload['recipient'] = 'someone-else@example.com';

        $this->postJson('/haskimail/webhook', $payload)->assertForbidden();
        $this->postJson('/haskimail/webhook', array_diff_key(self::signed(), ['secret' => true]))->assertForbidden();

        Event::assertNothingDispatched();
    }

    public function test_every_event_of_a_list_must_be_signed(): void
    {
        Event::fake(self::EVENTS);
        $forged = self::signed(['event' => 'clicked']);
        $forged['secret'] = str_repeat('0', 32);

        $this->postJson('/haskimail/webhook', [self::signed(), $forged])->assertForbidden();

        Event::assertNothingDispatched();
    }

    public function test_lists_of_events_are_processed(): void
    {
        Event::fake(self::EVENTS);

        $this->postJson('/haskimail/webhook', [
            self::signed(['event' => 'delivered']),
            self::signed(['event' => 'clicked', 'url_original' => 'https://example.com']),
        ])->assertOk();

        Event::assertDispatched(MessageDelivered::class, 1);
        Event::assertDispatched(LinkClicked::class, 1);
    }

    public function test_malformed_bodies_are_rejected(): void
    {
        // Unauthenticated garbage is simply forbidden...
        $this->call('POST', '/haskimail/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{oops')->assertForbidden();

        // ...authenticated garbage is a bad request (Basic auth only).
        config()->set('haskimail.webhooks.signing_key', null);
        config()->set('haskimail.webhooks.username', 'haskimail');
        config()->set('haskimail.webhooks.password', 's3cret');
        $auth = ['PHP_AUTH_USER' => 'haskimail', 'PHP_AUTH_PW' => 's3cret', 'CONTENT_TYPE' => 'application/json'];
        $this->call('POST', '/haskimail/webhook', [], [], [], $auth, '{oops')->assertBadRequest();
        $this->call('POST', '/haskimail/webhook', [], [], [], $auth, '[]')->assertBadRequest();
        $this->call('POST', '/haskimail/webhook', [], [], [], $auth, '["x"]')->assertBadRequest();
    }

    public function test_only_post_is_accepted(): void
    {
        $this->get('/haskimail/webhook')->assertMethodNotAllowed();
    }

    public function test_duplicate_deliveries_are_processed_once(): void
    {
        Event::fake(self::EVENTS);

        $this->postJson('/haskimail/webhook', self::signed())->assertOk();
        $this->postJson('/haskimail/webhook', self::signed())->assertOk();

        Event::assertDispatched(MessageBounced::class, 1);
    }

    public function test_failing_listeners_return_5xx_so_haskimail_retries_and_the_retry_is_processed(): void
    {
        $calls = 0;
        Event::listen(MessageBounced::class, function () use (&$calls): void {
            if (++$calls === 1) {
                throw new RuntimeException('database is down');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->postJson('/haskimail/webhook', self::signed());
            self::fail('Expected exception');
        } catch (RuntimeException $e) {
            self::assertSame('database is down', $e->getMessage());
        }

        $this->withExceptionHandling();
        $this->postJson('/haskimail/webhook', self::signed())->assertOk();
        self::assertSame(2, $calls);
    }

    public function test_basic_auth(): void
    {
        config()->set('haskimail.webhooks.signing_key', null);
        config()->set('haskimail.webhooks.username', 'haskimail');
        config()->set('haskimail.webhooks.password', 's3cret');
        Event::fake(self::EVENTS);
        $payload = ['secret' => 'irrelevant'] + WebhookEventTest::bounce();

        $this->postJson('/haskimail/webhook', $payload)->assertUnauthorized()->assertHeader('WWW-Authenticate');
        $this->postJson('/haskimail/webhook', $payload, ['Authorization' => 'Basic '.base64_encode('haskimail:wrong')])->assertUnauthorized();
        $this->postJson('/haskimail/webhook', $payload, ['Authorization' => 'Basic '.base64_encode('haskimail:s3cret')])->assertOk();

        Event::assertDispatched(MessageBounced::class, 1);
    }

    public function test_when_both_methods_are_configured_both_must_pass(): void
    {
        config()->set('haskimail.webhooks.username', 'haskimail');
        config()->set('haskimail.webhooks.password', 's3cret');
        Event::fake(self::EVENTS);
        $auth = ['Authorization' => 'Basic '.base64_encode('haskimail:s3cret')];

        $this->postJson('/haskimail/webhook', self::signed())->assertUnauthorized();
        $this->postJson('/haskimail/webhook', ['secret' => str_repeat('a', 32)] + self::signed(), $auth)->assertForbidden();
        $this->postJson('/haskimail/webhook', self::signed(), $auth)->assertOk();

        Event::assertDispatched(MessageBounced::class, 1);
    }

    public function test_unauthenticated_webhooks_are_refused_when_nothing_is_configured(): void
    {
        config()->set('haskimail.webhooks.signing_key', '');
        Event::fake(self::EVENTS);

        // 503 makes Haskimail redeliver once the application is configured.
        $this->postJson('/haskimail/webhook', WebhookEventTest::bounce())->assertServiceUnavailable();

        Event::assertNothingDispatched();
    }

    public function test_unsigned_events_are_refused_even_with_basic_auth(): void
    {
        config()->set('haskimail.webhooks.username', 'haskimail');
        config()->set('haskimail.webhooks.password', 's3cret');
        Event::fake(self::EVENTS + [10 => SpamComplaintReceived::class]);
        $auth = ['Authorization' => 'Basic '.base64_encode('haskimail:s3cret')];
        $complaint = self::signed(['event' => 'complained']);

        $this->postJson('/haskimail/webhook', array_diff_key($complaint, ['secret' => true]), $auth)->assertForbidden();
        $this->postJson('/haskimail/webhook', $complaint, $auth)->assertOk();

        Event::assertDispatched(SpamComplaintReceived::class, 1);
    }

    public function test_a_new_message_id_format_is_redelivered_instead_of_lost(): void
    {
        // A valid signature but a message_id the SDK does not recognize yet must not be
        // answered 403 (Haskimail never retries 4xx and the event would be lost forever).
        Event::fake(self::EVENTS);
        $recipient = 'ivan@example.com';
        $messageId = 'a-new-format-that-is-not-a-uuid-or-32-hex';
        $payload = array_merge(WebhookEventTest::bounce(), [
            'recipient' => $recipient,
            'email' => $recipient,
            'message_id' => $messageId,
            'secret' => WebhookSignature::compute($recipient, $messageId, self::KEY),
        ]);

        $this->postJson('/haskimail/webhook', $payload)->assertServiceUnavailable();

        Event::assertNothingDispatched();
    }

    public function test_oversized_event_lists_are_refused(): void
    {
        Event::fake(self::EVENTS);

        $this->postJson('/haskimail/webhook', array_fill(0, 101, self::signed()))->assertForbidden();

        Event::assertNothingDispatched();
    }

    public function test_queue_mode_answers_immediately_and_processes_on_the_queue(): void
    {
        config()->set('haskimail.webhooks.queue', ['enabled' => true, 'connection' => null, 'queue' => 'webhooks']);
        \Illuminate\Support\Facades\Queue::fake();

        $this->postJson('/haskimail/webhook', self::signed())->assertOk();

        \Illuminate\Support\Facades\Queue::assertPushedOn('webhooks', ProcessWebhookEvent::class, function (ProcessWebhookEvent $job): bool {
            return ! array_key_exists('secret', $job->payload) && $job->payload['event'] === 'bounced';
        });
    }

    public function test_queued_job_dispatches_events(): void
    {
        Event::fake(self::EVENTS);

        $this->app->call([new ProcessWebhookEvent(WebhookEventTest::bounce()), 'handle']);

        Event::assertDispatched(MessageBounced::class, 1);
    }

    public function test_signature_replay_onto_another_recipient_is_refused(): void
    {
        Event::fake(self::EVENTS);
        $captured = self::signed(['event' => 'delivered']);
        $forged = ['recipient' => '', 'message_id' => $captured['recipient'].$captured['message_id'], 'email' => 'victim@example.com', 'secret' => $captured['secret'], 'event' => 'subscriptionchange', 'SuppressSending' => 'true'];

        $this->postJson('/haskimail/webhook', $forged)->assertForbidden();
        $this->postJson('/haskimail/webhook', ['email' => 'victim@example.com'] + $captured)->assertForbidden();

        Event::assertNothingDispatched();
    }

    public function test_basic_auth_requires_a_password(): void
    {
        config()->set('haskimail.webhooks.signing_key', null);
        config()->set('haskimail.webhooks.username', 'haskimail');

        $this->postJson('/haskimail/webhook', self::signed(), ['Authorization' => 'Basic '.base64_encode('haskimail:')])
            ->assertServiceUnavailable();
    }

    public function test_events_being_processed_elsewhere_are_redelivered_later(): void
    {
        Event::fake(self::EVENTS);
        $fingerprint = \Haskimail\Laravel\Webhooks\WebhookEvent::fromPayload(self::signed())->fingerprint();

        // Simulates a worker that is still running (or crashed) while holding the lock.
        cache()->add('haskimail:webhook:lock:'.$fingerprint, 1, 120);
        $this->postJson('/haskimail/webhook', self::signed())->assertServiceUnavailable();
        Event::assertNothingDispatched();

        cache()->forget('haskimail:webhook:lock:'.$fingerprint);
        $this->postJson('/haskimail/webhook', self::signed())->assertOk();
        Event::assertDispatched(MessageBounced::class, 1);
    }

    public function test_deduplication_can_be_disabled(): void
    {
        config()->set('haskimail.webhooks.deduplicate.enabled', false);
        Event::fake(self::EVENTS);

        $this->postJson('/haskimail/webhook', self::signed())->assertOk();
        $this->postJson('/haskimail/webhook', self::signed())->assertOk();

        Event::assertDispatched(MessageBounced::class, 2);
    }
}
