# SendFox Mailer

Send email through [SendFox Transactional](https://sendfox.com/developer/docs/#tag/Transactional) from Laravel or any Symfony Mailer app.

```bash
composer require dyaskur/sendfox-php
```

## Laravel

The service provider is auto-discovered. Create a personal access token at https://sendfox.com/account/oauth, then:

`config/services.php`
```php
'sendfox' => [
    'key' => env('SENDFOX_API_KEY'),
],
```

`config/mail.php`
```php
'mailers' => [
    'sendfox' => [
        'transport' => 'sendfox',
        // 'key' => env('SENDFOX_API_KEY'), // optional, overrides services.sendfox.key
    ],
],
```

`.env`
```
MAIL_MAILER=sendfox
SENDFOX_API_KEY=your-token
MAIL_FROM_ADDRESS=hello@your-verified-domain.com
```

Send mail the usual way:

```php
Mail::to('jane@example.com')->send(new OrderShipped($order));
```

The first `->tag()` on a mailable maps to SendFox's `tag`. While testing, send from `onboarding@sendfoxmail.com` (no DNS needed) to your own account email or the `delivered@sendfoxmail.com` / `bounced@sendfoxmail.com` test addresses.

## Plain PHP (Symfony Mailer)

```php
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;
use Dyaskur\SendFox\SendFoxTransport;

$mailer = new Mailer(new SendFoxTransport('your-token'));

$mailer->send((new Email())
    ->from('Acme <hello@mail.acme.com>')
    ->to('jane@example.com')
    ->subject('Your login code')
    ->html('<p>Your code is <strong>483 920</strong>.</p>'));
```

## SendFox limits

- At most 10 addresses across to/cc/bcc, at most 2 bcc.
- One `reply_to` address; extra Reply-To addresses are dropped.
- Attachments: at most 10, 5 MB total, no executables. Inline (`cid:`) images are sent as regular attachments.
- Custom headers: `List-*`, `References`, `In-Reply-To` and `X-*` only.
- 5 requests/second, 300/minute per key.

## Tests

```bash
vendor/bin/phpunit tests
```
