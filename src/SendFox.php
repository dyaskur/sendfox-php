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

    // Contact Tags
    public function listContactTags(): array
    {
        return $this->request('GET', '/contact-tags');
    }

    public function createContactTag(array $data): array
    {
        return $this->request('POST', '/contact-tags', ['json' => $data]);
    }

    public function getContactTag(int|string $id): array
    {
        return $this->request('GET', "/contact-tags/{$id}");
    }

    public function updateContactTag(int|string $id, array $data): array
    {
        return $this->request('PATCH', "/contact-tags/{$id}", ['json' => $data]);
    }

    public function deleteContactTag(int|string $id): array
    {
        return $this->request('DELETE', "/contact-tags/{$id}");
    }

    public function listContactTagsForContact(int|string $contactId): array
    {
        return $this->request('GET', "/contacts/{$contactId}/tags");
    }

    public function attachContactTag(int|string $contactId, int|string $tagId): array
    {
        return $this->request('POST', "/contacts/{$contactId}/tags/{$tagId}");
    }

    public function detachContactTag(int|string $contactId, int|string $tagId): array
    {
        return $this->request('DELETE', "/contacts/{$contactId}/tags/{$tagId}");
    }

    // Contact Fields
    public function listContactFields(): array
    {
        return $this->request('GET', '/contact-fields');
    }

    public function createContactField(array $data): array
    {
        return $this->request('POST', '/contact-fields', ['json' => $data]);
    }

    public function getContactField(int|string $id): array
    {
        return $this->request('GET', "/contact-fields/{$id}");
    }

    public function updateContactField(int|string $id, array $data): array
    {
        return $this->request('PATCH', "/contact-fields/{$id}", ['json' => $data]);
    }

    public function deleteContactField(int|string $id): array
    {
        return $this->request('DELETE', "/contact-fields/{$id}");
    }

    // Lists
    public function listContactLists(array $query = []): array
    {
        return $this->request('GET', '/lists', ['query' => $query]);
    }

    public function createContactList(array $data): array
    {
        return $this->request('POST', '/lists', ['json' => $data]);
    }

    public function getContactList(int|string $id): array
    {
        return $this->request('GET', "/lists/{$id}");
    }

    public function updateContactList(int|string $id, array $data): array
    {
        return $this->request('PATCH', "/lists/{$id}", ['json' => $data]);
    }

    public function deleteContactList(int|string $id): array
    {
        return $this->request('DELETE', "/lists/{$id}");
    }

    public function listListUnsubscribes(int|string $listId, array $query = []): array
    {
        return $this->request('GET', "/lists/{$listId}/unsubscribes", ['query' => $query]);
    }

    public function unsubscribeContactFromList(int|string $listId, array $data): array
    {
        return $this->request('PATCH', "/lists/{$listId}/unsubscribe", ['json' => $data]);
    }

    public function listContactsInList(int|string $listId, array $query = []): array
    {
        return $this->request('GET', "/lists/{$listId}/contacts", ['query' => $query]);
    }

    public function addContactToList(int|string $listId, array $data): array
    {
        return $this->request('POST', "/lists/{$listId}/contacts", ['json' => $data]);
    }

    public function removeContactFromList(int|string $listId, int|string $contactId): array
    {
        return $this->request('DELETE', "/lists/{$listId}/contacts/{$contactId}");
    }
}
