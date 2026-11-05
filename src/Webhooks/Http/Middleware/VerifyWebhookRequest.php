<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Webhooks\Http\Middleware;

use Closure;
use Haskimail\Laravel\Webhooks\Http\Controllers\WebhookController;
use Haskimail\Laravel\Webhooks\WebhookSignature;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates Haskimail webhook requests with HTTP Basic credentials and/or the
 * per-event signature.
 *
 * - Bad credentials or signatures get 401/403: Haskimail does not retry 4xx.
 * - Missing configuration gets 503, so Haskimail keeps redelivering (about an hour)
 *   while the application is being fixed instead of losing events.
 */
final class VerifyWebhookRequest
{
    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $signingKey = self::stringConfig($this->config->get('haskimail.webhooks.signing_key'));
        $username = self::stringConfig($this->config->get('haskimail.webhooks.username'));
        $password = self::stringConfig($this->config->get('haskimail.webhooks.password'));
        $useBasicAuth = $username !== '' || $password !== '';

        if (($signingKey === '' && ! $useBasicAuth) || ($useBasicAuth && ($username === '' || $password === ''))) {
            $this->logger->error('Haskimail webhook refused: set haskimail.webhooks.signing_key and/or both webhooks.username and webhooks.password.');

            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ($useBasicAuth && ! self::hasValidCredentials($request, $username, $password)) {
            return new Response('', Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Basic realm="Haskimail webhooks"']);
        }

        if ($signingKey !== '' && ! $this->hasValidSignatures($request, $signingKey)) {
            $this->logger->warning('Haskimail webhook refused: invalid signature.', ['ip' => $request->ip()]);

            return new Response('', Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }

    /**
     * Every event must carry a valid signature; malformed bodies are refused here
     * too, so unauthenticated callers cannot probe the controller.
     */
    private function hasValidSignatures(Request $request, string $signingKey): bool
    {
        $payload = json_decode($request->getContent(), true, 64);

        if (! is_array($payload) || $payload === []) {
            return false;
        }

        $events = array_is_list($payload) ? $payload : [$payload];

        if (count($events) > WebhookController::MAX_EVENTS) {
            return false;
        }

        foreach ($events as $event) {
            if (! is_array($event) || ! WebhookSignature::isValid($event, $signingKey)) {
                return false;
            }
        }

        return true;
    }

    private static function hasValidCredentials(Request $request, string $username, string $password): bool
    {
        // Evaluate both comparisons so timing does not reveal which one failed.
        $userOk = hash_equals($username, (string) $request->getUser());
        $passwordOk = hash_equals($password, (string) $request->getPassword());

        return $userOk && $passwordOk;
    }

    private static function stringConfig(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
