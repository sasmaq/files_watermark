# Server Development

> https://docs.nextcloud.com/server/31/developer_manual/server/

Tooling and conventions for building front-end/back-end code, static analysis, testing,
the filesystem API, and the "how to test" environment matrix. Most of this also applies
to app development.

## Front-end code

- Vue components and scripts are built with **webpack** (`@nextcloud/webpack-vue-config`).
- Styles via SCSS; use `@nextcloud/vue` components and the global CSS variables for
  theming consistency.
- Build commands (typical app `package.json`):

```bash
npm ci                     # install (use --legacy-peer-deps if needed)
npm run dev                # development build
npm run watch              # rebuild on change
npm run build              # production build into js/
npm run lint               # eslint
npm run stylelint
```

- **Commit the compiled assets** (`js/`) for released apps — the App Store package must
  run without a build step.

## Back-end code

- PHP App Framework (see [02-basic-concepts.md](02-basic-concepts.md)).
- Public API surface is the `OCP\*` namespace. `OC\*` is private/internal.
- Maintain compatibility across supported NC versions declared in `info.xml`; check the
  upgrade guides ([06-app-publishing.md](06-app-publishing.md)) when bumping `max-version`.

## Static analysis

- **Psalm** for type-level static analysis (`psalm.xml`, `composer psalm` /
  `vendor/bin/psalm`).
- **php-cs-fixer** + `nextcloud/coding-standard` for code style
  (`composer cs:check` / `composer cs:fix`).
- **ESLint** + **Stylelint** for JS/Vue/CSS.

```bash
composer cs:check
composer psalm
npm run lint
```

## Unit testing

- **PHP:** PHPUnit, tests in `tests/`, extend `\Test\TestCase`, bootstrap via
  `tests/bootstrap.php`. Run `phpunit tests/` or `composer test`.
- **JS:** Jest (`npm run test`) or Karma for browser tests.
- Resolve classes through the DI container to verify wiring; replace services in the
  container to mock. (Details in [02-basic-concepts.md](02-basic-concepts.md#testing).)

## Nextcloud filesystem API

Operate on files through the **Node API**, not raw paths:

- `OCP\Files\IRootFolder` → entry point to the virtual filesystem.
- `IRootFolder->getUserFolder($uid)` → a user's home `Folder`.
- `Folder` / `File` nodes (`OCP\Files\Node`): `get()`, `newFile()`, `newFolder()`,
  `getContent()`, `putContent()`, `copy()`, `move()`, `delete()`, `getId()`,
  `getMimeType()`, `getPath()`.

```php
use OCP\Files\IRootFolder;

public function __construct(private IRootFolder $rootFolder) {}

public function readUserFile(string $uid, string $path): string {
    $userFolder = $this->rootFolder->getUserFolder($uid);
    /** @var \OCP\Files\File $node */
    $node = $userFolder->get($path);
    return $node->getContent();
}
```

This abstracts local, S3/object, SMB, and other external storage backends transparently —
so a watermark download controller that uses the Node API works on S3-backed instances
without changes.

App-private scratch storage: `OCP\Files\IAppData` (`getAppDataDir()`), useful for caches
and generated artifacts that shouldn't appear in user folders.

## External API

- Expose OCS endpoints with `OCSController` + an `ocs` block in `routes.php`
  (served at `/ocs/v2.php/apps/<appid>/...`). See [07-client-apis.md](07-client-apis.md).
- For REST APIs needing CORS, use `ApiController` and the CORS attributes/middleware
  (see [05-digging-deeper.md](05-digging-deeper.md#rest-apis--cors)).

## How to test (environment matrix)

The manual documents how to stand up dependencies for realistic testing:

- **Email** — local SMTP (e.g. MailHog) for mail features.
- **Redis / Redis Cluster** — distributed caching & file locking.
- **S3** — as primary storage and as external storage (relevant for the watermark
  download path).
- **SMB** — external Windows/Samba storage.
- **SAML** — SSO authentication.
- **Collabora / OnlyOffice** — office document integration.
- **WebAuthn** — hardware-key/passkey auth.

For files_watermark, the most relevant are the **S3 (primary & external)** setups to
verify watermark generation and download against object storage.
