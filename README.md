# clamp/analytics

Server-side analytics SDK for [Clamp Analytics](https://clamp.sh) in PHP.

Send tracked events from any PHP server to Clamp. Works with Laravel, Symfony, WordPress, Slim, and anything else that runs PHP 8.1+ and can make outbound HTTPS calls.

## Install

```bash
composer require clamp/analytics
```

PHP 8.1+ supported. Requires `ext-curl` and `ext-json` (both standard).

## Quick start

```php
use Clamp\Analytics\Analytics;
use Clamp\Analytics\Money;

Analytics::init(
    projectId: 'proj_xxx',
    apiKey: getenv('CLAMP_API_KEY'),
);

// Simple event
Analytics::track('signup', ['plan' => 'pro', 'method' => 'email']);

// Link a server event to a browser visitor
Analytics::track(
    'subscription_started',
    [
        'plan' => 'pro',
        'total' => new Money(29.00, 'USD'),
    ],
    anonymousId: 'aid_xxx',
);
```

Get a server API key at <https://clamp.sh/dashboard> (Settings → API Keys, format `sk_proj_...`). Set it as an environment variable; never commit it.

## API

### `Analytics::init(projectId, apiKey, endpoint = null)`

Initializes the SDK. Call once at application bootstrap (Laravel `AppServiceProvider`, Symfony compiler pass, WordPress `plugins_loaded` hook). Stores config in static state.

`endpoint` is optional and overrides the default `https://api.clamp.sh`. Use this for self-hosted Clamp deployments or integration testing.

### `Analytics::track(name, properties = [], anonymousId = null, timestamp = null)`

Sends a server event.

- **`name`**: event name string. Examples: `'signup'`, `'subscription_started'`, `'feature_used'`.
- **`properties`**: optional associative array. Values may be `string`, `int`, `float`, `bool`, or `Money`. No nested arrays (other than `Money`) and no plain objects.
- **`anonymousId`**: optional string. Links the server event to a browser visitor.
- **`timestamp`**: optional. Pass a `DateTimeInterface` (timezone-aware preferred; non-UTC timestamps are normalized to UTC) or an ISO 8601 string. If omitted, the SDK uses the current UTC time.

Returns `true` on success. Throws `ClampHttpException` on a non-2xx response or `ClampNotInitializedException` if `init()` wasn't called.

### `Money(amount, currency)`

A typed monetary value. Use it for revenue, refunds, taxes; anywhere a currency-denominated amount belongs.

```php
Analytics::track('purchase', [
    'plan' => 'pro',
    'total' => new Money(29.00, 'USD'),
    'tax' => new Money(4.35, 'USD'),
]);
```

`amount` is in major units (29.00, not 2900). `currency` is an ISO 4217 code (uppercase, three letters).

### `Analytics::captureError(\Throwable $exception, array $context = [], ?string $anonymousId = null, $timestamp = null)`

Sends a throwable as a `$error` event. Convenience over `track()` that extracts message, type, and stack from the throwable. The server adds a stable fingerprint at ingest so the same bug groups across occurrences.

```php
use Clamp\Analytics\Analytics;

try {
    processWebhook($payload);
} catch (\Throwable $e) {
    Analytics::captureError($e, ['webhook' => 'stripe']);
}
```

- **`$exception`**: any `\Throwable`. Stack via `getTraceAsString`, type via the throwable's short class name.
- **`$context`**: optional associative array of additional properties. Values must be primitives (`string`, `int`, `float`, `bool`); the reserved key `'handled'` is ignored.
- **`$anonymousId`**: optional. Links the error to a browser visitor.
- **`$timestamp`**: optional `DateTimeInterface` or ISO 8601 string.

Same return value and exceptions as `track()`. Lengths are capped (`error.message` 1KB, `error.type` 64 chars, `error.stack` 16KB) to match server-side limits.

## Framework integrations

Per-framework integration patterns (Laravel service provider, Symfony event subscriber, WordPress action hook) are documented at <https://clamp.sh/docs/sdk/php>.

## Errors

The SDK is synchronous and throws on failure. There are no automatic retries. If you want fire-and-forget behaviour, wrap the call yourself:

```php
try {
    Analytics::track('subscription_started', [...]);
} catch (\Clamp\Analytics\ClampException $e) {
    error_log('failed to send to Clamp: ' . $e->getMessage());
}
```

For high-throughput webhook handlers, defer to a background queue (Laravel queues, Symfony Messenger, RabbitMQ).

## Links

- Dashboard: <https://clamp.sh/dashboard>
- Docs: <https://clamp.sh/docs/sdk/php>
- Source: <https://github.com/clamp-sh/analytics-php>
- Issues: <https://github.com/clamp-sh/analytics-php/issues>
