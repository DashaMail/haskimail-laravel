<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Testing;

use Haskimail\Laravel\Contracts\Client;
use Haskimail\Laravel\Data\BatchResult;
use Haskimail\Laravel\Data\SendResult;
use Haskimail\Laravel\Support\MessageValidator;
use PHPUnit\Framework\Assert as PHPUnit;
use SensitiveParameter;
use Throwable;

/**
 * In-memory Client for application tests, installed with Haskimail::fake().
 * Messages are validated exactly like the real client does.
 *
 * It also captures mail sent through the "haskimail" mailer unless that mailer
 * overrides the token or endpoint in config/mail.php.
 */
final class HaskimailFake implements Client
{
    /** @var list<array{endpoint: string, message: array<string, mixed>}> */
    private array $sent = [];

    private ?Throwable $nextFailure = null;

    public function send(array $message): SendResult
    {
        MessageValidator::validate($message);

        return $this->record('/email', $message);
    }

    public function sendWithTemplate(array $message): SendResult
    {
        MessageValidator::validate($message, requireTemplate: true);

        return $this->record('/email/withTemplate', $message);
    }

    public function sendBatch(array $messages): BatchResult
    {
        array_map(static fn (array $m) => MessageValidator::validate($m), $messages);

        return new BatchResult(array_map(fn (array $m): SendResult => $this->record('/email/batch', $m), $messages));
    }

    public function sendBatchWithTemplates(array $messages): BatchResult
    {
        array_map(static fn (array $m) => MessageValidator::validate($m, requireTemplate: true), $messages);

        return new BatchResult(array_map(fn (array $m): SendResult => $this->record('/email/batchWithTemplates', $m), $messages));
    }

    /**
     * The fake records every token's messages in the same place.
     */
    public function withToken(#[SensitiveParameter] string $token): static
    {
        return $this;
    }

    /**
     * Make the next send throw, e.g. ApiException::fromResponse(422, ['ErrorCode' => 406, ...]).
     */
    public function failNextWith(Throwable $exception): self
    {
        $this->nextFailure = $exception;

        return $this;
    }

    /**
     * Messages sent so far, optionally filtered.
     *
     * @param  (callable(array<string, mixed>, string): bool)|null  $filter  Receives the message and the endpoint.
     * @return list<array<string, mixed>>
     */
    public function sent(?callable $filter = null): array
    {
        $messages = [];

        foreach ($this->sent as $entry) {
            if ($filter === null || $filter($entry['message'], $entry['endpoint'])) {
                $messages[] = $entry['message'];
            }
        }

        return $messages;
    }

    /**
     * @param  (callable(array<string, mixed>, string): bool)|null  $filter
     */
    public function assertSent(?callable $filter = null): void
    {
        PHPUnit::assertNotEmpty($this->sent($filter), 'The expected Haskimail message was not sent.');
    }

    /**
     * @param  (callable(array<string, mixed>, string): bool)  $filter
     */
    public function assertNotSent(callable $filter): void
    {
        PHPUnit::assertEmpty($this->sent($filter), 'An unexpected Haskimail message was sent.');
    }

    /**
     * Assert a message was sent to the exact address (To, Cc or Bcc, case-insensitive).
     */
    public function assertSentTo(string $address): void
    {
        $this->assertSent(static function (array $message) use ($address): bool {
            foreach (['To', 'Cc', 'Bcc'] as $field) {
                if (is_string($message[$field] ?? null) && in_array(strtolower($address), self::addresses($message[$field]), true)) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * @return list<string> Lower-cased bare addresses of a "Name <a@b>, c@d" list.
     */
    private static function addresses(string $list): array
    {
        // Drop quoted display names first: they may contain commas or "@".
        $list = (string) preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '', $list);
        preg_match_all('/<([^<>]+)>|([^\s,<>]+@[^\s,<>]+)/', $list, $matches, PREG_SET_ORDER);

        return array_map(static fn (array $m): string => strtolower(($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '')), $matches);
    }

    public function assertSentCount(int $count): void
    {
        PHPUnit::assertCount($count, $this->sent, sprintf('Expected %d Haskimail messages, %d sent.', $count, count($this->sent)));
    }

    public function assertNothingSent(): void
    {
        $this->assertSentCount(0);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function record(string $endpoint, array $message): SendResult
    {
        if ($this->nextFailure !== null) {
            $failure = $this->nextFailure;
            $this->nextFailure = null;

            throw $failure;
        }

        $this->sent[] = ['endpoint' => $endpoint, 'message' => $message];

        return new SendResult(
            to: is_string($message['To'] ?? null) ? $message['To'] : '',
            messageId: bin2hex(random_bytes(16)),
            submittedAt: null,
            errorCode: 0,
            message: 'OK',
        );
    }
}
