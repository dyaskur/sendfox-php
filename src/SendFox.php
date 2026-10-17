<?php

namespace Dyaskur\SendFox;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * SendFox REST API client. Method names match the operation IDs in the docs.
 *
 * @see https://sendfox.com/developer/docs/
 */
class SendFox
{
    private HttpClientInterface $client;

    public function __construct(#[\SensitiveParameter] string $key, ?HttpClientInterface $client = null)
    {
        $this->client = ($client ?? HttpClient::create())->withOptions([
            'base_uri' => 'https://api.sendfox.com',
            'auth_bearer' => $key,
            'headers' => ['Accept' => 'application/json'],
        ]);
    }

    /**
     * Call any endpoint, including ones added to the API after this release.
     *
     * @throws SendFoxException on a non-2xx response
     */
    public function request(string $method, string $uri, array $options = []): array
    {
        $response = $this->client->request($method, $uri, $options);

        if ($response->getStatusCode() >= 300) {
            throw new SendFoxException($response);
        }

        return '' === $response->getContent() ? [] : $response->toArray();
    }
}
