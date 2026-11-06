<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Tests\Feature;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Haskimail\Laravel\Enums\ErrorCode;
use Haskimail\Laravel\Exceptions\ApiException;
use Haskimail\Laravel\Exceptions\AuthenticationException;
use Haskimail\Laravel\Exceptions\ConfigurationException;
use Haskimail\Laravel\Exceptions\ConnectionException;
use Haskimail\Laravel\Exceptions\HaskimailException;
use Haskimail\Laravel\Exceptions\InvalidMessageException;
use Haskimail\Laravel\Exceptions\InvalidRequestException;
use Haskimail\Laravel\Exceptions\ServerException;
use Haskimail\Laravel\Exceptions\UnexpectedResponseException;
use Haskimail\Laravel\Facades\Haskimail;
use Haskimail\Laravel\HaskimailClient;
use Haskimail\Laravel\Tests\TestCase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

final class HaskimailClientTest extends TestCase
{
    private const MESSAGE = [
        'From' => 'Магазин <shop@example.com>',
        'To' => 'ivan@example.com',
        'Subject' => 'Ваш заказ',
        'TextBody' => 'Привет',
    ];

    private function client(int $retries = 2): HaskimailClient
    {
        return new HaskimailClient($this->app->make(Factory::class), self::TOKEN, retries: $retries);
    }

