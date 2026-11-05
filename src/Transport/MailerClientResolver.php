<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Transport;

use Closure;
use Haskimail\Laravel\Contracts\Client;
use SensitiveParameter;

/**
 * Lazily builds and memoizes the client of a mailer with its own Haskimail options.
 *
 * An object rather than a closure so that dumping a mailer or its transport never
 * reveals the mailer configuration (and therefore the token).
 *
 * @internal
 */
final class MailerClientResolver
{
    private ?Client $client = null;

    /**
     * @param  Closure(array<string, mixed>): Client  $factory
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly Closure $factory,
        #[SensitiveParameter] private readonly array $config,
    ) {}

    public function __invoke(): Client
    {
        return $this->client ??= ($this->factory)($this->config);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['config' => '***'];
    }
}
