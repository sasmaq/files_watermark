# Nextcloud Developer Manual (Server 31)

A condensed, working reference for Nextcloud 31 app development, compiled from the
official [Developer Manual](https://docs.nextcloud.com/server/31/developer_manual/).
This is tailored for building server apps like **files_watermark** (PHP App Framework
backend + Vue 3 frontend).

> Source of truth: https://docs.nextcloud.com/server/31/developer_manual/
> These notes summarize the manual; consult the live docs for edge cases.

## Documents in this folder

| File | Covers |
|------|--------|
| [01-getting-started.md](01-getting-started.md) | Dev process, environment, coding style |
| [02-basic-concepts.md](02-basic-concepts.md) | Request lifecycle, routing, DI, controllers, middlewares, events, background jobs, caching, logging, settings, storage/DB, testing |
| [03-app-development.md](03-app-development.md) | App structure, bootstrapping, `info.xml` metadata, navigation, dependency management, translations |
| [04-server-development.md](04-server-development.md) | Front-end/back-end build, static analysis, unit testing, filesystem API, how-to-test matrix |
| [05-digging-deeper.md](05-digging-deeper.md) | Config/preferences, files metadata, HTTP client, notifications, security, search, repair steps, and the full topic index |
| [06-app-publishing.md](06-app-publishing.md) | Release process, App Store, code signing, release automation, upgrade guides |
| [07-client-apis.md](07-client-apis.md) | OCS API, WebDAV, Files, Login Flow, Activity, Remote Wipe |

## Top-level manual structure

1. **Prologue** — code of conduct, communication, bugtracker, security guidelines, ecosystem compatibility
2. **Getting started** — development process, environment, coding style → [01](01-getting-started.md)
3. **Basic concepts** — the App Framework essentials → [02](02-basic-concepts.md)
4. **App development** — building an app end-to-end → [03](03-app-development.md)
5. **ExApp development** — external apps (AppAPI / out-of-process apps)
6. **Server development** — contributing to / building the server → [04](04-server-development.md)
7. **Digging deeper** — 40+ specialized APIs → [05](05-digging-deeper.md)
8. **App publishing and maintenance** — releasing & maintaining → [06](06-app-publishing.md)
9. **Interface & interaction design** — UX foundations, layout, components
10. **HTML/CSS guidelines** — markup and styling conventions
11. **Clients and Client APIs** — OCS, WebDAV, mobile/desktop client APIs → [07](07-client-apis.md)

## Quick orientation for app authors

- An app lives in `apps/<appid>/` with this canonical layout:
  ```
  appinfo/info.xml          App metadata (required)
  appinfo/routes.php        Route definitions
  lib/AppInfo/Application.php  Bootstrap (App + IBootstrap)
  lib/Controller/           Controllers
  lib/Service/              Business logic
  lib/Db/                   Entities + QBMapper
  lib/Migration/            IMigrationStep schema migrations
  lib/Listener/             IEventListener classes
  lib/Settings/             ISettings / ISection
  lib/BackgroundJob/        TimedJob / QueuedJob
  templates/                PHP templates
  src/                      Vue/JS source (built into js/ via webpack)
  js/                       Compiled assets (committed)
  tests/                    PHPUnit + Jest tests
  ```
- Use **dependency injection** everywhere; prefer auto-wiring (`<namespace>` in info.xml).
- Use the **OCP** namespace for public, stable APIs. `OC`/`OCA\<App>` is internal.
- Use **typed events** (`OCP\EventDispatcher`) — hooks/emitters are deprecated.
