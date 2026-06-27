# Basic Concepts (App Framework)

> https://docs.nextcloud.com/server/31/developer_manual/basics/

The App Framework is an MVC-style layer with dependency injection. This is the core you
use daily.

## Table of contents

- [Request lifecycle](#request-lifecycle)
- [Routing](#routing)
- [Dependency injection](#dependency-injection)
- [Controllers](#controllers)
- [Middlewares](#middlewares)
- [Events](#events)
- [Background jobs (Cron)](#background-jobs-cron)
- [Caching](#caching)
- [Logging](#logging)
- [Settings](#settings)
- [Storage and database](#storage-and-database)
- [Testing](#testing)

---

## Request lifecycle

An incoming HTTP request hits the front controller (`index.php` / `ocs/v2.php`), which
matches a route, builds the responsible **Controller** through the DI container, injects
request parameters into the action method, and serializes the returned **Response**.

---

## Routing

Routes live in `appinfo/routes.php` and are returned as an array.

```php
<?php
return [
    'routes' => [
        ['name' => 'page#index',     'url' => '/',                'verb' => 'GET'],
        ['name' => 'author#show',    'url' => '/authors/{id}',    'verb' => 'GET'],
        ['name' => 'post#index',     'url' => '/post/{page}',     'verb' => 'GET',
            'defaults' => ['page' => 1]],
        ['name' => 'author_api#cors','url' => '/api/{path}',      'verb' => 'OPTIONS',
            'requirements' => ['path' => '.+']],
    ],
    // OCS routes are exposed under /ocs/v2.php/apps/<appid>/...
    'ocs' => [
        ['name' => 'share#getShares', 'url' => '/api/v1/shares', 'verb' => 'GET'],
    ],
    // Auto-generated CRUD routes (index/show/create/update/destroy):
    'resources' => [
        'author' => ['url' => '/authors'],
    ],
];
```

Route fields:

| Field | Meaning |
|-------|---------|
| `name` | `controller#method`; `author_api#some_method` → `AuthorApiController->someMethod()` |
| `url` | path after `/index.php/apps/<appid>`; `{param}` segments become method args |
| `verb` | GET (default), POST, PUT, DELETE, OPTIONS, … |
| `requirements` | regex per param (e.g. `'.+'` to allow slashes) |
| `defaults` | default values for absent params |
| `postfix` | disambiguate otherwise-identical route names |

**Name resolution:** `author_api#some_method` → underscores to camelCase → `AuthorApi` →
`AuthorApiController`.

### URLGenerator

Inject `OCP\IURLGenerator` to build URLs from route names (use dots, not `#`, and the
exact app name casing):

```php
$url = $this->urlGenerator->linkToRoute('myapp.author_api.do_something', ['id' => 3]);
return new RedirectResponse($url);
```

---

## Dependency injection

Pass dependencies into the constructor instead of `new`-ing them. The **container** is the
factory that builds your classes.

### Auto-wiring (recommended)

Enable by adding `<namespace>` to `info.xml` and returning routes directly from
`routes.php`. The container resolves constructor args by **type hint**
(`SomeType $x` → `$container->get(SomeType::class)`) and by **parameter name**
(`$appName`, `$userId`, `$webRoot`).

```php
class MyService {
    public function __construct(
        private AuthorMapper $mapper,
        private LoggerInterface $logger,
        private string $appName,   // resolved by name
    ) {}
}
```

### Manual registration

In `lib/AppInfo/Application.php`:

```php
public function register(IRegistrationContext $context): void {
    $context->registerService(AuthorService::class, function (ContainerInterface $c): AuthorService {
        return new AuthorService($c->get(AuthorMapper::class));
    });

    // Interfaces & primitives can't be auto-wired — register explicitly:
    $context->registerParameter('TableName', 'my_app_table');
    $context->registerServiceAlias(IAuthorMapper::class, AuthorMapper::class);
}
```

### Common injectable core services

`IDBConnection`, `IRequest`, `IUserSession`, `IConfig`, `IURLGenerator`,
`LoggerInterface`, `IL10N`, `ITimeFactory`, `IClientService`, `IRootFolder`.

**Optional dependencies (NC 28+):** nullable type hints yield `null` instead of throwing:
```php
public function __construct(private ?OptionalService $service) {}
```

---

## Controllers

Controllers live in `lib/Controller/`, extend `OCP\AppFramework\Controller`
(or `OCSController` for OCS, `ApiController` for CORS APIs). Request params are injected
into action methods automatically from URL path, GET query, form body, and JSON body.

```php
namespace OCA\MyApp\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;

class PageController extends Controller {

    #[NoAdminRequired]                 // regular users allowed
    public function index(int $id, string $name = 'john'): DataResponse {
        try {
            return new DataResponse(['id' => $id, 'name' => $name]);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }
    }

    #[PublicPage]                      // unauthenticated access
    #[NoCSRFRequired]
    public function open(): TemplateResponse {
        return new TemplateResponse($this->appName, 'main', ['key' => 'value']);
        // template reads via $_['key']
    }
}
```

### Parameter type casting

URL/GET params arrive as strings; use typed signatures or PHPDoc to cast
`bool`, `int`, `float`:

```php
public function process(int $id, bool $doMore, float $value): DataResponse { /* ... */ }
```

### Response types

| Class | Use |
|-------|-----|
| `DataResponse` | data + HTTP status (best for APIs) |
| `JSONResponse` | JSON payload (returning a bare array also auto-JSONs) |
| `TemplateResponse` | render `templates/<name>.php` |
| `PublicTemplateResponse` | public (unauthenticated) page with header title/actions |
| `RedirectResponse` | redirect to a URL |
| `DownloadResponse` | file download with mimetype |
| `StreamResponse` | stream a file/resource |

### Security attributes

- `#[NoAdminRequired]` — allow non-admin logged-in users
- `#[PublicPage]` — allow unauthenticated access
- `#[NoCSRFRequired]` — skip CSRF token check (needed for non-browser API calls)
- `#[NoTwoFactorRequired]` — bypass 2FA
- `#[UseSession]` — keep the PHP session open for writes

### Rate limiting & brute-force protection

```php
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;

#[UserRateLimit(limit: 5, period: 100)]   // 5 / 100s for users
#[AnonRateLimit(limit: 1, period: 100)]   // 1 / 100s for guests
public function expensive(): DataResponse { /* ... */ }

#[BruteForceProtection(action: 'login')]
public function authenticate(string $password): TemplateResponse {
    $response = new TemplateResponse(/* ... */);
    if (!$this->verify($password)) {
        $response->throttle();            // increment violation counter
    }
    return $response;
}
```

---

## Middlewares

Cross-cutting logic that runs around controller execution (auth, CORS, security headers).
Extend `OCP\AppFramework\Middleware` and register via
`$context->registerMiddleware(MyMiddleware::class)`. The framework's own attribute parsing
(security checks, rate limiting) is implemented as middleware.

---

## Events

Use the **typed OCP event dispatcher**. Hooks and the public emitter are deprecated
(since NC 18).

### Listening to events

Register in `Application::register()`:

```php
$context->registerEventListener(
    \OCP\Files\Events\Node\NodeWrittenEvent::class,
    \OCA\MyApp\Listener\NodeWrittenListener::class,
);
```

Listener class:

```php
namespace OCA\MyApp\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeWrittenEvent;

/** @template-implements IEventListener<NodeWrittenEvent> */
class NodeWrittenListener implements IEventListener {
    public function handle(Event $event): void {
        if (!($event instanceof NodeWrittenEvent)) {
            return;
        }
        $node = $event->getNode();
        // ... react (e.g. watermark the uploaded file). Guard against re-triggering!
    }
}
```

### Dispatching custom events

```php
use OCP\EventDispatcher\Event;
use OCP\IUser;

class UserCreatedEvent extends Event {
    public function __construct(private IUser $user) { parent::__construct(); }
    public function getUser(): IUser { return $this->user; }
}

// emit:
$this->dispatcher->dispatchTyped(new UserCreatedEvent($user));
```

### Useful built-in events

- **Files:** `BeforeNodeWrittenEvent`, `NodeWrittenEvent`, `NodeCreatedEvent`,
  `NodeDeletedEvent`, `NodeRenamedEvent`, `NodeCopiedEvent`
- **Shares:** `ShareCreatedEvent`, `ShareDeletedEvent`
- **Users:** `UserCreatedEvent`, `BeforeUserDeletedEvent`, `BeforeUserLoggedInEvent`,
  `PasswordUpdatedEvent`
- **Metadata:** `MetadataLiveEvent`, `MetadataBackgroundEvent` (see
  [05-digging-deeper.md](05-digging-deeper.md))

> **files_watermark note:** the on-upload trigger listens to `NodeWrittenEvent`; the
> on-share trigger listens to `ShareCreatedEvent`. Always guard against the watermarked
> output write re-triggering the listener (infinite loop).

---

## Background jobs (Cron)

Two job types extend `OCP\BackgroundJob\Job`:

- **`TimedJob`** — recurring; call `setInterval(seconds)`.
- **`QueuedJob`** — one-shot, triggered by code.

```php
namespace OCA\MyApp\BackgroundJob;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class CleanupJob extends TimedJob {
    public function __construct(ITimeFactory $time, private SomeService $service) {
        parent::__construct($time);
        $this->setInterval(3600);                 // hourly
        // $this->setTimeSensitivity(self::TIME_INSENSITIVE); // defer to off-peak
        // $this->setAllowParallelRuns(false);                // NC 27+
    }

    protected function run($arguments): void {
        $this->service->cleanup();
    }
}
```

Register automatically in `info.xml`:

```xml
<background-jobs>
    <job>OCA\MyApp\BackgroundJob\CleanupJob</job>
</background-jobs>
```

Or manage via `OCP\BackgroundJob\IJobList`:

```php
$this->jobList->add(CleanupJob::class, ['uid' => $uid]);
$this->jobList->remove(CleanupJob::class, ['uid' => $uid]);
$this->jobList->scheduleAfter(RevokeShare::class, $args, $timestamp);
```

---

## Caching

Three cache tiers via `OCP\ICacheFactory`:

- `createInMemory()` — per-request memory cache.
- `createLocal()` — local server cache (APCu).
- `createDistributed()` — shared across servers (Redis/Memcached).

```php
$cache = $this->cacheFactory->createDistributed('myapp');
$cache->set('key', $value, 3600);   // TTL seconds
$value = $cache->get('key');
```

Check `$cacheFactory->isAvailable()` / `isLocalCacheAvailable()` before relying on it.

---

## Logging

PSR-3 logger, injectable as `Psr\Log\LoggerInterface`:

```php
use Psr\Log\LoggerInterface;

public function __construct(private LoggerInterface $logger) {}

$this->logger->error('Something failed', ['extra_context' => $value, 'exception' => $e]);
$this->logger->warning('Heads up');
$this->logger->info('FYI');
```

Without DI:

```php
use function OCP\Log\logger;
logger('myapp')->warning('no DI here');
```

**Admin audit log** — emit a critical-action event:

```php
use OCP\Log\Audit\CriticalActionPerformedEvent;

$dispatcher->dispatchTyped(new CriticalActionPerformedEvent(
    'Watermark applied to file %s by %s',
    ['file' => $name, 'user' => $uid],
));
```

> **files_watermark note:** the app maintains its own `oc_watermark_log` table for
> audit entries; the admin audit log event above is the platform-wide complement.

---

## Settings

Implement `OCP\Settings\ISettings` for a form and `OCP\Settings\IIconSection` for a section.

```php
use OCP\Settings\ISettings;
use OCP\AppFramework\Http\TemplateResponse;

class AdminSettings implements ISettings {
    public function getForm(): TemplateResponse {
        return new TemplateResponse('myapp', 'admin', [/* params */]);
    }
    public function getSection(): string { return 'myapp'; }   // section id
    public function getPriority(): int { return 50; }          // 0..100
}
```

Register in `info.xml`:

```xml
<settings>
    <admin>OCA\MyApp\Settings\AdminSettings</admin>
    <admin-section>OCA\MyApp\Settings\AdminSection</admin-section>
    <personal>OCA\MyApp\Settings\PersonalSettings</personal>
    <personal-section>OCA\MyApp\Settings\PersonalSection</personal-section>
</settings>
```

`IIconSection` requires `getID()`, `getName()`, `getPriority()` (0..99), `getIcon()`.
A **declarative settings** API also exists for schema-defined forms without templates
(see [05-digging-deeper.md](05-digging-deeper.md)).

---

## Storage and database

### Entity + QBMapper

```php
namespace OCA\MyApp\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method string getName()
 * @method void setName(string $name)
 */
class Author extends Entity {
    protected ?string $name = null;
    protected int $stars = 0;

    public function __construct() {
        $this->addType('stars', Types::INTEGER);
    }
}
```

```php
namespace OCA\MyApp\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<Author> */
class AuthorMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'myapp_authors');
    }

    public function find(int $id): Author {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
        return $this->findEntity($qb);
    }
}
```

### Raw query builder

```php
$qb = $this->db->getQueryBuilder();
$qb->select('*')->from('myapp_authors')
   ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
$result = $qb->executeQuery();   // executeStatement() for write queries
```

### Transactions (TTransactional trait)

```php
$this->atomic(function () {
    // unit of work; auto commit / rollback
}, $this->db);
```

### Entity types

`Types::INTEGER`, `FLOAT`, `BOOLEAN`, `STRING`, `TEXT`, `JSON`, `BLOB`, `DATETIME`, …
ensure correct PHP↔DB conversion.

### Migrations

Schema lives in `lib/Migration/VersionXXXXXDate*.php` implementing
`OCP\Migration\IMigrationStep` with `changeSchema()`:

```php
namespace OCA\MyApp\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IMigrationStep;
use OCP\Migration\IOutput;

class Version001000Date20240101000000 extends \OCP\Migration\SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('myapp_authors')) {
            $table = $schema->createTable('myapp_authors');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('name', 'string', ['notnull' => true, 'length' => 200]);
            $table->setPrimaryKey(['id']);
        }
        return $schema;
    }
}
```

Migrations run on `occ app:enable` / `occ upgrade` / `occ migrations:migrate <appid>`.

**Oracle constraints:** table names ≤ 27 chars, column names ≤ 30 chars, primary keys
mandatory. Declare supported databases in `info.xml`.

---

## Testing

PHP via **PHPUnit**, JS via **Jest/Karma**. Tests go in `tests/`.

```xml
<!-- phpunit.xml -->
<phpunit bootstrap="../../tests/bootstrap.php"> ... </phpunit>
```

```php
namespace OCA\MyApp\Tests\Service;

class AuthorServiceTest extends \Test\TestCase {
    private $container;

    protected function setUp(): void {
        parent::setUp();                      // REQUIRED — sets up env + cleanup
        $app = new \OCA\MyApp\AppInfo\Application();
        $this->container = $app->getContainer();
    }
}
```

Run:

```bash
phpunit tests/                # or: composer test
npm run test                  # Jest frontend tests
```

- Always extend `\Test\TestCase` and call `parent::setUp()/tearDown()` to avoid
  cross-test side effects (leftover files, filecache rows).
- Resolve classes from the container to verify wiring; mock services by replacing them
  in the container.

See [04-server-development.md](04-server-development.md) for static analysis (Psalm) and
the full how-to-test matrix.
