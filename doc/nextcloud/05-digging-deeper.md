# Digging Deeper

> https://docs.nextcloud.com/server/31/developer_manual/digging_deeper/

Specialized APIs beyond the framework basics. The full topic index is at the bottom;
the sections below detail the ones most relevant to apps like files_watermark.

## Table of contents

- [Config & Preferences](#config--preferences)
- [Files Metadata](#files-metadata)
- [HTTP Client](#http-client)
- [Security](#security)
- [Notifications](#notifications)
- [Settings (declarative)](#settings-declarative)
- [Repair steps](#repair-steps)
- [Search](#search)
- [REST APIs / CORS](#rest-apis--cors)
- [Email](#email)
- [Full topic index](#full-topic-index)

---

## Config & Preferences

Two modern, type-safe services (NC 29+) replace much of the older `IConfig`:

- **`IAppConfig`** — app-wide config values (admin scope).
- **`IUserConfig` / user preferences** — per-user values.

```php
use OCP\IAppConfig;

public function __construct(private IAppConfig $appConfig) {}

$this->appConfig->setValueString('files_watermark', 'default_template', '{user} {date}');
$tmpl = $this->appConfig->getValueString('files_watermark', 'default_template', '');
// typed variants: getValueBool/Int/Float/Array, setValueBool/Int/...
// mark a value sensitive (masked in occ output) / lazy-loaded as needed
```

Legacy: `OCP\IConfig` (`getAppValue`/`setAppValue`, `getUserValue`/`setUserValue`) is
still available. System config (`config.php`) via `IConfig::getSystemValue(...)`.

> **files_watermark note:** config resolution order is user config → global app config →
> built-in defaults; implement that fallback explicitly in `WatermarkService`.

---

## Files Metadata

> https://docs.nextcloud.com/server/31/developer_manual/digging_deeper/files-metadata.html
> (Added in NC 28.) Manage per-file metadata with WebDAV integration.

`OCP\FilesMetadata\IFilesMetadataManager`:

- `refreshMetadata(Node $node, int $process)` — trigger a refresh.
- `getMetadata(int $fileId, bool $generate)` → `IFilesMetadata`.
- `saveMetadata(IFilesMetadata $metadata)` — persist + build indexes.
- `deleteMetadata(int $fileId)`.
- `initMetadata(string $key, string $type, bool $indexed, bool $remotelyEditable)` —
  pre-register a key (call before files are examined, e.g. in `boot()`).

Generate metadata via two events:

- **`MetadataLiveEvent`** — fires on the main process after upload/modify (fast work).
- **`MetadataBackgroundEvent`** — fires from cron for heavy work.

```php
// register both to the same listener
$context->registerEventListener(MetadataLiveEvent::class, UpdateFilesMetadata::class);
$context->registerEventListener(MetadataBackgroundEvent::class, UpdateFilesMetadata::class);

public function handle(Event $event): void {
    $metadata = $event->getMetadata();
    $metadata->setString('my-key', 'value');
    if ($event instanceof MetadataLiveEvent) {
        $event->requestBackgroundJob();   // defer heavy processing
    }
}
```

WebDAV exposure uses the `nc:metadata-` prefix (PROPFIND/PROPPATCH/SEARCH). Indexed
metadata is queryable via `IMetadataQuery` (`joinIndex()`, `getMetadataValueField()`).

---

## HTTP Client

> Use `OCP\Http\Client\IClientService` — it honors proxy config and blocks SSRF to
> internal hosts automatically.

```php
use OCP\Http\Client\IClientService;

public function __construct(private IClientService $clientService) {}

public function fetch(): string {
    $client = $this->clientService->newClient();
    $response = $client->get('https://example.com');
    return $response->getBody();
}

$response = $client->post('https://api.example.tld/endpoint', [
    'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
    'body'    => json_encode(['key' => 'value']),
    'timeout' => 30,
]);
```

Also supports `head/put/delete/options`. Errors surface as exceptions — wrap in
try/catch. The client is synchronous.

---

## Security

> https://docs.nextcloud.com/server/31/developer_manual/digging_deeper/security.html

### Rate limiting

For controllers use `#[UserRateLimit]`/`#[AnonRateLimit]`
(see [02-basic-concepts.md](02-basic-concepts.md#rate-limiting--brute-force-protection)).
For non-controller code (DAV plugins, etc.) inject `OCP\Security\RateLimiting\ILimiter`:

```php
use OCP\Security\RateLimiting\ILimiter;

try {
    $this->limiter->registerAnonRequest('my-plugin-anon', 5, 60 * 60); // 5/hour
} catch (\OCP\Security\RateLimiting\IRateLimitExceededException $e) {
    // return HTTP 429
}
// $this->limiter->registerUserRequest('action', $limit, $period, $user);
```

### SSRF / remote host validation

```php
use OCP\Security\IRemoteHostValidator;
if (!$this->hostValidator->isValid($hostname)) { return; }   // block internal targets
```
(The `IClientService` already applies this.)

### Trusted domains / URLs

```php
$helper->isTrustedUrl('https://localhost/nextcloud/index.php/apps/files/');
$helper->isTrustedDomain('example.tld:8443');
```

### Other security building blocks

- **CSRF:** enforced by default; only disable per-action with `#[NoCSRFRequired]` for
  non-browser APIs.
- **Content Security Policy:** build with
  `OCP\AppFramework\Http\ContentSecurityPolicy`, attach via
  `$response->setContentSecurityPolicy($csp)`. Default policy is strict; relax minimally.
- **Input validation/escaping:** escape output in templates (`p()` escapes, `print_unescaped()`
  does not). Sanitize any watermark template tokens before rendering to avoid XSS in the
  settings UI.
- **Secrets:** hash with `OCP\Security\IHasher`; encrypt with
  `OCP\Security\ICrypto`; generate tokens with `OCP\Security\ISecureRandom`;
  store credentials with `OCP\Security\ICredentialsManager`.

---

## Notifications

> Core API lives in the official Notifications app:
> https://github.com/nextcloud/notifications#developers

Flow:
- Inject `OCP\Notification\IManager`; create with `$manager->createNotification()`.
- Populate `INotification` (`setApp`, `setUser`, `setObject`, `setSubject`,
  `setDateTime`, add actions), then `$manager->notify($notification)`.
- Implement `OCP\Notification\INotifier` to render notifications (human-readable subject,
  icon, actions) and register it in `boot()` via
  `$manager->registerNotifierService(Notifier::class)`.

```php
$n = $this->manager->createNotification();
$n->setApp('files_watermark')
  ->setUser($uid)
  ->setDateTime(new \DateTime())
  ->setObject('file', (string)$fileId)
  ->setSubject('watermarked', ['name' => $fileName]);
$this->manager->notify($n);
```

---

## Settings (declarative)

Beyond the `ISettings`/`IIconSection` template approach
([02-basic-concepts.md](02-basic-concepts.md#settings)), NC offers **declarative
settings**: define a schema (fields, types, defaults, storage = internal/external) and the
server renders & persists the form for you — no Vue/template needed for simple forms.
Register a class implementing `OCP\Settings\IDeclarativeSettingsForm` (or provide the
schema via the dedicated event) describing `id`, `section_type`, `priority`, and `fields`.

---

## Repair steps

Run maintenance logic during `occ maintenance:repair` / upgrades. Implement
`OCP\Migration\IRepairStep`:

```php
class MigrateLegacyConfig implements \OCP\Migration\IRepairStep {
    public function getName(): string { return 'Migrate legacy watermark config'; }
    public function run(\OCP\Migration\IOutput $output): void { /* ... */ }
}
```

Register in `info.xml` under `<repair-steps><post-migration>` (or `<pre-migration>`,
`<install>`, `<uninstall>`).

---

## Search

Provide unified-search results by implementing `OCP\Search\IProvider`
(`getId`, `getName`, `getOrder`, `search(IUser, ISearchQuery): SearchResult`). Register it
as a service (auto-discovered) so results appear in the global search bar.

---

## REST APIs / CORS

For APIs consumed by external clients, extend `OCP\AppFramework\ApiController` and add CORS
support: annotate actions with `#[CORS]`, add an `OPTIONS` preflight route, and apply
`#[NoCSRFRequired]`. The framework injects the proper `Access-Control-*` headers.

---

## Email

Send mail via `OCP\Mail\IMailer`:

```php
$message = $this->mailer->createMessage();
$message->setTo([$address => $name]);
$message->setSubject('Subject');
$message->setHtmlBody('<p>Hello</p>');
$message->setPlainBody('Hello');
// inline attachments via $this->mailer->createAttachment(...) / createEmbeddedFile(...)
$this->mailer->send($message);
```

Prefer `IEMailTemplate` for branded, consistent emails.

---

## Full topic index

| Topic | Page | Description |
|-------|------|-------------|
| API reference | `api.html` | Public & unstable PHP APIs |
| Config & Preferences | `config/index.html` | `IAppConfig` / `IUserConfig` |
| Classloader | `classloader.html` | Server & app autoloading |
| Continuous Integration | `continuous_integration.html` | Linting & static analysis |
| Dashboard | `dashboard.html` | Dashboard widgets |
| Deadlocks | `deadlock.html` | DB locking pitfalls |
| Debugging | `debugging.html` | Troubleshooting tools |
| Email | `email.html` | Sending mail + attachments |
| Files Metadata | `files-metadata.html` | Per-file metadata API |
| Groupware integration | `groupware/index.html` | Calendar/contacts/mail |
| HTTP Client | `http_client.html` | Outbound HTTP requests |
| JavaScript APIs | `javascript-apis.html` | npm packages, frontend libs |
| Machine Translation | `translation.html` | Translation providers |
| Nextcloud Flow | `flow.html` | Workflow automation |
| NPM | `npm.html` | Build/dev/test/lint commands |
| Notifications | `notifications.html` | User notifications |
| Out-of-office | `out_of_office.html` | Absence data/events |
| Performance | `performance.html` | PHP/DB/cache optimization |
| Phone number util | `phonenumberutil.html` | Number formatting |
| PSR | `psr.html` | PSR compliance |
| Profile | `profile.html` | Profile actions |
| Profiler | `profiler.html` | Performance profiling |
| Projects | `projects.html` | Resource providers |
| Public Pages | `publicpage.html` | Public/share pages |
| Reference providers | `reference.html` | Link previews / smart picker |
| Repair steps | `repair.html` | Maintenance steps |
| REST APIs | `rest_apis.html` | CORS configuration |
| Search | `search.html` | Search providers |
| Security | `security.html` | Rate limiting, validation, domains |
| Settings | `settings.html` | Declarative & form settings |
| Setup checks | `setup_checks.html` | Server health checks |
| Speech-To-Text | `speech-to-text.html` | Audio transcription |
| Talk Integration | `talk.html` | Talk/video features |
| Task Processing | `task_processing.html` | Async task framework (AI) |
| Text Processing | `text_processing.html` | AI text analysis |
| Text-To-Image | `text2image.html` | Image generation |
| Two-factor providers | `two-factor-provider.html` | Custom 2FA |
| User Status | `status.html` | Availability status |
| User migration | `user_migration.html` | Account data transfer |
| User management | `users.html` | Users & sessions |
| Web Host Metadata | `web_host_metadata.html` | Discovery protocol |
| Working with time | `time.html` | `ITimeFactory`, time utils |
