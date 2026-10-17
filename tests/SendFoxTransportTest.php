<?php

namespace Dyaskur\SendFox\Tests;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Mail\MailManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Dyaskur\SendFox\SendFoxServiceProvider;
use Dyaskur\SendFox\SendFoxTransport;

class SendFoxTransportTest extends TestCase
{
    public function testSendsPayload(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) {
            $this->assertSame('POST', $method);
            $this->assertSame('https://api.sendfox.com/transactional/emails', $url);
            $this->assertContains('Authorization: Bearer KEY', $options['headers']);

            $this->assertSame([
                'from' => 'Acme <hello@acme.com>',
                'to' => ['jane@example.com'],
                'subject' => 'Hi',
                'cc' => ['cc@example.com'],
                'bcc' => ['bcc@example.com'],
                'reply_to' => 'reply@acme.com',
                'html' => '<p>Hi</p>',
                'text' => 'Hi',
                'attachments' => [['filename' => 'a.txt', 'content' => base64_encode('abc'), 'content_type' => 'text/plain']],
                'tag' => 'receipt',
                'headers' => ['X-Entity-Ref-ID' => '42'],
            ], json_decode($options['body'], true));

            return new JsonMockResponse(['id' => 'tm_123', 'status' => 'queued'], ['http_code' => 202]);
        });

        $email = (new Email())
            ->from(new Address('hello@acme.com', 'Acme'))
            ->to(new Address('jane@example.com', 'Jane'))
            ->cc('cc@example.com')
            ->bcc('bcc@example.com')
            ->replyTo('reply@acme.com')
            ->subject('Hi')
            ->html('<p>Hi</p>')
            ->text('Hi')
            ->attach('abc', 'a.txt', 'text/plain');
        $email->getHeaders()->add(new TagHeader('receipt'))->addTextHeader('X-Entity-Ref-ID', '42');

        $sent = (new SendFoxTransport('KEY', $client))->send($email);

        $this->assertSame('tm_123', $sent->getMessageId());
    }

    public function testSendsIdempotencyKeyAsHttpHeader(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) {
            $this->assertContains('Idempotency-Key: receipt-1042', $options['headers']);
            $this->assertArrayNotHasKey('headers', json_decode($options['body'], true));

            return new JsonMockResponse(['id' => 'tm_1', 'status' => 'queued'], ['http_code' => 202]);
        });

        $email = (new Email())->from('a@acme.com')->to('b@example.com')->subject('x')->text('x');
        $email->getHeaders()->addTextHeader('Idempotency-Key', 'receipt-1042');

        (new SendFoxTransport('KEY', $client))->send($email);
    }

    public function testThrowsOnApiError(): void
    {
        $client = new MockHttpClient(new JsonMockResponse(
            ['status' => 403, 'name' => 'domain_not_verified', 'message' => 'Domain not verified.'],
            ['http_code' => 403],
        ));

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage('Unable to send an email: Domain not verified. (code 403).');

        (new SendFoxTransport('KEY', $client))->send((new Email())->from('a@acme.com')->to('b@example.com')->subject('x')->text('x'));
    }

    public function testLaravelRegistersTransport(): void
    {
        $app = new Container();
        $app->instance('config', new Repository(['services' => ['sendfox' => ['key' => 'KEY']]]));
        $app->singleton('mail.manager', fn ($app) => new MailManager($app));
        (new SendFoxServiceProvider($app))->boot();

        $transport = $app->make('mail.manager')->createSymfonyTransport(['transport' => 'sendfox']);

        $this->assertInstanceOf(SendFoxTransport::class, $transport);
    }
}
