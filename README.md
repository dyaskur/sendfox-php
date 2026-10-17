# SendFox PHP

Send email through the [SendFox Transactional API](https://sendfox.com/developer/docs/#tag/Transactional) from Laravel or any app using [Symfony Mailer](https://symfony.com/doc/current/mailer.html).

- Laravel `sendfox` mail transport, auto-discovered: use `Mail::`, Mailables and Notifications as usual.
- Plain Symfony Mailer transport for any other PHP project.
- Supports to/cc/bcc, reply-to, HTML and text bodies, attachments, tags and custom headers.

## About SendFox

[SendFox](https://sendfox.com) is an email marketing platform for creators and small businesses. Besides contacts, lists, campaigns, automations and signup forms, it offers a **Transactional** API for the emails your app sends one at a time: login codes, password resets, receipts and notifications.

- **Included in every plan, including the free one.** Transactional email has no separate quota: each recipient counts as one send against the same monthly sends your campaigns use.
- **Your own domain.** You send from a domain you verify under **Account > Domains**. A sandbox sender, `onboarding@sendfoxmail.com`, lets you test before DNS is set up.
- **Delivery tracking.** Every message gets an ID (`tm_...`). Its status (`queued`, `sent`, `delivered`, `bounced`, `complained`, `failed`, `suppressed`) shows up in the dashboard, through the API and through webhooks.
- **Built-in protection.** Recipients on your suppression list are refused, and tags that look like authentication mail (`login_code`, `password_reset`, `otp`) never get open or click tracking.

Links: [Website](https://sendfox.com) · [API docs](https://sendfox.com/developer/docs/) · [Transactional API](https://sendfox.com/developer/docs/#tag/Transactional)

## Requirements

- PHP 8.2+
- Symfony Mailer 7 or 8
- Laravel 11, 12 or 13 (optional)
- A SendFox account and an API key from **Settings > API tokens** (the Transactional page creates your first one)

## Installation

```bash
composer require dyaskur/sendfox-php
```

## Laravel

### 1. Configure

The service provider is registered automatically. Add your API key to `config/services.php`:

```php
'sendfox' => [
    'key' => env('SENDFOX_API_KEY'),
],
```

Add a mailer to `config/mail.php`:

```php
'mailers' => [
    // ...

    'sendfox' => [
        'transport' => 'sendfox',
        // 'key' => env('SENDFOX_API_KEY'), // optional, overrides services.sendfox.key
    ],
],
```

Set it as the default in `.env`:

```dotenv
MAIL_MAILER=sendfox
SENDFOX_API_KEY=your-api-key
MAIL_FROM_ADDRESS=hello@mail.your-domain.com
MAIL_FROM_NAME="${APP_NAME}"
```

The `from` domain must be verified under **Account > Domains** in SendFox. To try things out before DNS is set up, see [Sandbox testing](#sandbox-testing).

### 2. Send

Everything in the [Laravel mail docs](https://laravel.com/docs/mail) works unchanged:

```php
use App\Mail\OrderShipped;
use Illuminate\Support\Facades\Mail;

Mail::to('jane@example.com')
    ->cc('ops@example.com')
    ->send(new OrderShipped($order));

Mail::raw('Your code is 483 920', function ($message) {
    $message->to('jane@example.com')->subject('Your login code');
});
```

If SendFox is not your default mailer, pick it per message:

```php
Mail::mailer('sendfox')->to('jane@example.com')->send(new OrderShipped($order));
```

Or use it as the primary mailer with a fallback:

```php
'failover' => [
    'transport' => 'failover',
    'mailers' => ['sendfox', 'smtp'],
],
```

### Tags

SendFox groups messages by `tag` in its dashboard, and uses tags for per-tag tracking settings and alerts. The first tag on a Mailable is sent as the SendFox tag:

```php
use Illuminate\Mail\Mailables\Envelope;

public function envelope(): Envelope
{
    return new Envelope(
        subject: 'Your receipt',
        tags: ['receipt'],
    );
}
```

Tags must be lowercase letters, digits, `.`, `-` or `_`. Tag by kind of email (`receipt`, `password_reset`), not per customer: an account can hold at most 200 distinct tags.

Laravel metadata (`metadata: ['order_id' => 42]`) is sent as `X-Metadata-*` headers.

### Custom headers

Use `List-*`, `References`, `In-Reply-To` and any `X-*` header. For one-click unsubscribe (RFC 8058, which Gmail and Yahoo require of bulk senders):

```php
use Illuminate\Mail\Mailables\Headers;

public function headers(): Headers
{
    return new Headers(
        text: [
            'List-Unsubscribe' => '<https://acme.com/unsubscribe?u=4f0c>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ],
    );
}
```

### Attachments

```php
use Illuminate\Mail\Mailables\Attachment;

public function attachments(): array
{
    return [
        Attachment::fromPath(storage_path('invoices/1042.pdf')),
        Attachment::fromData(fn () => $this->ics, 'invite.ics')->withMime('text/calendar; method=REQUEST'),
    ];
}
```

### Getting the SendFox message ID

SendFox returns an ID like `tm_4f0c9b2e7d1a5c3e8b6f2a9d`, which you can use with `GET /transactional/emails/{id}` and to match webhook events:

```php
$sent = Mail::to('jane@example.com')->send(new OrderShipped($order));

$sent->getMessageId(); // "tm_4f0c9b2e7d1a5c3e8b6f2a9d"
```

When mail is queued, read it from the `Illuminate\Mail\Events\MessageSent` event instead: `$event->sent->getMessageId()`.

### Notifications

Mail notifications use your default mailer, or pick SendFox per notification:

```php
public function toMail(object $notifiable): MailMessage
{
    return (new MailMessage)
        ->mailer('sendfox')
        ->subject('Invoice paid')
        ->line('Thanks for your payment.');
}
```

## Plain PHP (Symfony Mailer)

```php
use Dyaskur\SendFox\SendFoxTransport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;

$mailer = new Mailer(new SendFoxTransport('your-api-key'));

$mailer->send((new Email())
    ->from('Acme <hello@mail.acme.com>')
    ->to('jane@example.com')
    ->subject('Your login code')
    ->text('Your code is 483 920.')
    ->html('<p>Your code is <strong>483 920</strong>.</p>'));
```

To get the message ID, call the transport directly:

```php
$transport = new SendFoxTransport('your-api-key');

$sent = $transport->send($email);
$sent->getMessageId(); // "tm_..."
```

The second constructor argument takes any Symfony HTTP client, for example to set a timeout:

```php
use Symfony\Component\HttpClient\HttpClient;

$transport = new SendFoxTransport('your-api-key', HttpClient::create(['timeout' => 10]));
```

## Error handling

A response other than `202 Accepted` throws `Symfony\Component\Mailer\Exception\HttpTransportException`. The exception message contains SendFox's error message and any field errors, and the raw response is available from `$e->getResponse()`:

```php
use Symfony\Component\Mailer\Exception\HttpTransportException;

try {
    Mail::to('jane@example.com')->send(new OrderShipped($order));
} catch (HttpTransportException $e) {
    $error = $e->getResponse()->toArray(false);

    $error['name'];       // e.g. "domain_not_verified", "rate_limit_exceeded"
    $error['fix'] ?? null; // what SendFox suggests doing about it
}
```

Common errors:

| Status | Name | Meaning |
|---|---|---|
| 401 | `missing_api_key`, `invalid_api_key` | Check `SENDFOX_API_KEY` |
| 403 | `insufficient_scope` | The key lacks the transactional scope (older free-plan keys) |
| 403 | `domain_not_verified` | Verify the `from` domain under **Account > Domains** |
| 403 | `sandbox_recipient_not_allowed` | The sandbox sender only delivers to your own address and the test addresses |
| 403 | `recipient_suppressed` | The recipient previously bounced or complained |
| 422 | `validation_error` | A field is invalid; see the field errors in the message |
| 429 | `rate_limit_exceeded`, `monthly_quota_exceeded` | Slow down, or the plan's monthly sends are used up |

## Sandbox testing

Send from `onboarding@sendfoxmail.com` to try the integration without any DNS setup. The sandbox only delivers to your own SendFox account email and to these test addresses, which never reach a mailbox or count toward your limits:

| Address | Outcome |
|---|---|
| `delivered@sendfoxmail.com` | Delivered |
| `bounced@sendfoxmail.com` | Bounced |
| `complained@sendfoxmail.com` | Spam complaint |
| `suppressed@sendfoxmail.com` | Suppressed |

```dotenv
MAIL_FROM_ADDRESS=onboarding@sendfoxmail.com
```

For automated tests in Laravel, `Mail::fake()` works as usual and never calls SendFox.

## SendFox limits

- At most 10 addresses per message across to, cc and bcc, at most 2 of them bcc. Each address counts as one send against your SendFox plan.
- One reply-to address. Any extra Reply-To addresses are dropped.
- HTML body up to 500 KB, text body up to 100 KB.
- At most 10 attachments, 5 MB in total. Executable and script files are refused. Inline (`cid:`) images are sent as regular attachments.
- At most 20 custom headers.
- 5 requests per second and 300 per minute per API key.

## Not supported yet

- The batch endpoint (`POST /transactional/emails/batch`)
- `Idempotency-Key` for safe retries
- Reading message status or handling webhooks

## Testing

```bash
composer install
vendor/bin/phpunit tests
```

## License

MIT. See [LICENSE](LICENSE).
