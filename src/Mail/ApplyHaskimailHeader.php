<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Mail;

use Haskimail\Laravel\Mail\Headers\MessageStreamHeader;
use Haskimail\Laravel\Mail\Headers\TemplateHeader;
use Haskimail\Laravel\Mail\Headers\TrackLinksHeader;
use Haskimail\Laravel\Mail\Headers\TrackOpensHeader;
use Symfony\Component\Mime\Email;

/**
 * Invokable withSymfonyMessage() callback.
 *
 * A plain, serializable object (not a Closure) so that queued Mailables and
 * queued notifications using InteractsWithHaskimail can be serialized.
 *
 * @internal
 */
final class ApplyHaskimailHeader
{
    public const STREAM = 'stream';

    public const TEMPLATE = 'template';

    public const TRACK_OPENS = 'track_opens';

    public const TRACK_LINKS = 'track_links';

    /**
     * @param  self::*  $option
     * @param  array<string, scalar|null>  $model
     */
    public function __construct(
        public readonly string $option,
        public readonly int|string|bool $value,
        public readonly array $model = [],
    ) {
        // Fail fast at configuration time rather than when the queue worker sends.
        $this->header();
    }

    public function __invoke(Email $message): void
    {
        $header = $this->header();
        $headers = $message->getHeaders();
        $headers->remove($header->getName());
        $headers->add($header);
    }

    private function header(): MessageStreamHeader|TemplateHeader|TrackLinksHeader|TrackOpensHeader
    {
        return match ($this->option) {
            self::STREAM => new MessageStreamHeader((string) $this->value),
            self::TEMPLATE => new TemplateHeader(is_bool($this->value) ? '' : $this->value, $this->model),
            self::TRACK_OPENS => new TrackOpensHeader((bool) $this->value),
            self::TRACK_LINKS => new TrackLinksHeader((bool) $this->value),
        };
    }
}
