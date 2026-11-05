<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Tests\Fixtures;

use Haskimail\Laravel\Mail\TemplateMailable;

final class WelcomeTemplate extends TemplateMailable
{
    public function __construct(public string $name) {}

    public function template(): int|string
    {
        return 'welcome';
    }

    public function templateModel(): array
    {
        return ['{{name}}' => $this->name];
    }
}
