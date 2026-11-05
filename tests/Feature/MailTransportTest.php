<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Tests\Feature;

use Haskimail\Laravel\Exceptions\ConfigurationException;
use Haskimail\Laravel\Exceptions\InvalidMessageException;
use Haskimail\Laravel\Exceptions\InvalidRequestException;
use Haskimail\Laravel\Facades\Haskimail;
use Haskimail\Laravel\Tests\Fixtures\InvoicePaid;
use Haskimail\Laravel\Tests\Fixtures\OrderShipped;
use Haskimail\Laravel\Tests\Fixtures\WelcomeTemplate;
use Haskimail\Laravel\Tests\TestCase;
use Haskimail\Laravel\Transport\HaskimailTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Message;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use stdClass;

final class MailTransportTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function sentPayload(): array
    {
        $recorded = Http::recorded();
        self::assertCount(1, $recorded);

        /** @var array<string, mixed> */
        return $recorded[0][0]->data();
    }

    public function test_the_mailer_uses_the_haskimail_transport(): void
    {
        $transport = Mail::mailer('haskimail')->getSymfonyTransport();

        self::assertInstanceOf(HaskimailTransport::class, $transport);
        self::assertSame('haskimail+api://api.haskimail.ru', (string) $transport);
    }

    public function test_a_mailable_is_sent_through_the_api(): void
    {
        Http::fake(['api.haskimail.ru/email' => Http::response(self::okResponse())]);

        Mail::to('ivan@example.com', 'Иван')->cc('boss@example.com')->bcc('audit@example.com')
            ->send((new OrderShipped)->haskimailStream('2081')->haskimailTrackLinks());

        $payload = self::sentPayload();

        self::assertSame('Магазин <shop@example.com>', $payload['From']);
        self::assertSame('Иван <ivan@example.com>', $payload['To']);
        self::assertSame('boss@example.com', $payload['Cc']);
        self::assertSame('audit@example.com', $payload['Bcc']);
        self::assertSame('support@example.com', $payload['ReplyTo']);
        self::assertSame('Заказ №1042 отправлен', $payload['Subject']);
        self::assertSame('<p>Заказ 1042</p>', $payload['HtmlBody']);
        self::assertSame('orders', $payload['Tag']);
        self::assertSame(['order_id' => '1042'], $payload['Metadata']);
        self::assertSame('2081', $payload['MessageStream']);
        self::assertTrue($payload['TrackLinks']);
        self::assertSame([['Name' => 'X-Order-Id', 'Value' => '1042']], $payload['Headers']);
        self::assertSame([['Name' => 'invoice.pdf', 'Content' => base64_encode('PDF'), 'ContentType' => 'application/pdf']], $payload['Attachments']);
    }

    public function test_the_haskimail_message_id_is_exposed_to_listeners(): void
    {
        Http::fake(['*' => Http::response(self::okResponse())]);
        $captured = null;
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$captured): void {
            $captured = $event->sent->getMessageId();
        });

        $sent = Mail::raw('Привет', fn (Message $m) => $m->to('ivan@example.com')->subject('Тест'));

        self::assertSame('6f1c0b2d9e8a4f7b3c5d2e1a0b9c8d7e', $sent?->getMessageId());
        self::assertSame('6f1c0b2d9e8a4f7b3c5d2e1a0b9c8d7e', $captured);
    }

    public function test_default_stream_and_per_mailer_token_from_mail_config(): void
    {
        config()->set('mail.mailers.marketing', ['transport' => 'haskimail', 'token' => 'other-token', 'message_stream' => 'broadcasts']);
        Http::fake(['*' => Http::response(self::okResponse())]);

        Mail::mailer('marketing')->raw('x', fn (Message $m) => $m->to('ivan@example.com'));

        Http::assertSent(fn (Request $r): bool => $r->header('X-Haskimail-Server-Token') === ['other-token']
            && $r->data()['MessageStream'] === 'broadcasts');
    }

    public function test_services_config_is_supported(): void
    {
        config()->set('haskimail.token', null);
        config()->set('services.haskimail.token', 'services-token');
        Http::fake(['*' => Http::response(self::okResponse())]);

        Mail::raw('x', fn (Message $m) => $m->to('ivan@example.com'));

        Http::assertSent(fn (Request $r): bool => $r->header('X-Haskimail-Server-Token') === ['services-token']);
    }

    public function test_a_missing_token_fails_with_a_helpful_message(): void
    {
        config()->set('haskimail.token', null);

        try {
            Mail::raw('x', fn (Message $m) => $m->to('ivan@example.com'));
            self::fail('Expected exception');
        } catch (\Symfony\Component\Mailer\Exception\TransportException $e) {
            self::assertInstanceOf(ConfigurationException::class, $e->getPrevious());
            self::assertStringContainsString('HASKIMAIL_TOKEN', $e->getMessage());
        }
    }

    public function test_api_rejections_surface_as_transport_exceptions(): void
    {
        Http::fake(['*' => Http::response(['ErrorCode' => 52, 'Message' => 'Адрес отправителя не подтвержден'], 422)]);

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionCode(52);

        Mail::raw('x', fn (Message $m) => $m->to('ivan@example.com'));
    }

    public function test_template_mailables_use_the_template_endpoint(): void
    {
        Http::fake(['api.haskimail.ru/email/withTemplate' => Http::response(self::okResponse())]);

        Mail::to('ivan@example.com')->send(new WelcomeTemplate('Иван'));

        $payload = self::sentPayload();
        self::assertSame('welcome', $payload['TemplateAlias']);
        self::assertSame(['{{name}}' => 'Иван'], $payload['TemplateModel']);
        self::assertArrayNotHasKey('HtmlBody', $payload);
        self::assertArrayNotHasKey('Subject', $payload);
    }

    public function test_template_mailables_can_override_the_subject(): void
    {
        Http::fake(['*' => Http::response(self::okResponse())]);

        Mail::to('ivan@example.com')->send((new WelcomeTemplate('Иван'))->subject('Добро пожаловать'));

        self::assertSame('Добро пожаловать', self::sentPayload()['Subject']);
    }

    public function test_notifications_can_use_haskimail_options(): void
    {
        Http::fake(['*' => Http::response(self::okResponse())]);

        (new AnonymousNotifiable)->route('mail', 'ivan@example.com')->notify(new InvoicePaid);

        $payload = self::sentPayload();
        self::assertSame('notifications', $payload['MessageStream']);
        self::assertTrue($payload['TrackOpens']);
        self::assertSame('Счёт оплачен', $payload['Subject']);
    }

    public function test_mailables_with_haskimail_options_survive_queue_serialization(): void
    {
        Http::fake(['*' => Http::response(self::okResponse())]);

        $mailable = (new OrderShipped('7'))->haskimailStream('2081')->haskimailTemplate(4521, ['{{order}}' => '7']);
        /** @var OrderShipped $restored */
        $restored = unserialize(serialize($mailable));

        Mail::to('ivan@example.com')->send($restored);

        $payload = self::sentPayload();
        self::assertSame(4521, $payload['TemplateId']);
        self::assertSame('2081', $payload['MessageStream']);
    }

    public function test_queued_mailables_are_sent_by_the_worker(): void
    {
        config()->set('queue.default', 'sync');
        Http::fake(['*' => Http::response(self::okResponse())]);

        Mail::to('ivan@example.com')->queue((new OrderShipped)->haskimailStream('2081'));

        self::assertSame('2081', self::sentPayload()['MessageStream']);
    }

    public function test_mail_fake_still_works_for_application_tests(): void
    {
        Mail::fake();
        Http::fake();

        Mail::to('ivan@example.com')->send(new WelcomeTemplate('Иван'));

        Mail::assertSent(WelcomeTemplate::class);
        Http::assertNothingSent();
    }

    public function test_invalid_haskimail_options_fail_when_configured_not_in_the_queue_worker(): void
    {
        $this->expectException(InvalidMessageException::class);

        (new OrderShipped)->haskimailTemplate('');
    }

    public function test_invalid_numeric_options_are_rejected(): void
    {
        config()->set('haskimail.retries', 99);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('retries');

        Haskimail::send(['From' => 'a@example.com']);
    }

    public function test_raw_mime_messages_are_refused(): void
    {
        $transport = Mail::mailer('haskimail')->getSymfonyTransport();

        $this->expectException(InvalidMessageException::class);

        $transport->send(new \Symfony\Component\Mime\RawMessage('Subject: x'), new \Symfony\Component\Mailer\Envelope(
            new \Symfony\Component\Mime\Address('a@example.com'),
            [new \Symfony\Component\Mime\Address('b@example.com')],
        ));
    }

    public function test_non_ascii_custom_headers_are_sent_end_to_end(): void
    {
        Http::fake(['*' => Http::response(self::okResponse())]);

        Mail::raw('x', fn (Message $m) => $m->to('ivan@example.com')->getSymfonyMessage()->getHeaders()->addTextHeader('X-Customer', 'Иван Петров'));

        self::assertSame([['Name' => 'X-Customer', 'Value' => 'Иван Петров']], self::sentPayload()['Headers']);
    }

    public function test_embedded_images_reference_their_content_id(): void
    {
        Http::fake(['*' => Http::response(self::okResponse())]);

        Mail::html('', function (Message $m): void {
            $cid = $m->embedData('PNG', 'logo.png', 'image/png');
            $m->to('ivan@example.com')->html('<img src="'.$cid.'">');
        });

        $payload = self::sentPayload();
        self::assertIsString($payload['HtmlBody']);
        self::assertIsArray($payload['Attachments']);
        $attachment = $payload['Attachments'][0];
        self::assertIsArray($attachment);
        self::assertIsString($attachment['ContentID']);
        self::assertStringContainsString('src="'.$attachment['ContentID'].'"', $payload['HtmlBody']);
    }

    public function test_content_errors_do_not_fail_over_to_another_provider(): void
    {
        config()->set('mail.mailers.failover', ['transport' => 'failover', 'mailers' => ['haskimail', 'array']]);
        Http::fake(['*' => Http::response(['ErrorCode' => 6, 'Message' => 'Некорректный email-адрес получателя'], 422)]);

        $sent = $this->countSentMessages();

        try {
            Mail::mailer('failover')->raw('x', fn (Message $m) => $m->to('ivan@example.com'));
            self::fail('Expected exception');
        } catch (InvalidRequestException) {
            self::assertSame(0, $sent->count);
        }
    }

    public function test_outages_fail_over_to_the_next_mailer(): void
    {
        config()->set('mail.mailers.failover', ['transport' => 'failover', 'mailers' => ['haskimail', 'array']]);
        config()->set('haskimail.retries', 0);
        Http::fake(['*' => Http::response('', 503)]);
        $sent = $this->countSentMessages();

        Mail::mailer('failover')->raw('x', fn (Message $m) => $m->to('ivan@example.com'));

        Http::assertSentCount(1);
        self::assertSame(1, $sent->count, 'The array mailer should have taken over.');
    }

    public function test_a_missing_token_does_not_break_failover_mailers_that_do_not_need_it(): void
    {
        config()->set('haskimail.token', null);
        config()->set('mail.mailers.failover', ['transport' => 'failover', 'mailers' => ['array', 'haskimail']]);
        $sent = $this->countSentMessages();

        Mail::mailer('failover')->raw('x', fn (Message $m) => $m->to('ivan@example.com'));

        self::assertSame(1, $sent->count);
    }

    public function test_haskimail_fake_captures_mailer_traffic(): void
    {
        Http::fake();
        $fake = Haskimail::fake();

        Mail::to('ivan@example.com')->send((new OrderShipped)->haskimailStream('2081'));
        Mail::to('anna@example.com')->send(new WelcomeTemplate('Анна'));

        $fake->assertSentCount(2);
        $fake->assertSentTo('ivan@example.com');
        $fake->assertSent(fn (array $m, string $endpoint): bool => $endpoint === '/email/withTemplate' && $m['TemplateAlias'] === 'welcome');
        $fake->assertNotSent(fn (array $m): bool => ($m['MessageStream'] ?? null) === 'other');
        Http::assertNothingSent();
    }

    public function test_haskimail_fake_can_simulate_failures(): void
    {
        Haskimail::fake()->failNextWith(\Haskimail\Laravel\Exceptions\ApiException::fromResponse(422, ['ErrorCode' => 300, 'Message' => 'bad']));

        $this->expectException(InvalidRequestException::class);
        Haskimail::send(['From' => 'a@example.com', 'To' => 'b@example.com', 'TextBody' => 'x']);
    }

    public function test_the_facade_can_be_mocked(): void
    {
        Haskimail::shouldReceive('send')->once()->andReturn(\Haskimail\Laravel\Data\SendResult::fromArray(self::okResponse()));

        self::assertSame('OK', Haskimail::send(['From' => 'a@example.com'])->message);
    }

    public function test_template_mails_cannot_leave_through_a_transport_that_cannot_render_them(): void
    {
        config()->set('mail.mailers.smtp', ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 2525]);
        config()->set('mail.mailers.mixed', ['transport' => 'failover', 'mailers' => ['haskimail', 'smtp']]);
        Http::fake(['*' => Http::response(self::okResponse())]);

        try {
            Mail::mailer('mixed')->to('ivan@example.com')->send(new WelcomeTemplate('Иван'));
            self::fail('Expected exception');
        } catch (InvalidMessageException $e) {
            self::assertStringContainsString('"mixed"', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_template_mails_may_fail_over_between_haskimail_servers_and_render_in_log_mailers(): void
    {
        config()->set('mail.mailers.backup', ['transport' => 'haskimail', 'token' => 'backup-token']);
        config()->set('mail.mailers.pair', ['transport' => 'failover', 'mailers' => ['haskimail', 'backup']]);
        config()->set('haskimail.retries', 0);
        Http::fake(function (Request $r) {
            return $r->header('X-Haskimail-Server-Token') === [self::TOKEN] ? Http::response('', 503) : Http::response(self::okResponse());
        });

        Mail::mailer('pair')->to('ivan@example.com')->send(new WelcomeTemplate('Иван'));
        Mail::mailer('log')->to('ivan@example.com')->send(new WelcomeTemplate('Иван'));

        Http::assertSent(fn (Request $r): bool => $r->header('X-Haskimail-Server-Token') === ['backup-token']);
    }

    public function test_fake_installed_after_the_mailer_was_used_still_captures_mail(): void
    {
        Http::fake(['*' => Http::response(self::okResponse())]);
        Mail::raw('first', fn (Message $m) => $m->to('ivan@example.com'));

        $fake = Haskimail::fake();
        Mail::raw('second', fn (Message $m) => $m->to('ivan@example.com'));

        Http::assertSentCount(1);
        $fake->assertSentCount(1);
    }

    public function test_a_mailer_for_another_server_does_not_inherit_the_global_stream(): void
    {
        config()->set('haskimail.message_stream', 'server-a-stream');
        config()->set('mail.mailers.other', ['transport' => 'haskimail', 'token' => 'server-b-token']);
        Http::fake(['*' => Http::response(self::okResponse())]);

        Mail::mailer('other')->raw('x', fn (Message $m) => $m->to('ivan@example.com'));
        Mail::raw('x', fn (Message $m) => $m->to('ivan@example.com'));

        $recorded = Http::recorded();
        self::assertArrayNotHasKey('MessageStream', $recorded[0][0]->data());
        self::assertSame('server-a-stream', $recorded[1][0]->data()['MessageStream']);
    }

    private function countSentMessages(): stdClass
    {
        $counter = new stdClass;
        $counter->count = 0;
        Event::listen(MessageSent::class, function () use ($counter): void {
            $counter->count++;
        });

        return $counter;
    }

    public function test_template_mail_through_on_demand_mailers_is_refused(): void
    {
        $this->expectException(InvalidMessageException::class);

        Mail::build(['transport' => 'array'])->to('ivan@example.com')->send(new WelcomeTemplate('Иван'));
    }

    public function test_template_mail_may_fail_over_to_a_log_mailer(): void
    {
        config()->set('mail.mailers.devchain', ['transport' => 'failover', 'mailers' => ['haskimail', 'log']]);
        Http::fake(['*' => Http::response(self::okResponse())]);

        Mail::mailer('devchain')->to('ivan@example.com')->send(new WelcomeTemplate('Иван'));

        Http::assertSentCount(1);
    }

    public function test_template_model_line_breaks_are_refused(): void
    {
        $this->expectException(InvalidMessageException::class);

        (new OrderShipped)->haskimailTemplate('welcome', ['{{name}}' => "Ivan\r\nBcc: evil@example.com"]);
    }

    public function test_an_empty_mailer_token_keeps_the_global_server_settings(): void
    {
        config()->set('haskimail.message_stream', '2081');
        config()->set('mail.mailers.haskimail', ['transport' => 'haskimail', 'token' => '']);
        Http::fake(['*' => Http::response(self::okResponse())]);

        Mail::raw('x', fn (Message $m) => $m->to('ivan@example.com'));

        Http::assertSent(fn (Request $r): bool => $r->data()['MessageStream'] === '2081'
            && $r->header('X-Haskimail-Server-Token') === [self::TOKEN]);
    }

    public function test_dumping_a_mailer_never_reveals_its_token(): void
    {
        config()->set('mail.mailers.secret', ['transport' => 'haskimail', 'token' => 'MAILER-SECRET-TOKEN', 'endpoint' => 'https://eu.example.test']);
        $transport = Mail::mailer('secret')->getSymfonyTransport();

        ob_start();
        var_dump($transport);
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString('MAILER-SECRET-TOKEN', $dump.print_r($transport, true));
        self::assertSame('haskimail+api://eu.example.test', (string) $transport);
    }

    public function test_the_fake_validates_like_the_real_client(): void
    {
        Haskimail::fake();

        $this->expectException(InvalidMessageException::class);
        Haskimail::send(['From' => 'a@example.com', 'To' => 'b@example.com', 'Subject' => "x\r\nBcc: y@example.com", 'TextBody' => 'x']);
    }
}
