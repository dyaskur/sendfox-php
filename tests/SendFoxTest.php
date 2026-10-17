<?php

namespace Dyaskur\SendFox\Tests;

use Dyaskur\SendFox\SendFox;
use Dyaskur\SendFox\SendFoxException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

class SendFoxTest extends TestCase
{
    public function testBuildsPathAndQuery(): void
    {
        $client = new MockHttpClient(function (string $method, string $url) {
            $this->assertSame('GET', $method);
            $this->assertSame('https://api.sendfox.com/contacts?filter[status]=active&per_page=50', $url);

            return new JsonMockResponse(['data' => []]);
        });

        (new SendFox('KEY', $client))->request('GET', '/contacts', ['query' => ['filter' => ['status' => 'active'], 'per_page' => 50]]);
    }

    public function testEmptyResponseIsEmptyArray(): void
    {
        $client = new MockHttpClient(function (string $method, string $url) {
            $this->assertSame('https://api.sendfox.com/contacts/3/tags/9', $url);

            return new MockResponse('', ['http_code' => 204]);
        });

        $this->assertSame([], (new SendFox('KEY', $client))->request('DELETE', '/contacts/3/tags/9'));
    }

    public function testThrowsWithErrorBody(): void
    {
        $client = new MockHttpClient(new JsonMockResponse(
            ['message' => 'The email field is required.', 'errors' => ['email' => ['The email field is required.']]],
            ['http_code' => 422],
        ));

        try {
            (new SendFox('KEY', $client))->request('POST', '/contacts', ['json' => []]);
            $this->fail('Expected SendFoxException');
        } catch (SendFoxException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertSame('The email field is required.', $e->getMessage());
            $this->assertSame(['email' => ['The email field is required.']], $e->errors());
        }
    }
}
