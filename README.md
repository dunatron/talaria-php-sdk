# newtalaria/logging

Official PHP SDK for [Talaria](https://www.newtalaria.com) — capture exceptions and application logs into triageable issues. Framework-agnostic core, with a Silverstripe Monolog / Injector adapter and optional browser SDK injection.

Events are **queued in memory** and sent with batch ingest when the buffer hits a size limit, exceeds a max age, or the request shuts down. Fingerprinting stays on the server.

Docs: [PHP SDK guide](https://www.newtalaria.com/docs/sdk/php) · Dashboard: [one.newtalaria.com](https://one.newtalaria.com)

## Install

```bash
composer require newtalaria/logging
```

## Initialize (best practices)

Create a client key under **Project settings → Client keys** (`tal_live_…`).

```php
use Talaria\Talaria;

Talaria::init([
    'dsn' => 'https://api.newtalaria.com',
    'apiKey' => 'tal_live_…',
    'environment' => 'production', // staging | development also accepted
    'release' => '1.4.2',          // deploy version — first-class field, not a tag
    'commitSha' => getenv('TALARIA_COMMIT_SHA') ?: null, // optional; enables GitHub source
    'minLevel' => 'warning',       // production: drop info/debug noise
    'sampleRate' => 1.0,
    'tags' => [
        'service' => 'api',
        'platform' => 'php',
    ],
    'maxBatchSize' => 50,
    'flushIntervalMs' => 2000,
]);
```

**Recommendations**

| Concern | Production default |
| --- | --- |
| Log volume | `minLevel: 'warning'` (use `'info'` / `'debug'` only when you intentionally want noisier capture) |
| Identity | Set `userId` when you know the signed-in user |
| Product dims | Put stable filters in init `tags` (`service`, `platform`) |
| Shutdown | Leave `defaultIntegrations: true` so uncaught errors flush on shutdown |

`environment` must resolve to `production` | `staging` | `development`. Common aliases work (`prod` / `live` → `production`, `test` / `uat` → `staging`, `dev` / `local` → `development`).

## Logging

Prefer a scoped logger for application code. Level methods wrap `captureMessage`; use `captureException` for throwables.

```php
$logger = Talaria::logger([
    'tags' => ['feature' => 'checkout', 'operation' => 'pay'],
]);

$logger->info('Checkout opened');              // filtered if minLevel is warning
$logger->warn('Payment method missing');

try {
    charge();
} catch (Throwable $e) {
    $logger->captureException($e, [
        'tags' => ['component' => 'stripe'],
        'extra' => ['cart_id' => 'abc123'],
    ]);
    throw $e;
}
```

| Method | Severity sent |
| --- | --- |
| `debug` / `info` / `warning` / `error` / `fatal` | same name |
| `warn` | `warning` |
| `log($level, $message, $context)` | mapped severity |
| `captureException($e, $context)` | `error` |

Also available on the root facade (`Talaria::warn(…)`, etc.) and on `TalariaClient`.  
`Talaria::withTags([…])` is shorthand for `Talaria::logger(['tags' => […]])`.  
Low-level `captureMessage($message, $level, $context)` remains supported.

`Talaria\Logger` implements **PSR-3** (`LoggerInterface`), so you can type-hint the interface and still call Talaria-native helpers (`withTags`, `child`, `captureException`, …).

### Filtering

Gates run in order. Filtered calls are quiet no-ops (no throw).

1. **`minLevel`** (default `'debug'`) — **default/root** severity. Direct client captures and unset scopes use this. Scoped loggers may override below it unless `enforceDefaultLevel` is true.
2. **`sampleRate`** (default `1`) — fraction of eligible events to enqueue.
3. **`beforeSend`** — return `null` to drop, or a mutated event array. Not called when earlier gates already dropped the capture.

Scoped loggers **inherit** the client default and can assign a higher or lower floor (Logback-style). Full rules: [docs/logging-levels.md](docs/logging-levels.md).

```php
// warning globally, info for one area
Talaria::init([/* … */, 'minLevel' => 'warning', 'enforceDefaultLevel' => false]);

$directory = Talaria::logger(['minLevel' => 'info', 'tags' => ['area' => 'businessDirectory']]);
$directory->info('Listing loaded'); // sent

$payments = $logger->child([
    'tags' => ['component' => 'payments'],
    'minLevel' => 'error', // quieter than default
]);

if ($logger->isLevelEnabled('info')) {
    // build expensive context only when it would send
}
```

## Good patterns

### Feature-scoped logger per flow

```php
function checkoutLogger(string $step): \Talaria\Logger
{
    return Talaria::logger([
        'tags' => ['feature' => 'checkout', 'operation' => $step],
    ]);
}

$logger = checkoutLogger('review');
$logger->warn('Address validation failed', ['extra' => ['field' => 'postcode']]);
```

### Tags vs `extra`

| Use | For | Examples |
| --- | --- | --- |
| **tags** | Low-cardinality dimensions you filter/group on | `feature`, `operation`, `component`, `service` |
| **extra** | High-cardinality diagnostics | `cart_id`, payloads, counts |

```php
$logger->error('Charge declined', [
    'tags' => ['component' => 'stripe', 'operation' => 'charge'],
    'extra' => ['cart_id' => 'cart_01H…', 'decline_code' => 'insufficient_funds'],
]);
```

### Child logger that only sends errors

```php
$analytics = Talaria::logger(['tags' => ['feature' => 'analytics']])
    ->withMinLevel('error');
$analytics->info('page_view'); // no-op when floor is error
$analytics->captureException($e);
```

### PSR-3 placeholders + exception key

```php
$logger->warning('Order {order_id} delayed', ['order_id' => '42']);

try {
    risky();
} catch (Throwable $e) {
    // Prefer the exception key — maps to captureException with a real stack.
    $logger->error('Checkout failed', [
        'exception' => $e,
        'tags' => ['feature' => 'checkout'],
        'order_id' => '123',
    ]);
    throw $e;
}
```

### Redact before send

```php
Talaria::init([
    'dsn' => 'https://api.newtalaria.com',
    'apiKey' => 'tal_live_…',
    'environment' => 'production',
    'minLevel' => 'warning',
    'beforeSend' => static function (array $event, array $hint): ?array {
        if (str_contains(strtolower((string) $event['message']), 'password')) {
            return null;
        }
        if (isset($event['extra']['rawCard'])) {
            unset($event['extra']['rawCard']);
        }
        return $event;
    },
]);
```

### Severity guidance

- **`warn` / `error` / `fatal`** — user-impacting or actionable problems (default production traffic when `minLevel: 'warning'`).
- **`info`** — intentional product signals when you lower `minLevel` or run in staging.
- **`debug`** — local diagnosis only; leave filtered out in production.

## Tags

Preferred conventions: `service`, `platform`, `feature`, `operation`, `component`, `runtime`, `runtime_version`.

**Merge order (later wins):** automatic runtime tags → init / global tags → processors → logger scope → per-call `context.tags`.

Do **not** put `environment` or `release` in tags — use the first-class init fields. Do **not** put user ids, emails, order ids, or URLs in tags — use `userId` / `extra`.

Prefer `addProcessor()` over `setTags()` for per-request dimensions on long-lived singletons (e.g. Silverstripe Injector).

## Silverstripe

Supported: **Silverstripe 4.13+ / 5 / 6** on **PHP 8.1+**.

```bash
composer require newtalaria/logging
```

Set `TALARIA_DSN`, `TALARIA_API_KEY`, `TALARIA_ENVIRONMENT`, and optional `TALARIA_RELEASE` / `TALARIA_COMMIT_SHA` / `TALARIA_BROWSER_DSN`, then:

```bash
vendor/bin/sake dev/build flush=1
```

**Two logging approaches** (can coexist):

1. **Monolog / `LoggerInterface`** — default Injector path; type-hint PSR-3; YAML `minLevel` (default `warning`)
2. **Talaria Logger** — scoped tags, `child`, `withMinLevel`, `captureException` (wrap the Injector `TalariaClient`; do not double-`init`)

Full walkthrough, YAML examples, hybrid recommendation, browser inject, and troubleshooting: **[docs/silverstripe.md](docs/silverstripe.md)**.

Browser CDN pin and inject details: [`client/README.md`](client/README.md) (default `browserSdkVersion` **0.1.21**).

## Batching

| Trigger | Default |
| --- | --- |
| Size | `maxBatchSize` 50 |
| Age | `flushIntervalMs` 2000 (checked on enqueue) |
| Explicit | `Talaria::flush()` / `close()` |
| Shutdown | when `defaultIntegrations` is true |

Call `Talaria::flush()` before long-running CLI exit if you need guarantees beyond shutdown hooks.

## Init options

| Option | Default | Description |
| --- | --- | --- |
| `dsn` / `baseUrl` | *(required)* | Talaria API base URL, e.g. `https://api.newtalaria.com` |
| `apiKey` | *(required)* | Project client key (`tal_live_…`) |
| `environment` | *(required)* | `production` \| `staging` \| `development` (aliases accepted) |
| `release` | — | Optional release string on every event |
| `commitSha` | — | Optional git SHA for source context (`TALARIA_COMMIT_SHA`) |
| `userId` | — | Optional app user id |
| `tags` | `[]` | Tags merged into every event |
| `minLevel` | `'debug'` | Default/root severity; use `'warning'` in production |
| `enforceDefaultLevel` | `false` | When true, scoped loggers cannot go below `minLevel` |
| `loggers` | `[]` | Named presets for `Talaria::logger('name')` |
| `sampleRate` | `1.0` | Fraction of eligible events to enqueue (after level gate) |
| `beforeSend` | — | `fn (array $event, array $hint): ?array` — mutate or drop after gates |
| `maxBatchSize` | `50` | Flush when buffer reaches N events |
| `flushIntervalMs` | `2000` | Flush when oldest buffered event is this old |
| `defaultIntegrations` | `true` | Uncaught / fatal handlers + shutdown flush |
| `httpTimeoutSeconds` | `3.0` | HTTP timeout for batch ingest |

## Public API

| API | Description |
| --- | --- |
| `Talaria::init($options)` | Configure singleton client |
| `Talaria::logger($name\|$options?)` | Scoped PSR-3 + Talaria logger (`tags`, `minLevel`, named presets) |
| `Talaria::withTags($tags)` | Shorthand for `logger(['tags' => $tags])` |
| `Talaria::debug/info/warning/warn/error/fatal` | Level helpers |
| `Talaria::log($level, $message, $context?)` | Generic level helper |
| `Talaria::captureException($e, $context?)` | Ingest error |
| `Talaria::captureMessage($message, $level?, $context?)` | Ingest message |
| `Talaria::getMinLevel()` / `setMinLevel($level)` | Read/update default/root level |
| `Talaria::isEnforceDefaultLevel()` / `setEnforceDefaultLevel($bool)` | Hard-floor toggle |
| `Talaria::isLevelEnabled($level)` | Whether a level would pass the root floor |
| `Talaria::flush()` / `close()` | Drain queue / shut down |

Scoped loggers also expose `child`, `withMinLevel`, `withTags`, `isLevelEnabled`, `getMinLevel`, and `capture*`.

`Talaria\TalariaClient` is available for DI. `Talaria\Client` remains a deprecated alias.

## Troubleshooting

| Symptom | What to check |
| --- | --- |
| 401 / 403 | Client key belongs to the project |
| `info` never appears | Root `minLevel` is often `'warning'` — use a scoped/named logger with `minLevel: 'info'`, or lower the root |
| Exceptions missing after `setMinLevel('fatal')` | `captureException` counts as `'error'` |
| Nothing after deploy | Confirm `environment` filter in the dashboard; call `flush` in CLI scripts |

More guides: [www.newtalaria.com/docs](https://www.newtalaria.com/docs)
