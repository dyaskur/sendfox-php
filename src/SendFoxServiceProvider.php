<?php

namespace Dyaskur\SendFox;

use Illuminate\Mail\MailManager;
use Illuminate\Support\ServiceProvider;

class SendFoxServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->callAfterResolving('mail.manager', function (MailManager $manager) {
            $manager->extend('sendfox', fn (array $config = []) => new SendFoxTransport(
                $config['key'] ?? $this->app['config']->get('services.sendfox.key'),
            ));
        });
    }
}
