<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Tests\Feature;

use Haskimail\Laravel\Tests\TestCase;
use Illuminate\Foundation\Application;

final class WebhookThrottleTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('haskimail.webhooks.enabled', true);
        $app['config']->set('haskimail.webhooks.signing_key', 'key');
        $app['config']->set('haskimail.webhooks.middleware', ['throttle:2,1']);
    }

    public function test_user_middleware_runs_before_verification(): void
    {
        $this->postJson('/haskimail/webhook', ['recipient' => 'a@example.com'])->assertForbidden();
        $this->postJson('/haskimail/webhook', ['recipient' => 'a@example.com'])->assertForbidden();
        $this->postJson('/haskimail/webhook', ['recipient' => 'a@example.com'])->assertTooManyRequests();
    }
}
