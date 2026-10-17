<?php

namespace Dyaskur\SendFox;

/**
 * @see https://sendfox.com/developer/docs/#tag/Transactional (Webhooks)
 */
final class Webhook
{
    /**
     * Checks the X-SendFox-Signature header (`t={unix},v1={hex}`) and returns the decoded event.
     *
     * @param string $payload the raw request body, before any JSON parsing
     *
     * @throws \UnexpectedValueException when the signature is missing, wrong or too old
     * @throws \JsonException            when the body is not JSON
     */
    public static function verify(string $payload, ?string $signatureHeader, #[\SensitiveParameter] string $secret, int $tolerance = 300): array
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', (string) $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ('t' === $key) {
                $timestamp = $value;
            } elseif ('v1' === $key) {
                $signatures[] = $value;
            }
        }

        if ('' === $secret || !ctype_digit((string) $timestamp) || !$signatures) {
            throw new \UnexpectedValueException('Missing or malformed X-SendFox-Signature header.');
        }
        if (abs(time() - (int) $timestamp) > $tolerance) {
            throw new \UnexpectedValueException('Webhook timestamp is outside the tolerance window.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return json_decode($payload, true, flags: \JSON_THROW_ON_ERROR);
            }
        }

        throw new \UnexpectedValueException('Webhook signature does not match.');
    }
}
