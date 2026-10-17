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

    // Contacts
    public function listContacts(array $query = []): array
    {
        return $this->request('GET', '/contacts', ['query' => $query]);
    }

    public function createContact(array $data): array
    {
        return $this->request('POST', '/contacts', ['json' => $data]);
    }

    public function getContact(int|string $id): array
    {
        return $this->request('GET', "/contacts/{$id}");
    }

    public function updateContact(int|string $id, array $data): array
    {
        return $this->request('PATCH', "/contacts/{$id}", ['json' => $data]);
    }

    public function deleteContact(int|string $id): array
    {
        return $this->request('DELETE', "/contacts/{$id}");
    }

    public function getContactActivity(int|string $id): array
    {
        return $this->request('GET', "/contacts/{$id}/activity");
    }

    public function batchImportContacts(array $data): array
    {
        return $this->request('POST', '/contacts/batch', ['json' => $data]);
    }

    public function createBulkContactAction(array $data): array
    {
        return $this->request('POST', '/contacts/bulk-actions', ['json' => $data]);
    }

    public function getBulkContactAction(int|string $id): array
    {
        return $this->request('GET', "/contacts/bulk-actions/{$id}");
    }

    public function listUnsubscribedContacts(array $query = []): array
    {
        return $this->request('GET', '/contacts/unsubscribed', ['query' => $query]);
    }

    public function unsubscribeContact(array $data): array
    {
        return $this->request('PATCH', '/unsubscribe', ['json' => $data]);
    }
}
