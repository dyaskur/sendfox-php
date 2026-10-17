<?php

namespace Dyaskur\SendFox;

/**
 * Fired once per verified SendFox webhook event.
 */
class WebhookReceived
{
    /** `sent`, `delivered`, `delayed`, `bounced`, `complained` or `failed`. */
    public readonly string $type;

    /**
     * @param array $payload the event: ['id' => 'whd_...', 'event' => ..., 'occurred_at' => ..., 'data' => ['message' => [...], 'recipient' => ..., 'status' => ..., 'reason' => ...]]
     */
    public function __construct(public readonly array $payload)
    {
        $this->type = $payload['event'] ?? '';
    }
}
