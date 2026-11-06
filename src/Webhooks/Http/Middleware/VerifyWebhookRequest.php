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
    /** Signatures all valid. */
    private const VALID = 'valid';

    /** Bad signature or malformed body: refuse permanently (403). */
    private const INVALID = 'invalid';

    /** Valid signature but unrecognized payload shape: refuse retryably (503). */
    private const UNSUPPORTED = 'unsupported';

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

        if ($signingKey !== '') {
            $signature = $this->checkSignatures($request, $signingKey);

            if ($signature === self::INVALID) {
                $this->logger->warning('Haskimail webhook refused: invalid signature.', ['ip' => $request->ip()]);

                return new Response('', Response::HTTP_FORBIDDEN);
            }

            if ($signature === self::UNSUPPORTED) {
                // Haskimail never retries 4xx, and a 403 here would lose real events forever
                // (e.g. a new message_id format). Ask it to redeliver until the SDK catches up.
                $this->logger->warning('Haskimail webhook refused: unrecognized event shape, asking Haskimail to redeliver.', ['ip' => $request->ip()]);

                return new Response('', Response::HTTP_SERVICE_UNAVAILABLE);
            }
        }

        return $next($request);
    }

    /**
     * Every event must carry a valid signature; malformed bodies are refused here
     * too, so unauthenticated callers cannot probe the controller.
     *
     * @return self::* one of {@see self::VALID}, {@see self::INVALID}, {@see self::UNSUPPORTED}
     */
    private function checkSignatures(Request $request, string $signingKey): string
    {
        $payload = json_decode($request->getContent(), true, 64);

        if (! is_array($payload) || $payload === []) {
            return self::INVALID;
        }

        $events = array_is_list($payload) ? $payload : [$payload];

        if (count($events) > WebhookController::MAX_EVENTS) {
            return self::INVALID;
        }

        foreach ($events as $event) {
            if (! is_array($event)) {
                return self::INVALID;
            }

            $result = WebhookSignature::verify($event, $signingKey);

            if ($result === WebhookSignature::INVALID_SIGNATURE) {
                return self::INVALID;
            }

            if ($result === WebhookSignature::UNSUPPORTED_FORMAT) {
                return self::UNSUPPORTED;
            }
        }

        return self::VALID;
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
