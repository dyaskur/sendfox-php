<?php

namespace Dyaskur\SendFox;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @see https://sendfox.com/developer/docs/#tag/Transactional
 */
class SendFoxTransport extends AbstractApiTransport
{
    private const HOST = 'api.sendfox.com';

    // Set by SendFox itself; sending them is a 422.
    private const RESERVED_HEADERS = [
        'from', 'to', 'cc', 'bcc', 'reply-to', 'sender', 'subject', 'content-type', 'content-transfer-encoding',
        'mime-version', 'date', 'message-id', 'return-path', 'dkim-signature', 'received',
        'idempotency-key', // sent as an HTTP header instead
    ];

    public function __construct(
        #[\SensitiveParameter] private string $key,
        ?HttpClientInterface $client = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct(SendFox::retrying($client), $dispatcher, $logger);
    }

    public function __toString(): string
    {
        return \sprintf('sendfox+api://%s', $this->getEndpoint());
    }

    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        $response = $this->client->request('POST', 'https://'.$this->getEndpoint().'/transactional/emails', [
            'auth_bearer' => $this->key,
            'headers' => array_filter(['Idempotency-Key' => $email->getHeaders()->get('Idempotency-Key')?->getBodyAsString()]),
            'json' => $this->getPayload($email, $envelope),
        ]);

        try {
            $statusCode = $response->getStatusCode();
            $result = $response->toArray(false);
        } catch (DecodingExceptionInterface) {
            throw new HttpTransportException('Unable to send an email: '.$response->getContent(false).\sprintf(' (code %d).', $statusCode), $response);
        } catch (TransportExceptionInterface $e) {
            throw new HttpTransportException('Could not reach the remote SendFox server.', $response, 0, $e);
        }

        if (202 !== $statusCode) {
            $message = \sprintf('Unable to send an email: %s (code %d).', $result['message'] ?? $response->getContent(false), $statusCode);
            if (isset($result['errors'])) {
                $message .= ' '.json_encode($result['errors']);
            }

            throw new HttpTransportException($message, $response);
        }

        $sentMessage->setMessageId($result['id']);

        return $response;
    }

    private function getPayload(Email $email, Envelope $envelope): array
    {
        $payload = [
            'from' => $this->formatAddress($email->getFrom()[0] ?? $envelope->getSender()),
            'to' => $this->addresses($this->getRecipients($email, $envelope)),
            'subject' => $email->getSubject(),
        ];

        if ($cc = $email->getCc()) {
            $payload['cc'] = $this->addresses($cc);
        }
        if ($bcc = $email->getBcc()) {
            $payload['bcc'] = $this->addresses($bcc);
        }
        // ponytail: SendFox takes a single reply_to, extra Reply-To addresses are dropped.
        if ($replyTo = $email->getReplyTo()) {
            $payload['reply_to'] = $replyTo[0]->getAddress();
        }
        if (null !== $html = $email->getHtmlBody()) {
            $payload['html'] = $html;
        }
        if (null !== $text = $email->getTextBody()) {
            $payload['text'] = $text;
        }

        foreach ($email->getAttachments() as $attachment) {
            $payload['attachments'][] = [
                'filename' => $attachment->getFilename() ?? 'attachment',
                'content' => base64_encode($attachment->getBody()),
                'content_type' => $attachment->getContentType(),
            ];
        }

        foreach ($email->getHeaders()->all() as $name => $header) {
            if (\in_array($name, self::RESERVED_HEADERS, true)) {
                continue;
            }
            // ponytail: SendFox has one tag per message, the first TagHeader wins.
            if ($header instanceof TagHeader) {
                $payload['tag'] ??= $header->getValue();
                continue;
            }

            $payload['headers'][$header->getName()] = $header->getBodyAsString();
        }

        return $payload;
    }

    /**
     * @param Address[] $addresses
     */
    private function addresses(array $addresses): array
    {
        return array_values(array_map(static fn (Address $a) => $a->getAddress(), $addresses));
    }

    private function formatAddress(Address $address): string
    {
        return '' === $address->getName() ? $address->getAddress() : \sprintf('%s <%s>', $address->getName(), $address->getAddress());
    }

    private function getEndpoint(): string
    {
        return ($this->host ?: self::HOST).($this->port ? ':'.$this->port : '');
    }
}
