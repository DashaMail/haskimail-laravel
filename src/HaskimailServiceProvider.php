<?php

declare(strict_types=1);

namespace Haskimail\Laravel;

use Haskimail\Laravel\Contracts\Client;
use Haskimail\Laravel\Exceptions\ConfigurationException;
use Haskimail\Laravel\Mail\TemplateGuard;
use Haskimail\Laravel\Transport\HaskimailTransport;
use Haskimail\Laravel\Transport\MailerClientResolver;
use Haskimail\Laravel\Webhooks\Http\Controllers\WebhookController;
use Haskimail\Laravel\Webhooks\Http\Middleware\VerifyWebhookRequest;
use Haskimail\Laravel\Webhooks\WebhookProcessor;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\MailManager;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use SensitiveParameter;

final class HaskimailServiceProvider extends ServiceProvider
{
    /** Mailer keys that make a mailer use its own client instead of the shared one. */
    private const CLIENT_OPTIONS = ['token', 'endpoint', 'timeout', 'connect_timeout', 'retries', 'retry_delay'];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/haskimail.php', 'haskimail');

        $this->app->singleton(HaskimailClient::class, static fn (Container $app): HaskimailClient => self::makeClient($app, []));
        // The contract (and the facade) can be swapped by Haskimail::fake() independently of the concrete class.
        $this->app->singleton(Client::class, static fn (Container $app): Client => $app->make(HaskimailClient::class));
        $this->app->alias(Client::class, 'haskimail');

        // Not a singleton: always uses the current event dispatcher and cache (Event::fake(), Octane).
        $this->app->bind(WebhookProcessor::class, static function (Container $app): WebhookProcessor {
            /** @var Config $config */
            $config = $app->make(Config::class);
            $enabled = (bool) $config->get('haskimail.webhooks.deduplicate.enabled', true);
            $store = $config->get('haskimail.webhooks.deduplicate.store');
            $ttl = $config->get('haskimail.webhooks.deduplicate.ttl', 7200);

            return new WebhookProcessor(
                $app->make(Dispatcher::class),
                $enabled ? $app->make(CacheFactory::class) : null,
                is_string($store) && $store !== '' ? $store : null,
                $enabled && is_numeric($ttl) ? max(0, (int) $ttl) : 0,
            );
        });
    }

    public function boot(): void
    {
        $this->callAfterResolving('mail.manager', static function (MailManager $manager): void {
            $manager->extend('haskimail', static function (#[SensitiveParameter] array $config) use ($manager): HaskimailTransport {
                /** @var array<string, mixed> $config */
                // Resolve through the manager so Octane's per-request application is used.
                $app = $manager->getApplication();
                $overrides = array_filter(
                    array_intersect_key($config, array_flip(self::CLIENT_OPTIONS)),
                    static fn (mixed $value): bool => $value !== null && $value !== '',
                );
                $ownServer = isset($overrides['token']);

                if ($overrides === []) {
                    // The shared client is resolved on every send, so Haskimail::fake() always applies.
                    $client = static fn (): Client => $app->make(Client::class);
                } else {
                    $client = (new MailerClientResolver(
                        static fn (array $mailer): Client => self::makeClient($app, $mailer, $ownServer),
                        $config,
                    ))(...);
                }

                return new HaskimailTransport(
                    $client,
                    self::option($app, $config, 'message_stream', $ownServer),
                    endpoint: self::option($app, $config, 'endpoint', $ownServer),
                );
            });
        });

        $this->app->make(Dispatcher::class)->listen(MessageSending::class, [TemplateGuard::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/haskimail.php' => $this->app->configPath('haskimail.php'),
            ], 'haskimail-config');
        }

        $this->registerWebhookRoute();
    }

    private function registerWebhookRoute(): void
    {
        /** @var Config $config */
        $config = $this->app->make(Config::class);

        if (! (bool) $config->get('haskimail.webhooks.enabled', false)) {
            return;
        }

        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        $path = $config->get('haskimail.webhooks.path', 'haskimail/webhook');
        $middleware = $config->get('haskimail.webhooks.middleware', []);

        if (! is_string($path) || trim($path, '/') === '') {
            throw ConfigurationException::invalidOption('webhooks.path', 'must be a non-empty string.');
        }

        /** @var Router $router */
        $router = $this->app->make('router');
        $router->post(trim($path, '/'), WebhookController::class)
            ->middleware([
                // User middleware (e.g. throttle) runs first so it also limits rejected requests.
                ...(is_array($middleware) ? array_values(array_filter($middleware, is_string(...))) : []),
                VerifyWebhookRequest::class,
            ])
            ->name('haskimail.webhook');
    }

    /**
     * @param  array<string, mixed>  $mailer
     */
    private static function makeClient(Container $app, #[SensitiveParameter] array $mailer, bool $ownServer = false): HaskimailClient
    {
        $token = self::option($app, $mailer, 'token', $ownServer);

        if ($token === null) {
            throw ConfigurationException::missingToken();
        }

        return new HaskimailClient(
            $app->make(HttpFactory::class),
            $token,
            self::option($app, $mailer, 'endpoint', $ownServer) ?? HaskimailClient::DEFAULT_ENDPOINT,
            self::number($app, $mailer, 'timeout', 30.0),
            self::number($app, $mailer, 'connect_timeout', 10.0),
            (int) self::number($app, $mailer, 'retries', 2, integer: true),
            (int) self::number($app, $mailer, 'retry_delay', 200, integer: true),
        );
    }

    /**
     * @param  array<string, mixed>  $mailer
     */
    private static function number(Container $app, #[SensitiveParameter] array $mailer, string $key, float $default, bool $integer = false): float
    {
        $value = self::option($app, $mailer, $key);

        if ($value === null) {
            return $default;
        }

        $valid = $integer ? preg_match('/^\d+$/', $value) === 1 : is_numeric($value);

        if (! $valid) {
            throw ConfigurationException::invalidOption($key, sprintf('"%s" is not a valid %s.', $value, $integer ? 'integer' : 'number'));
        }

        return (float) $value;
    }

    /**
     * Precedence: the mailer entry in config/mail.php, then services.haskimail, then
     * config/haskimail.php. The facade (no mailer config) also falls back to
     * mail.mailers.haskimail.
     *
     * A mailer with its own token talks to another Haskimail server, so its server-specific
     * options (token, endpoint, message_stream) are never inherited from the global config.
     *
     * @param  array<string, mixed>  $mailer
     */
    private static function option(Container $app, #[SensitiveParameter] array $mailer, string $key, bool $ownServer = false): ?string
    {
        /** @var Config $config */
        $config = $app->make(Config::class);

        $candidates = [$mailer[$key] ?? null];

        if (! $ownServer || ! in_array($key, ['token', 'endpoint', 'message_stream'], true)) {
            $candidates[] = $config->get('services.haskimail.'.$key);
            $candidates[] = $config->get('haskimail.'.$key);

            if ($mailer === []) {
                $candidates[] = $config->get('mail.mailers.haskimail.'.$key);
            }
        }

        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }

            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return null;
    }
}
