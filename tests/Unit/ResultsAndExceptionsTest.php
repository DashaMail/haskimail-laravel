<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Tests\Unit;

use Haskimail\Laravel\Data\BatchResult;
use Haskimail\Laravel\Data\SendResult;
use Haskimail\Laravel\Exceptions\ApiException;
use Haskimail\Laravel\Exceptions\AuthenticationException;
use Haskimail\Laravel\Exceptions\InvalidRequestException;
use Haskimail\Laravel\Exceptions\ServerException;
use Haskimail\Laravel\Exceptions\UnexpectedResponseException;
use PHPUnit\Framework\TestCase;

final class ResultsAndExceptionsTest extends TestCase
{
    public function test_send_result_splits_multi_recipient_ids(): void
    {
        $result = SendResult::fromArray(['To' => 'a@x.ru, b@x.ru', 'MessageID' => 'id-1, id-2', 'ErrorCode' => 0, 'Message' => 'OK', 'SubmittedAt' => '2026-10-07 14:30:12']);

        self::assertSame(['id-1', 'id-2'], $result->messageIds());
        self::assertSame('2026-10-07 14:30:12', $result->toArray()['SubmittedAt']);
        self::assertSame('Europe/Moscow', $result->submittedAt?->getTimezone()->getName());
        self::assertSame(json_encode($result->toArray()), json_encode($result));
    }

    public function test_send_result_tolerates_missing_fields(): void
    {
        $result = SendResult::fromArray([]);

        self::assertTrue($result->failed());
        self::assertSame([], $result->messageIds());
        self::assertNull($result->submittedAt);
    }

    public function test_batch_result_is_iterable_and_countable(): void
    {
        $batch = BatchResult::fromArray([['ErrorCode' => 0, 'MessageID' => 'a'], ['ErrorCode' => 6], 'garbage']);

        self::assertCount(3, $batch);
        self::assertSame(['a', null, null], array_map(fn (SendResult $r): ?string => $r->messageId, iterator_to_array($batch)));
        self::assertFalse($batch->allAccepted());
        self::assertSame([1, 2], array_keys($batch->rejected()));
        self::assertCount(3, $batch->toArray());
    }

    public function test_exception_factory(): void
    {
        self::assertInstanceOf(AuthenticationException::class, ApiException::fromResponse(401, null));
        self::assertInstanceOf(ServerException::class, ApiException::fromResponse(500, null));
        self::assertInstanceOf(InvalidRequestException::class, ApiException::fromResponse(422, ['ErrorCode' => 300, 'Message' => 'bad batch']));
        self::assertInstanceOf(UnexpectedResponseException::class, ApiException::fromResponse(200, null));

        foreach ([4, 35, 36, 100] as $code) {
            $unavailable = ApiException::fromResponse(422, ['ErrorCode' => $code]);
            self::assertInstanceOf(\Haskimail\Laravel\Exceptions\SendingUnavailableException::class, $unavailable);
            self::assertInstanceOf(\Symfony\Component\Mailer\Exception\TransportExceptionInterface::class, $unavailable);
        }

        $e = ApiException::fromResponse(422, ['ErrorCode' => '412', 'Message' => ['not', 'a', 'string']]);
        self::assertSame(412, $e->errorCode());
        self::assertNull($e->apiMessage());
        self::assertSame('Haskimail API error 412: Unexpected response (HTTP 422).', $e->getMessage());
    }
}
