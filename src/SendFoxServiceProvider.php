<?php

namespace Dyaskur\SendFox;

use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Mail\MailManager;
use Illuminate\Support\ServiceProvider;

class SendFoxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SendFox::class, fn ($app) => new SendFox($app['config']->get('services.sendfox.key')));
    }

    public function boot(): void
    {
        $this->callAfterResolving('mail.manager', function (MailManager $manager) {
            $manager->extend('sendfox', fn (array $config = []) => new SendFoxTransport(
                $config['key'] ?? $this->app['config']->get('services.sendfox.key'),
            ));
        });

        $cached = $this->app instanceof CachesRoutes && $this->app->routesAreCached();
        if ($this->app['config']->get('services.sendfox.webhook_secret') && !$cached) {
            $this->app['router']->post('sendfox/webhook', WebhookController::class)->name('sendfox.webhook');
        }
    }
}
