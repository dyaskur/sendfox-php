<?php

namespace Dyaskur\SendFox\Tests;

use Dyaskur\SendFox\SendFoxServiceProvider;
use Dyaskur\SendFox\Webhook;
use Dyaskur\SendFox\WebhookController;
use Dyaskur\SendFox\WebhookReceived;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as Cache;
use Illuminate\Config\Repository as Config;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use PHPUnit\Framework\TestCase;

class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_test';
    private const BODY = '{"id":"whd_1","event":"bounced","data":{"recipient":"ada@example.com"}}';

    private static function sign(string $body, ?int $t = null, string $secret = self::SECRET): string
    {
        $t ??= time();

        return "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", $secret);
    }

    public function testVerifyReturnsEvent(): void
    {
        $this->assertSame('bounced', Webhook::verify(self::BODY, self::sign(self::BODY), self::SECRET)['event']);
    }

    public function testVerifyRejectsBadInput(): void
    {
        $cases = [
            'wrong secret' => self::sign(self::BODY, secret: 'whsec_other'),
            'tampered body' => self::sign(self::BODY.' '),
            'too old' => self::sign(self::BODY, time() - 301),
            'missing' => null,
            'malformed' => 'v1=abc',
        ];

        foreach ($cases as $case => $header) {
            try {
                Webhook::verify(self::BODY, $header, self::SECRET);
                $this->fail("Accepted: $case");
            } catch (\UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testControllerDispatchesOncePerEventId(): void
    {
        $events = new Dispatcher();
        $received = [];
        $events->listen(WebhookReceived::class, function (WebhookReceived $e) use (&$received) {
            $received[] = $e->type;
        });
        $config = new Config(['services' => ['sendfox' => ['webhook_secret' => self::SECRET]]]);
        $cache = new Cache(new ArrayStore());
        $request = fn (string $signature) => Request::create('/sendfox/webhook', 'POST', server: ['HTTP_X_SENDFOX_SIGNATURE' => $signature], content: self::BODY);

        $controller = new WebhookController();
        $this->assertSame(200, $controller($request(self::sign(self::BODY)), $config, $cache, $events)->getStatusCode());
        $this->assertSame(200, $controller($request(self::sign(self::BODY)), $config, $cache, $events)->getStatusCode());
        $this->assertSame(400, $controller($request('t=1,v1=bad'), $config, $cache, $events)->getStatusCode());

        $this->assertSame(['bounced'], $received);
    }

    public function testRouteRegisteredOnlyWithSecret(): void
    {
        foreach ([[null, false], [self::SECRET, true]] as [$secret, $expected]) {
            $app = new Container();
            $app->instance('config', new Config(['services' => ['sendfox' => ['webhook_secret' => $secret]]]));
            $app->instance('router', $router = new Router(new Dispatcher(), $app));
            (new SendFoxServiceProvider($app))->boot();

            $router->getRoutes()->refreshNameLookups();
            $this->assertSame($expected, $router->getRoutes()->hasNamedRoute('sendfox.webhook'));
        }
    }
}
