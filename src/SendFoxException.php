<?php

namespace Dyaskur\SendFox;

use Symfony\Contracts\HttpClient\ResponseInterface;

class SendFoxException extends \RuntimeException
{
    /** Decoded error body, e.g. ['message' => ..., 'errors' => [...]] or, on transactional routes, ['name' => ..., 'fix' => ...]. */
    public readonly array $body;

    public function __construct(public readonly ResponseInterface $response)
    {
        try {
            $this->body = $response->toArray(false);
        } catch (\Throwable) {
            $this->body = [];
        }

        parent::__construct($this->body['message'] ?? $response->getContent(false), $response->getStatusCode());
    }

    /** Field errors on a 422, keyed by field name. */
    public function errors(): array
    {
        return $this->body['errors'] ?? [];
    }
}