    public function test_it_sends_a_message_with_the_documented_headers_and_body(): void
    {
        Http::fake(['api.haskimail.ru/email' => Http::response(self::okResponse())]);

        $result = $this->client()->send(self::MESSAGE);

        self::assertTrue($result->successful());
        self::assertSame('6f1c0b2d9e8a4f7b3c5d2e1a0b9c8d7e', $result->messageId);
        self::assertSame('2026-10-07T11:30:12+00:00', $result->submittedAt?->utc()->toIso8601String());

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.haskimail.ru/email'
                && $request->header('X-Haskimail-Server-Token') === [self::TOKEN]
                && $request->header('Accept') === ['application/json']
                && $request->header('Content-Type') === ['application/json']
                && str_starts_with($request->header('User-Agent')[0] ?? '', 'haskimail-laravel/')
                // Unicode is sent as-is, not as \u escapes.
                && $request->body() === json_encode(self::MESSAGE, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        });
        Http::assertSentCount(1);
    }

    public function test_the_facade_and_container_alias_resolve_the_configured_client(): void
    {
        Http::fake(['*' => Http::response(self::okResponse())]);

        Haskimail::send(self::MESSAGE);

        self::assertSame($this->app->make('haskimail'), $this->app->make(HaskimailClient::class));
        Http::assertSent(fn (Request $r): bool => $r->header('X-Haskimail-Server-Token') === [self::TOKEN]);
    }

    public function test_api_errors_are_typed_exceptions_with_codes(): void
    {
        Http::fake(['*' => Http::response(['ErrorCode' => 6, 'Message' => 'Некорректный email-адрес получателя'], 422)]);

        try {
            $this->client()->send(self::MESSAGE);
            self::fail('Expected exception');
        } catch (InvalidRequestException $e) {
            self::assertInstanceOf(HaskimailException::class, $e);
            // Content errors must not trigger Laravel's failover to another provider.
            self::assertNotInstanceOf(TransportExceptionInterface::class, $e);
            self::assertFalse($e->mightHaveBeenSent());
            self::assertSame(422, $e->httpStatus());
            self::assertSame(6, $e->errorCode());
            self::assertTrue($e->is(ErrorCode::InvalidEmailAddress));
            self::assertSame('Некорректный email-адрес получателя', $e->apiMessage());
            self::assertFalse($e->isRetryable());
            self::assertStringContainsString('error 6', $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_non_zero_error_code_with_http_200_is_still_an_error(): void
    {
        Http::fake(['*' => Http::response(['ErrorCode' => 1226, 'Message' => "MessageStream doesn't match api key"])]);

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionCode(1226);

        $this->client()->send(self::MESSAGE);
    }

    /**
     * @return iterable<string, array{int, class-string<ApiException>}>
     */
    public static function statuses(): iterable
    {
        yield '401' => [401, AuthenticationException::class];
        yield '403 ip allow-list' => [403, AuthenticationException::class];
        yield '404' => [404, InvalidRequestException::class];
        yield '413' => [413, InvalidRequestException::class];
        yield '502' => [502, ServerException::class];
    }

    /**
     * @param  class-string<ApiException>  $expected
     */
    #[DataProvider('statuses')]
    public function test_http_statuses_map_to_exception_types(int $status, string $expected): void
    {
        Http::fake(['*' => Http::response('', $status)]);

        try {
            $this->client()->send(self::MESSAGE);
            self::fail('Expected exception');
        } catch (ApiException $e) {
            self::assertInstanceOf($expected, $e);
            self::assertSame($status, $e->httpStatus());
        }

        // None of these may be retried: they are permanent or ambiguous.
        Http::assertSentCount(1);
    }

    public function test_error_code_10_is_an_authentication_error(): void
    {
        Http::fake(['*' => Http::response(['ErrorCode' => 10, 'Message' => 'Неверный API-ключ'], 422)]);

        $this->expectException(AuthenticationException::class);
        $this->client()->send(self::MESSAGE);
    }

    /**
     * @return iterable<string, array{int, array<string, mixed>}>
     */
    public static function retryableResponses(): iterable
    {
        yield 'HTTP 503' => [503, []];
        yield 'HTTP 429' => [429, []];
        yield 'ErrorCode 36' => [422, ['ErrorCode' => 36, 'Message' => 'Временная ошибка']];
        yield 'ErrorCode 100' => [422, ['ErrorCode' => 100, 'Message' => 'Внутренняя ошибка']];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('retryableResponses')]
    public function test_documented_transient_errors_are_retried_with_backoff(int $status, array $body): void
    {
        Http::fakeSequence()
            ->push($body, $status)
            ->push(self::okResponse());

        $result = $this->client()->send(self::MESSAGE);

        self::assertTrue($result->successful());
        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    public function test_http_500_is_retryable_but_not_repeated_automatically(): void
    {
        Http::fakeSequence()->push('', 500)->push(self::okResponse());

        try {
            $this->client()->send(self::MESSAGE);
            self::fail('Expected exception');
        } catch (ServerException $e) {
            self::assertTrue($e->isRetryable());
            self::assertTrue($e->mightHaveBeenSent());
        }

        Http::assertSentCount(1);
    }

    public function test_redirects_are_never_followed(): void
    {
        Http::fake([
            'api.haskimail.ru/*' => Http::response('', 307, ['Location' => 'http://attacker.example/collect']),
            'attacker.example/*' => Http::response(self::okResponse()),
        ]);

        try {
            $this->client()->send(self::MESSAGE);
            self::fail('Expected exception');
        } catch (ApiException $e) {
            self::assertSame(307, $e->httpStatus());
        }

        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'attacker'));
    }

    public function test_raw_messages_are_validated_against_header_injection(): void
    {
        Http::fake();

        $invalid = [
            ['Subject' => "Hi\r\nBcc: victim@example.com"],
            ['Headers' => [['Name' => 'X-A', 'Value' => "a\r\nX-Injected: yes"]]],
            ['Headers' => [['Name' => 'Bad Name', 'Value' => 'x']]],
            ['Tag' => "a\nb"],
            ['Metadata' => ['k' => "a\nb"]],
            ['Attachments' => [['Name' => 'a.pdf', 'Content' => 'eA==', 'ContentType' => 'application/pdf; name="x.exe"']]],
            ['Subject' => str_repeat('я', 2001)],
            ['Tag' => str_repeat('t', 1001)],
        ];

        foreach ($invalid as $fields) {
            try {
                $this->client()->send($fields + self::MESSAGE);
                self::fail('Accepted: '.json_encode($fields));
            } catch (InvalidMessageException) {
            }
        }

        Http::assertNothingSent();
    }

    public function test_batch_with_templates_requires_a_template_per_item(): void
    {
        Http::fake();

        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('Batch item #0');
        $this->client()->sendBatchWithTemplates([self::MESSAGE]);
    }

    public function test_retries_are_bounded(): void
    {
        Http::fakeSequence()->push('', 503)->push('', 503)->push('', 503)->push(self::okResponse());

        try {
            $this->client(retries: 2)->send(self::MESSAGE);
            self::fail('Expected exception');
        } catch (ServerException $e) {
            self::assertSame(503, $e->httpStatus());
            self::assertTrue($e->isRetryable());
        }

        Http::assertSentCount(3);
    }

    public function test_retry_after_header_is_honoured(): void
    {
        Http::fakeSequence()->push('', 429, ['Retry-After' => '3'])->push(self::okResponse());

        $this->client()->send(self::MESSAGE);

        Sleep::assertSequence([Sleep::for(3)->seconds()]);
    }

    public function test_retries_can_be_disabled(): void
    {
        Http::fakeSequence()->push('', 503)->push(self::okResponse());

        $this->expectException(ServerException::class);
        $this->client(retries: 0)->send(self::MESSAGE);
    }

    public function test_connection_failures_before_the_request_was_sent_are_retried(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            if (++$attempts === 1) {
                throw new ConnectException('cURL error 7: Failed to connect', new PsrRequest('POST', 'https://api.haskimail.ru/email'), null, ['errno' => 7]);
            }

            return Http::response(self::okResponse());
        });

        self::assertTrue($this->client()->send(self::MESSAGE)->successful());
        self::assertSame(2, $attempts);
    }

    public function test_read_timeouts_are_not_retried_to_avoid_duplicate_emails(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts): never {
            $attempts++;

            throw new ConnectException('cURL error 28: Operation timed out after 30001 milliseconds', new PsrRequest('POST', 'https://api.haskimail.ru/email'), null, ['errno' => 28]);
        });

        try {
            $this->client()->send(self::MESSAGE);
            self::fail('Expected exception');
        } catch (ConnectionException $e) {
            self::assertInstanceOf(TransportExceptionInterface::class, $e);
            self::assertTrue($e->mightHaveBeenSent());
            self::assertStringContainsString('Operation timed out', $e->getMessage());
        }

        self::assertSame(1, $attempts);
    }

    public function test_unreadable_success_body_counts_as_accepted_to_avoid_duplicates(): void
    {
        Http::fake(['*' => Http::response('<html>proxy</html>', 200)]);

        $result = $this->client()->send(self::MESSAGE);

        self::assertTrue($result->successful());
        self::assertNull($result->messageId);
        self::assertSame('ivan@example.com', $result->to);
    }

    public function test_unreadable_batch_body_is_reported_as_possibly_sent(): void
    {
        Http::fake(['*' => Http::response('oops', 200)]);

        try {
            $this->client()->sendBatch([self::MESSAGE]);
            self::fail('Expected exception');
        } catch (UnexpectedResponseException $e) {
            self::assertTrue($e->mightHaveBeenSent());
            self::assertInstanceOf(TransportExceptionInterface::class, $e);
        }
    }

    public function test_batch_returns_per_message_results_in_order(): void
    {
        Http::fake(['api.haskimail.ru/email/batch' => Http::response([
            self::okResponse(),
            ['To' => 'anna.example.com', 'SubmittedAt' => '2026-10-13 14:30:12', 'MessageID' => null, 'ErrorCode' => 6, 'Message' => 'Некорректный email-адрес получателя'],
        ])]);

        $result = $this->client()->sendBatch([self::MESSAGE, ['To' => 'anna.example.com'] + self::MESSAGE]);

        self::assertCount(2, $result);
        self::assertTrue($result->hasRejections());
        self::assertSame([0], array_keys($result->accepted()));
        self::assertSame([1], array_keys($result->rejected()));
        self::assertNull($result->get(1)?->messageId);
        self::assertSame(6, $result->get(1)?->errorCode);

        Http::assertSent(fn (Request $r): bool => is_array($r->data()) && array_is_list($r->data()) && count($r->data()) === 2);
    }

    public function test_batch_with_templates_wraps_messages(): void
    {
        Http::fake(['api.haskimail.ru/email/batchWithTemplates' => Http::response([self::okResponse()])]);

        $this->client()->sendBatchWithTemplates([['TemplateId' => 4521, 'TemplateModel' => ['{{name}}' => 'Иван'], 'From' => 'a@example.com', 'To' => 'b@example.com']]);

        Http::assertSent(fn (Request $r): bool => $r->data()['Messages'][0]['TemplateId'] === 4521);
    }

    public function test_send_with_template_posts_to_the_template_endpoint(): void
    {
        Http::fake(['api.haskimail.ru/email/withTemplate' => Http::response(self::okResponse())]);

        $this->client()->sendWithTemplate(['TemplateAlias' => 'welcome', 'From' => 'a@example.com', 'To' => 'b@example.com']);

        Http::assertSentCount(1);
    }

    public function test_template_send_requires_a_template(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->client()->sendWithTemplate(self::MESSAGE);
    }

    public function test_template_id_must_be_an_integer(): void
    {
        Http::fake();

        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('"TemplateId" must be an integer');

        $this->client()->sendWithTemplate(['TemplateId' => '4521', 'From' => 'a@example.com', 'To' => 'b@example.com']);
    }

    public function test_an_overlong_from_is_rejected_after_encoding(): void
    {
        Http::fake();

        // 80 Cyrillic letters in the display name: each encodes to ~2.67 chars, so the
        // rendered header exceeds the documented 255-character limit.
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('field "From" is longer than 255 characters');

        $this->client()->send([
            'From' => str_repeat('И', 80).' <shop@example.com>',
            'To' => 'b@example.com',
            'TextBody' => 'Hi',
        ]);
    }

    public function test_a_short_from_is_accepted(): void
    {
        Http::fake(['*' => Http::response(self::okResponse())]);

        $this->client()->send(['From' => 'Магазин <shop@example.com>', 'To' => 'b@example.com', 'TextBody' => 'Hi']);

        Http::assertSentCount(1);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function invalidBatches(): iterable
    {
        yield 'empty' => [[]];
        yield 'too many' => [array_fill(0, 501, self::MESSAGE)];
        yield 'not a list' => [['a' => self::MESSAGE]];
        yield 'item is a list' => [[['x']]];
        yield 'item is scalar' => [['x']];
    }

    /**
     * @param  array<array-key, mixed>  $messages
     */
    #[DataProvider('invalidBatches')]
    public function test_invalid_batches_are_rejected_before_any_request(array $messages): void
    {
        Http::fake();

        try {
            $this->client()->sendBatch($messages);
            self::fail('Expected exception');
        } catch (InvalidMessageException) {
            Http::assertNothingSent();
        }
    }

    public function test_invalid_utf8_is_rejected_before_any_request(): void
    {
        Http::fake();

        $this->expectException(InvalidMessageException::class);
        $this->client()->send(['From' => 'a@example.com', 'To' => 'b@example.com', 'TextBody' => "\xB1\x31"]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'empty token' => ['  ', HaskimailClient::DEFAULT_ENDPOINT];
        yield 'token with newline' => ["abc\r\nX-Evil: 1", HaskimailClient::DEFAULT_ENDPOINT];
        yield 'plain http' => [self::TOKEN, 'http://api.haskimail.ru'];
        yield 'credentials in url' => [self::TOKEN, 'https://user:pass@api.haskimail.ru'];
        yield 'query string' => [self::TOKEN, 'https://api.haskimail.ru?x=1'];
        yield 'relative' => [self::TOKEN, 'api.haskimail.ru'];
    }

    #[DataProvider('invalidConfigurations')]
    public function test_insecure_or_invalid_configuration_is_rejected(string $token, string $endpoint): void
    {
        $this->expectException(ConfigurationException::class);

        new HaskimailClient($this->app->make(Factory::class), $token, $endpoint);
    }

    public function test_the_token_never_appears_in_dumps(): void
    {
        $dump = print_r($this->client(), true);

        self::assertStringNotContainsString(self::TOKEN, $dump);
        self::assertStringContainsString('***', $dump);
    }

    public function test_with_token_returns_a_new_client(): void
    {
        Http::fake(['*' => Http::response(self::okResponse())]);
        $client = $this->client();

        $client->withToken(HaskimailClient::TEST_TOKEN)->send(self::MESSAGE);

        self::assertFalse($client->isTestMode());
        Http::assertSent(fn (Request $r): bool => $r->header('X-Haskimail-Server-Token') === [HaskimailClient::TEST_TOKEN]);
    }

    public function test_custom_endpoint_is_normalized(): void
    {
        Http::fake(['https://eu.example.test/v1/email' => Http::response(self::okResponse())]);

        (new HaskimailClient($this->app->make(Factory::class), self::TOKEN, 'https://eu.example.test/v1/'))->send(self::MESSAGE);

        Http::assertSentCount(1);
    }

    public function test_connect_timeouts_are_retried_with_real_curl_messages(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            if (++$attempts <= 2) {
                throw new ConnectException($attempts === 1
                    ? 'cURL error 28: Connection timed out after 10001 milliseconds (see https://curl.haxx.se/libcurl/c/libcurl-errors.html)'
                    : 'cURL error 28: Failed to connect to api.haskimail.ru port 443 after 10002 ms: Timeout was reached', new PsrRequest('POST', 'https://api.haskimail.ru/email'), null, ['errno' => 28]);
            }

            return Http::response(self::okResponse());
        });

        $this->client()->send(self::MESSAGE);

        self::assertSame(3, $attempts);
    }

    public function test_batches_are_retried_on_documented_server_errors(): void
    {
        Http::fakeSequence()->push('', 503)->push([self::okResponse()]);

        self::assertTrue($this->client()->sendBatch([self::MESSAGE])->allAccepted());
        Http::assertSentCount(2);
    }

    public function test_total_retry_wait_is_bounded(): void
    {
        Http::fakeSequence()
            ->push('', 429, ['Retry-After' => '3600'])
            ->push('', 429, ['Retry-After' => '3600'])
            ->push('', 429, ['Retry-After' => '3600'])
            ->push(self::okResponse());

        try {
            (new HaskimailClient($this->app->make(Factory::class), self::TOKEN, retries: 5))->send(self::MESSAGE);
            self::fail('Expected exception');
        } catch (\Haskimail\Laravel\Exceptions\RateLimitException $e) {
            self::assertTrue($e->isRetryable());
        }

        // Retry-After is capped at 10s and the total wait at 20s.
        Sleep::assertSequence([Sleep::for(10_000)->milliseconds(), Sleep::for(10_000)->milliseconds()]);
        Http::assertSentCount(3);
    }

    public function test_batch_items_expose_retryability(): void
    {
        Http::fake(['*' => Http::response([['ErrorCode' => 36, 'Message' => 'later'], ['ErrorCode' => 6, 'Message' => 'bad']])]);

        $result = $this->client()->sendBatch([self::MESSAGE, self::MESSAGE]);

        self::assertTrue($result->get(0)?->isRetryable());
        self::assertFalse($result->get(1)?->isRetryable());
        self::assertSame([0], array_keys($result->retryable()));
    }

    public function test_validation_has_no_trailing_newline_loopholes_and_accepts_legitimate_values(): void
    {
        Http::fake(['*' => Http::response(self::okResponse())]);

        foreach ([
            ['Headers' => [['Name' => "X-A\n", 'Value' => 'x']]],
            ['Attachments' => [['Name' => 'a.txt', 'Content' => 'eA==', 'ContentType' => "text/plain\n"]]],
            ['TemplateModel' => ['{{n}}' => "Ivan\r\nBcc: evil@example.com"], 'TemplateAlias' => 'welcome'],
        ] as $fields) {
            try {
                $this->client()->send($fields + self::MESSAGE);
                self::fail('Accepted: '.json_encode($fields));
            } catch (InvalidMessageException) {
            }
        }

        $this->client()->send([
            'MessageStream' => 2081,
            'Tag' => 2026,
            'Subject' => "Привет\t👋",
            'Metadata' => new stdClass,
            'Headers' => [['Name' => 'X-Count', 'Value' => 1]],
            'Attachments' => [['Name' => 'a.txt', 'Content' => 'eA==', 'ContentType' => 'text/plain; charset=utf-8']],
        ] + self::MESSAGE);

        Http::assertSentCount(1);
    }

    public function test_redirect_responses_are_transport_failures(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://elsewhere.example'])]);

        $this->expectException(UnexpectedResponseException::class);
        $this->client()->send(self::MESSAGE);
    }

    public function test_endpoint_errors_never_echo_credentials(): void
    {
        foreach (['https://user:hun/ter2@api.example', 'https://u:p@ss@api.example', 'user:hunter2@host', 'http://api.example?token=hunter2'] as $endpoint) {
            try {
                new HaskimailClient($this->app->make(Factory::class), self::TOKEN, $endpoint);
                self::fail("Accepted {$endpoint}");
            } catch (ConfigurationException $e) {
                self::assertStringNotContainsString('hunter2', $e->getMessage());
                self::assertStringNotContainsString('ter2', $e->getMessage());
                self::assertStringNotContainsString('ss@', $e->getMessage());
            }
        }
    }
}
