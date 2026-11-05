<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Facades;

use Haskimail\Laravel\Contracts\Client;
use Haskimail\Laravel\Testing\HaskimailFake;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Haskimail\Laravel\Data\SendResult send(array<string, mixed> $message)
 * @method static \Haskimail\Laravel\Data\SendResult sendWithTemplate(array<string, mixed> $message)
 * @method static \Haskimail\Laravel\Data\BatchResult sendBatch(list<array<string, mixed>> $messages)
 * @method static \Haskimail\Laravel\Data\BatchResult sendBatchWithTemplates(list<array<string, mixed>> $messages)
 * @method static \Haskimail\Laravel\Contracts\Client withToken(string $token)
 *
 * @see \Haskimail\Laravel\HaskimailClient
 */
final class Haskimail extends Facade
{
    /**
     * Replace the client with an in-memory fake that records messages.
     */
    public static function fake(): HaskimailFake
    {
        $fake = new HaskimailFake;
        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }

    protected static function getMockableClass(): string
    {
        return Client::class;
    }
}
