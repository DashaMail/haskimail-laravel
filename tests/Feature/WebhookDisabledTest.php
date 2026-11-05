<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Tests\Feature;

use Haskimail\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Route;

final class WebhookDisabledTest extends TestCase
{
    public function test_no_route_is_registered_by_default(): void
    {
        self::assertFalse(Route::has('haskimail.webhook'));
        $this->postJson('/haskimail/webhook', ['event' => 'delivered'])->assertNotFound();
    }
}
