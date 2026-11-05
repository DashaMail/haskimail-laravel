<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Mail;

use Haskimail\Laravel\Exceptions\InvalidMessageException;
use Haskimail\Laravel\Mail\Headers\HaskimailHeader;
use Haskimail\Laravel\Mail\Headers\TemplateHeader;
use Haskimail\Laravel\Transport\HaskimailTransport;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Mail\Transport\LogTransport;
use ReflectionProperty;
use Symfony\Component\Mailer\Transport\RoundRobinTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Header\HeaderInterface;
use Throwable;

/**
 * Stops template messages from leaving through a transport that cannot render them.
 *
 * Only Haskimail renders templates; any other delivering transport (including the
 * backup of a failover / round-robin mailer) would send a blank message without a
 * subject. The non-delivering array and log transports are allowed.
 *
 * @internal
 */
final class TemplateGuard
{
    public function __construct(private readonly MailFactory $mail) {}

    public function handle(MessageSending $event): void
    {
        $headers = $event->message->getHeaders();
        $usesTemplate = false;

        foreach ($headers->all() as $header) {
            if (! $header instanceof HeaderInterface) {
                continue;
            }

            if ($header instanceof TemplateHeader || strcasecmp($header->getName(), HaskimailHeader::TEMPLATE) === 0) {
                $usesTemplate = true;

                break;
            }
        }

        $mailerName = $event->data['mailer'] ?? null;

        if (! $usesTemplate || ! is_string($mailerName)) {
            return;
        }

        try {
            $mailer = $this->mail->mailer($mailerName);
        } catch (Throwable) {
            // e.g. Mail::build() ("ondemand"): the transport cannot be inspected, so refuse.
            throw new InvalidMessageException(sprintf(
                'Cannot verify that the "%s" mailer renders Haskimail templates; send template mail through a configured haskimail mailer.',
                $mailerName,
            ));
        }

        $transport = $mailer instanceof Mailer ? $mailer->getSymfonyTransport() : null;

        if ($transport === null || ! self::canRender($transport)) {
            throw new InvalidMessageException(sprintf(
                'The "%s" mailer cannot render Haskimail templates: every transport it may use must be the haskimail transport.',
                $mailerName,
            ));
        }
    }

    /**
     * Haskimail renders the template; array / log never deliver anything, so local
     * development keeps working.
     */
    private static function canRender(TransportInterface $transport): bool
    {
        if ($transport instanceof HaskimailTransport || $transport instanceof ArrayTransport || $transport instanceof LogTransport) {
            return true;
        }

        // FailoverTransport extends RoundRobinTransport.
        if ($transport instanceof RoundRobinTransport) {
            try {
                $inner = (new ReflectionProperty(RoundRobinTransport::class, 'transports'))->getValue($transport);
            } catch (Throwable) {
                return false;
            }

            if (! is_array($inner) || $inner === []) {
                return false;
            }

            foreach ($inner as $candidate) {
                if (! $candidate instanceof TransportInterface || ! self::canRender($candidate)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }
}
