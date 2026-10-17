<?php

namespace Dyaskur\SendFox;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WebhookController
{
    public function __invoke(Request $request, Config $config, Cache $cache, Dispatcher $events): Response
    {
        try {
            $payload = Webhook::verify($request->getContent(), $request->header('X-SendFox-Signature'), (string) $config->get('services.sendfox.webhook_secret'));
        } catch (\UnexpectedValueException|\JsonException $e) {
            return new Response($e->getMessage(), 400);
        }

        // SendFox retries for about 20 hours with the same id. Mark it seen only after
        // listeners succeed, so a failed listener gets the retry.
        $key = 'sendfox-webhook:'.($payload['id'] ?? '');
        if (!$cache->has($key)) {
            $events->dispatch(new WebhookReceived($payload));
            $cache->put($key, true, 2 * 24 * 60 * 60);
        }

        return new Response();
    }
}
