<?php

namespace Dyaskur\SendFox;

use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Retries a 429 only when it carries Retry-After (a rate limit, not a used-up monthly quota),
 * and a 5xx or network error only for idempotent methods, since SendFox may have acted on a POST.
 */
class RetryStrategy extends GenericRetryStrategy
{
    public function __construct()
    {
        $idempotent = self::IDEMPOTENT_METHODS;

        parent::__construct([0 => $idempotent, 429, 500 => $idempotent, 502 => $idempotent, 503 => $idempotent, 504 => $idempotent]);
    }

    public function shouldRetry(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): ?bool
    {
        if (429 === $context->getStatusCode() && !isset($context->getHeaders()['retry-after'])) {
            return false;
        }

        return parent::shouldRetry($context, $responseContent, $exception);
    }
}
