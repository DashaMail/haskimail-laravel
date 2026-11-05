<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when the package is configured incorrectly (missing token, insecure endpoint, ...).
 */
final class ConfigurationException extends InvalidArgumentException implements HaskimailException
{
    public static function missingToken(): self
    {
        return new self(
            'Haskimail server token is not configured. Set HASKIMAIL_TOKEN in your .env file '
            .'or the "token" key of the haskimail mailer in config/mail.php.'
        );
    }

    public static function insecureEndpoint(string $endpoint): self
    {
        // Never echo the URL itself: it may embed credentials or tokens.
        $parts = parse_url($endpoint);
        $shown = is_array($parts) && isset($parts['host'])
            ? sprintf('%s://%s', $parts['scheme'] ?? '?', $parts['host'])
            : 'an unparsable URL';

        return new self(sprintf(
            'Haskimail endpoint must be an absolute https:// URL without credentials, query or fragment (%s given).',
            $shown,
        ));
    }

    public static function invalidOption(string $option, string $reason): self
    {
        return new self(sprintf('Invalid Haskimail option "%s": %s', $option, $reason));
    }
}
