<?php

namespace Dyaskur\SendFox\Tests;

use Dyaskur\SendFox\SendFox;
use Dyaskur\SendFox\SendFoxException;
use Dyaskur\SendFox\SendFoxServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

class SendFoxTest extends TestCase
{
    public function testCreateCampaign(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) {
            $this->assertSame('POST', $method);
            $this->assertSame('https://api.sendfox.com/campaigns', $url);
            $this->assertContains('Authorization: Bearer KEY', $options['headers']);
            $this->assertSame(['title' => 'Launch', 'lists' => [1]], json_decode($options['body'], true));

            return new JsonMockResponse(['id' => 7], ['http_code' => 201]);
        });

        $this->assertSame(['id' => 7], (new SendFox('KEY', $client))->createCampaign(['title' => 'Launch', 'lists' => [1]]));
    }

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

    public function testPaginateWalksAllPages(): void
    {
        $client = new MockHttpClient(function (string $method, string $url) {
            parse_str(parse_url($url, \PHP_URL_QUERY), $query);
            $this->assertSame('100', $query['per_page']);
            $page = (int) $query['page'];
            $data = [1 => [['id' => 1], ['id' => 2]], 2 => [['id' => 3]]][$page] ?? $this->fail("Unexpected page $page");

            return new JsonMockResponse(['data' => $data, 'current_page' => $page, 'per_page' => 2, 'total' => 3]);
        });

        $ids = array_column(iterator_to_array((new SendFox('KEY', $client))->paginate('/lists/12/contacts', ['per_page' => 100]), false), 'id');

        $this->assertSame([1, 2, 3], $ids);
    }

    public function testPaginateStopsWhenPageIsIgnored(): void
    {
        $client = new MockHttpClient(fn () => new JsonMockResponse(['data' => [['id' => 1]], 'current_page' => 1, 'per_page' => 1, 'total' => 5]));

        $this->assertCount(1, iterator_to_array((new SendFox('KEY', $client))->paginate('/campaigns'), false));
    }

    public function testRetriesRateLimitWithRetryAfter(): void
    {
        $responses = [
            new JsonMockResponse(['message' => 'Too Many Attempts.'], ['http_code' => 429, 'response_headers' => ['Retry-After' => '0']]),
            new JsonMockResponse(['id' => 1], ['http_code' => 201]),
        ];
        $client = new MockHttpClient(function () use (&$responses) { return array_shift($responses); });

        $this->assertSame(['id' => 1], (new SendFox('KEY', $client))->request('POST', '/contacts', ['json' => ['email' => 'a@b.co']]));
        $this->assertCount(0, $responses);
    }

    public function testDoesNotRetryQuotaOrPostServerError(): void
    {
        foreach ([[429, []], [503, ['Retry-After' => '0']]] as [$status, $headers]) {
            $responses = [
                new JsonMockResponse(['message' => 'No.'], ['http_code' => $status, 'response_headers' => $headers]),
                new JsonMockResponse(['id' => 1], ['http_code' => 201]),
            ];
            $client = new MockHttpClient(function () use (&$responses) { return array_shift($responses); });

            try {
                (new SendFox('KEY', $client))->request('POST', '/contacts', ['json' => []]);
                $this->fail("Expected SendFoxException on $status");
            } catch (SendFoxException $e) {
                $this->assertSame($status, $e->getCode());
                $this->assertCount(1, $responses);
            }
        }
    }

    public function testLaravelBindsClient(): void
    {
        $app = new Container();
        $app->instance('config', new Repository(['services' => ['sendfox' => ['key' => 'KEY']]]));
        (new SendFoxServiceProvider($app))->register();

        $this->assertSame($app->make(SendFox::class), $app->make(SendFox::class));
    }
}
