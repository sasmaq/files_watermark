# App Development

> https://docs.nextcloud.com/server/31/developer_manual/app_development/

How to build a Nextcloud app end-to-end: structure, bootstrapping, metadata, navigation,
dependency management, and translations.

## Table of contents

- [Introduction & app structure](#introduction--app-structure)
- [Bootstrapping (Application class)](#bootstrapping-application-class)
- [App metadata (info.xml)](#app-metadata-infoxml)
- [Navigation & initial state](#navigation--initial-state)
- [Dependency management (Composer)](#dependency-management-composer)
- [Translation setup](#translation-setup)

---

## Introduction & app structure

Apps live in `apps/<appid>/`. Canonical layout:

```
appinfo/
  info.xml              # required metadata
  routes.php            # route definitions
lib/
  AppInfo/Application.php   # bootstrap entry point
  Controller/
  Service/
  Db/                  # Entity + QBMapper
  Migration/           # IMigrationStep
  Listener/            # IEventListener
  Settings/            # ISettings / IIconSection
  BackgroundJob/       # TimedJob / QueuedJob
templates/             # PHP templates
src/                   # Vue/TS/JS source
js/                    # compiled bundles (committed)
css/ | img/ | l10n/
tests/                 # PHPUnit + Jest
composer.json | package.json
```

App IDs: lowercase, no spaces, unique on the App Store. You can scaffold a skeleton via
the [App skeleton generator](https://apps.nextcloud.com/developer/apps/generate) or copy
the `nextcloud/app-tutorial` repo.

---

## Bootstrapping (Application class)

The framework auto-loads `\OCA\<AppNamespace>\AppInfo\Application`. It extends
`OCP\AppFramework\App` and (NC 20+) implements `IBootstrap`, which provides two phases:

- **`register(IRegistrationContext $context)`** — runs first for *all* apps. Only prime
  the DI container here: register services, event listeners, middlewares, capabilities,
  notifiers (via context). No other app/server component is guaranteed ready.
- **`boot(IBootContext $context)`** — runs after all registrations. Safe to query the
  container and run once-per-process code.

```php
<?php
namespace OCA\MyApp\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCA\MyApp\Listener\NodeWrittenListener;

class Application extends App implements IBootstrap {
    public const APP_ID = 'myapp';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerEventListener(NodeWrittenEvent::class, NodeWrittenListener::class);
        // $context->registerService(...);
        // $context->registerCapability(...);
        // $context->registerMiddleware(...);
    }

    public function boot(IBootContext $context): void {
        $context->injectFn(function (\OCP\Notification\IManager $manager): void {
            $manager->registerNotifierService(\OCA\MyApp\Notification\Notifier::class);
        });
    }
}
```

> `appinfo/app.php` is the **deprecated** legacy bootstrap. Use the `Application` +
> `IBootstrap` pattern.

---

## App metadata (info.xml)

`appinfo/info.xml` describes the app to the server and App Store. Key elements:

```xml
<?xml version="1.0"?>
<info xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
      xsi:noNamespaceSchemaLocation="https://apps.nextcloud.com/schema/apps/info.xsd">
    <id>files_watermark</id>                 <!-- = app id = folder name -->
    <name>Files Watermark</name>
    <summary>Apply watermarks to files</summary>
    <description><![CDATA[Longer markdown description shown in the App Store.]]></description>
    <version>1.0.0</version>
    <licence>agpl</licence>
    <author mail="you@example.com" homepage="https://example.com">Your Name</author>

    <namespace>FilesWatermark</namespace>   <!-- enables auto-wiring; PSR-4 root -->
    <category>files</category>              <!-- files, tools, integration, security, ... -->

    <bugs>https://github.com/you/files_watermark/issues</bugs>
    <repository type="git">https://github.com/you/files_watermark.git</repository>
    <website>https://github.com/you/files_watermark</website>
    <documentation>
        <user>https://...</user>
        <admin>https://...</admin>
        <developer>https://...</developer>
    </documentation>

    <screenshot>https://.../screenshot.png</screenshot>

    <dependencies>
        <php min-version="8.1" max-version="8.3"/>
        <nextcloud min-version="31" max-version="31"/>
        <database>sqlite</database>
        <database>mysql</database>
        <database>pgsql</database>
        <lib>imagick</lib>                   <!-- optional/required PHP extensions -->
    </dependencies>

    <types>
        <filesystem/>                        <!-- declares filesystem access -->
    </types>

    <navigations>
        <navigation>
            <name>Files Watermark</name>
            <route>files_watermark.page.index</route>
            <icon>app.svg</icon>
            <order>10</order>
        </navigation>
    </navigations>

    <settings>
        <admin>OCA\FilesWatermark\Settings\AdminSettings</admin>
        <admin-section>OCA\FilesWatermark\Settings\AdminSection</admin-section>
    </settings>

    <background-jobs>
        <job>OCA\FilesWatermark\BackgroundJob\CleanupJob</job>
    </background-jobs>

    <commands>
        <command>OCA\FilesWatermark\Command\ApplyAll</command>  <!-- occ commands -->
    </commands>

    <repair-steps>
        <post-migration>
            <step>OCA\FilesWatermark\Migration\SomeRepairStep</step>
        </post-migration>
    </repair-steps>

    <commands/>
</info>
```

Notes:
- `<version>` must match the release tag you publish.
- `<namespace>` is required for auto-wiring and short-form routes; it is the segment after
  `OCA\` in your PHP namespace.
- `<dependencies>` gates installation; declare PHP/NC version ranges, databases, and
  required libs/extensions.
- A `<changelog>`/`CHANGELOG.md` may be referenced for App Store release notes.

---

## Navigation & initial state

- Add a left-sidebar entry via `<navigations>` in `info.xml` (as above), or
  programmatically via `OCP\INavigationManager`.
- Pass server-side data to the frontend with **initial state**:

```php
// PHP (in a controller or bootstrap)
$this->initialState->provideInitialState('config', $configArray);
```

```js
// JS
import { loadState } from '@nextcloud/initial-state'
const config = loadState('files_watermark', 'config')
```

- Register **file actions** (context-menu entries) from your Files script using
  `@nextcloud/files` `registerFileAction(new FileAction({...}))` — see
  [07-client-apis.md](07-client-apis.md) and the project's `main-files.js`.

---

## Dependency management (Composer)

- PHP deps via Composer; commit `composer.json` + `composer.lock`.
- Provide an autoloader: Nextcloud expects `vendor/autoload.php` (or use the app
  class-loading + a generated `composer/autoload_classmap.php`).
- Keep runtime dependencies minimal; bundle them in the released tarball (the App Store
  package must be self-contained).

```bash
composer install --no-dev -o     # production install, optimized autoloader
```

---

## Translation setup

- Wrap user-facing strings:
  - PHP: inject `OCP\IL10N` → `$this->l->t('Hello %s', [$name])`.
  - Templates: `<?php p($l->t('Hello')); ?>`.
  - JS/Vue: `import { translate as t } from '@nextcloud/l10n'` → `t('files_watermark', 'Hello')`.
- Translatable strings are extracted into `l10n/` (`.pot`/`.js`/`.json`).
- The Nextcloud Transifex bot syncs translations if you opt in (`.tx/config`); otherwise
  manage `l10n/` manually.

See [02-basic-concepts.md](02-basic-concepts.md) for controllers/routing used by the page,
and [06-app-publishing.md](06-app-publishing.md) for packaging and release.
