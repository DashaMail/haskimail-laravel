<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Tests;

use Haskimail\Laravel\HaskimailServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected const TOKEN = 'server-token-123';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake();
    }

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [HaskimailServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('haskimail.token', self::TOKEN);
        $app['config']->set('mail.default', 'haskimail');
        $app['config']->set('mail.mailers.haskimail', ['transport' => 'haskimail']);
        $app['config']->set('mail.from', ['address' => 'shop@example.com', 'name' => 'Магазин']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected static function okResponse(array $overrides = []): array
    {
        return $overrides + [
            'To' => 'ivan@example.com',
            'SubmittedAt' => '2026-10-07 14:30:12',
            'MessageID' => '6f1c0b2d9e8a4f7b3c5d2e1a0b9c8d7e',
            'ErrorCode' => 0,
            'Message' => 'OK',
        ];
    }
}
