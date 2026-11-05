<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Transport;

use Closure;
use Haskimail\Laravel\Contracts\Client;
use Haskimail\Laravel\Exceptions\ConfigurationException;
use Haskimail\Laravel\Exceptions\InvalidMessageException;
use Haskimail\Laravel\HaskimailClient;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;

/**
 * Laravel / Symfony mail transport backed by the Haskimail HTTP API.
 *
 * The Haskimail MessageID is available as $sentMessage->getMessageId(), e.g. in
 * Illuminate\Mail\Events\MessageSent listeners via $event->sent->getMessageId().
 */
final class HaskimailTransport extends AbstractTransport
{
    private readonly PayloadFactory $payloads;

    /**
     * @param  Client|Closure(): Client  $client  A closure defers configuration errors (e.g. a
     *                                            missing token) until the transport is used, so a
     *                                            failover mailer can still send through its other mailers.
     */
    public function __construct(
        private readonly Client|Closure $client,
        ?string $messageStream = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
        private readonly ?string $endpoint = null,
    ) {
        parent::__construct($dispatcher, $logger);

        $this->payloads = new PayloadFactory($messageStream !== null && trim($messageStream) !== '' ? trim($messageStream) : null);
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();

        if (! $original instanceof Message) {
            throw new InvalidMessageException('The Haskimail API transport cannot send raw MIME messages; use a Symfony Email.');
        }

        $payload = $this->payloads->make(MessageConverter::toEmail($original), $message->getEnvelope());
        $client = $this->client();

        $result = $payload->usesTemplate
            ? $client->sendWithTemplate($payload->fields)
            : $client->send($payload->fields);

        if ($result->messageId !== null) {
            $message->setMessageId($result->messageId);
        }

        $message->appendDebug(sprintf(
            "Haskimail accepted the message for %s (MessageID: %s).\n",
            $result->to,
            $result->messageId ?? 'n/a',
        ));
    }

    public function client(): Client
    {
        if (! $this->client instanceof Closure) {
            return $this->client;
        }

        try {
            return ($this->client)();
        } catch (ConfigurationException $e) {
            // Surface as a transport failure so failover / round-robin mailers move on.
            throw new TransportException($e->getMessage(), 0, $e);
        }
    }

    public function __toString(): string
    {
        $endpoint = $this->client instanceof HaskimailClient ? $this->client->endpoint() : ($this->endpoint ?? HaskimailClient::DEFAULT_ENDPOINT);
        $host = parse_url($endpoint, PHP_URL_HOST);

        return 'haskimail+api://'.(is_string($host) ? $host : 'api.haskimail.ru');
    }
}
