# SendFox PHP

[SendFox](https://sendfox.com) for PHP and Laravel: send email through the [SendFox Transactional API](https://sendfox.com/developer/docs/#tag/Transactional), and manage contacts, lists, campaigns and automations through the [SendFox API](https://sendfox.com/developer/docs/).

- Laravel `sendfox` mail transport, auto-discovered: use `Mail::`, Mailables and Notifications as usual.
- Plain Symfony Mailer transport for any other PHP project, supporting to/cc/bcc, reply-to, HTML and text bodies, attachments, tags and custom headers.
- An API client with a method for every SendFox endpoint: contacts, tags, lists, campaigns, automations, forms, domains and transactional email.
- Webhook signature checks, plus a ready-made Laravel route that fires an event per delivery update.
- Automatic retry when SendFox rate-limits a request.

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
    'webhook_secret' => env('SENDFOX_WEBHOOK_SECRET'), // optional, see Webhooks
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

### Safe retries with Idempotency-Key

A queued mail job that fails after SendFox accepted the email would send it again on retry. Give the message a stable `Idempotency-Key` header and SendFox returns the original message instead of sending a second one, for 24 hours:

```php
public function headers(): Headers
{
    return new Headers(
        text: ['Idempotency-Key' => "receipt-{$this->order->id}"],
    );
}
```

The transport sends it as the HTTP header SendFox expects, not as an email header.

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

## SendFox API: contacts, lists, campaigns and more

`Dyaskur\SendFox\SendFox` is a client for the rest of the [SendFox API](https://sendfox.com/developer/docs/). It has one method per endpoint, named after the operation in the docs, and each method returns the decoded JSON response as an array.

```php
use Dyaskur\SendFox\SendFox;

$sendfox = new SendFox('your-api-key');
```

In Laravel, it is bound in the container with the key from `services.sendfox.key`, so you can inject it or resolve it:

```php
public function __construct(private SendFox $sendfox) {}

// or
$sendfox = app(SendFox::class);
```

### Create and send a campaign

```php
$list = $sendfox->createContactList(['name' => 'Newsletter']);

$sendfox->createContact([
    'email' => 'jane@example.com',
    'first_name' => 'Jane',
    'lists' => [$list['id']],
]);

$campaign = $sendfox->createCampaign([
    'title' => 'October newsletter',
    'subject' => 'What we shipped in October',
    'html' => '<p>Here is what is new.</p>',
    'from_name' => 'Acme',
    'from_email' => 'hello@mail.acme.com',
    'lists' => [$list['id']],
    // 'scheduled_at' => '2026-11-01T09:00:00Z', // omit to keep it as a draft
]);

$sendfox->sendTestCampaign($campaign['id'], ['emails' => ['me@acme.com']]);
$sendfox->sendCampaign($campaign['id']);

$stats = $sendfox->getCampaignStats($campaign['id'], ['include_link_stats' => true]);
```

### Contacts, tags and lists

```php
$contacts = $sendfox->listContacts([
    'filter' => ['in_list_ids' => [12, 34], 'never_opened' => true],
    'per_page' => 100,
]);

$tag = $sendfox->createContactTag(['name' => 'customer']);
$sendfox->attachContactTag($contactId, $tag['id']);

$sendfox->addContactToList($listId, ['contact_id' => $contactId]);
$sendfox->unsubscribeContact(['email' => 'jane@example.com']);
```

List endpoints take their query parameters (filters, `page`, `per_page`) as an array. Endpoints that take a request body take it as an array too, with the field names from the docs.

### Iterating every page

List methods return one page at a time. `paginate()` takes an endpoint path and yields every item across all pages, fetching each page only when you reach it:

```php
foreach ($sendfox->paginate('/contacts', ['per_page' => 1000, 'filter' => ['never_opened' => true]]) as $contact) {
    // ...
}

$campaigns = iterator_to_array($sendfox->paginate('/campaigns'), false);
```

### All methods

| Group | Methods |
|---|---|
| Contacts | `listContacts`, `createContact`, `getContact`, `updateContact`, `deleteContact`, `getContactActivity`, `batchImportContacts`, `createBulkContactAction`, `getBulkContactAction`, `listUnsubscribedContacts`, `unsubscribeContact` |
| Contact tags | `listContactTags`, `createContactTag`, `getContactTag`, `updateContactTag`, `deleteContactTag`, `listContactTagsForContact`, `attachContactTag`, `detachContactTag` |
| Contact fields | `listContactFields`, `createContactField`, `getContactField`, `updateContactField`, `deleteContactField` |
| Lists | `listContactLists`, `createContactList`, `getContactList`, `updateContactList`, `deleteContactList`, `listListUnsubscribes`, `unsubscribeContactFromList`, `listContactsInList`, `addContactToList`, `removeContactFromList` |
| Campaigns | `listCampaigns`, `createCampaign`, `getBatchCampaignStats`, `getCampaign`, `updateCampaign`, `deleteCampaign`, `sendCampaign`, `sendTestCampaign`, `getCampaignStats`, `listCampaignEngagement`, `resendCampaign` |
| Content library | `listCampaignTemplates`, `getCampaignTemplate`, `listCampaignSections`, `getCampaignSection` |
| Automations | `listAutomations`, `createAutomation`, `getAutomation`, `updateAutomation`, `deleteAutomation`, `createAutomationEmail`, `updateAutomationEmail`, `deleteAutomationEmail` |
| Forms | `listForms`, `createForm`, `getForm`, `updateForm`, `deleteForm` |
| Domains | `listDomains`, `createDomain`, `getDomain`, `deleteDomain`, `validateDomain` |
| Transactional | `sendTransactionalEmail`, `sendTransactionalEmailBatch`, `listTransactionalEmails`, `getTransactionalEmail` |
| Account | `getCurrentUser` |

For an endpoint not listed here, call it directly:

```php
$sendfox->request('GET', '/campaigns', ['query' => ['page' => 2]]);
```

### Transactional email without Laravel Mail

The mail transport covers single sends. For batches, idempotent retries and delivery status, use the client:

```php
$result = $sendfox->sendTransactionalEmailBatch(['emails' => [
    ['from' => 'Acme <receipts@mail.acme.com>', 'to' => ['jane@example.com'], 'subject' => 'Receipt #1042', 'html' => '<p>Thanks!</p>', 'tag' => 'receipt'],
]], idempotencyKey: 'receipts-2026-10-18');

$message = $sendfox->getTransactionalEmail('tm_4f0c9b2e7d1a5c3e8b6f2a9d');
$message['status']; // "delivered"
```

### API errors

A non-2xx response throws `Dyaskur\SendFox\SendFoxException`:

```php
use Dyaskur\SendFox\SendFoxException;

try {
    $sendfox->createContact(['email' => 'not-an-email']);
} catch (SendFoxException $e) {
    $e->getCode();    // HTTP status, e.g. 422
    $e->getMessage(); // "The email field must be a valid email address."
    $e->errors();     // ['email' => ['The email field must be a valid email address.']]
    $e->body;         // the full decoded error response
}
```

On the Free plan, endpoints outside the free set return `402 Payment Required`. The API allows 60 requests per minute per user; transactional routes have their own limit (see below).

### Automatic retries

The client and the mail transport both retry, up to 3 times:

- a `429` that carries a `Retry-After` header, after that delay. A `429` without one, such as a used-up monthly quota, is not retried.
- a `5xx` or network error on `GET`, `PUT` and `DELETE`. A `POST` is never retried on a `5xx`, since SendFox may already have created the contact or sent the email.

To change the number of retries, or turn them off with `0`, pass `maxRetries`: `new SendFox($key, maxRetries: 5)`. A client you pass in is wrapped the same way.

## Webhooks

SendFox can POST an event to your app for each `sent`, `delivered`, `delayed`, `bounced`, `complained` and `failed` transactional email. Add the URL under **Transactional > Webhooks** in SendFox and copy the signing secret (`whsec_...`).

### Laravel

Set the secret, and the package registers `POST /sendfox/webhook` (route name `sendfox.webhook`, no CSRF):

```dotenv
SENDFOX_WEBHOOK_SECRET=whsec_...
```

Point the SendFox webhook at `https://your-app.com/sendfox/webhook`. Every request is checked against the signature, and each event fires `Dyaskur\SendFox\WebhookReceived` once. SendFox retries a failed delivery with the same event ID, and repeats are skipped:

```php
use Dyaskur\SendFox\WebhookReceived;
use Illuminate\Support\Facades\Event;

Event::listen(function (WebhookReceived $event) {
    if (in_array($event->type, ['bounced', 'complained'])) {
        User::where('email', $event->payload['data']['recipient'])->update(['email_invalid' => true]);
    }
});
```

`$event->payload` is the full event:

```json
{
  "id": "whd_5f0c2a9e1b7d4c3a8e6f1d2b",
  "event": "bounced",
  "occurred_at": "2026-10-10T14:03:22+00:00",
  "data": {
    "message": { "id": "tm_...", "status": "bounced", "to": ["ada@example.com"], "subject": "Your code", "tag": "login_code" },
    "recipient": "ada@example.com",
    "status": "550",
    "reason": "Mailbox does not exist"
  }
}
```

A listener that throws makes the route return an error, so SendFox retries the event later. Repeats are tracked in your app's cache for 2 days.

### Plain PHP

```php
use Dyaskur\SendFox\Webhook;

try {
    $event = Webhook::verify(
        file_get_contents('php://input'),          // the raw body
        $_SERVER['HTTP_X_SENDFOX_SIGNATURE'] ?? null,
        'whsec_...',
    );
} catch (UnexpectedValueException|JsonException) {
    http_response_code(400);
    exit;
}

// Handle $event, skipping any $event['id'] you have already processed.
```

`verify()` rejects a missing or wrong signature, and a timestamp more than 5 minutes from now (pass a fourth argument in seconds to change that).

## Mail error handling

In the mail transport, a response other than `202 Accepted` throws `Symfony\Component\Mailer\Exception\HttpTransportException`. The exception message contains SendFox's error message and any field errors, and the raw response is available from `$e->getResponse()`:

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

## Testing

```bash
composer install
vendor/bin/phpunit tests
```

## License

MIT. See [LICENSE](LICENSE).
