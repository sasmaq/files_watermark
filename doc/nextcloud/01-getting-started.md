# Getting Started

> https://docs.nextcloud.com/server/31/developer_manual/getting_started/

The Getting Started section is an entry point with three areas: development process,
development environment, and coding style/guidelines.

## Development process

- Nextcloud uses **Git** for version control; the server and apps live on GitHub
  (`github.com/nextcloud`).
- When contributing, **choose the right target branch**. Bug fixes typically target
  the current stable branch (e.g. `stable31`) and `master`; new features target `master`.
- Follow the pull-request workflow: fork, branch, commit (with sign-off / DCO),
  open a PR, pass CI, get review.

## Development environment

To run a local instance you need a web server + database + the source code:

- **Web server:** Apache (with `mod_php` or php-fpm) or nginx.
- **PHP:** a version supported by NC 31 (PHP 8.1–8.3 range).
- **Database:** MySQL/MariaDB, PostgreSQL, or SQLite (SQLite for dev only).
- **Source:** clone the server, or for app dev run a server and drop your app into `apps/`.

For files_watermark the project already uses Docker:

```bash
# Run a Nextcloud 31 instance
docker run -d -p 8080:80 nextcloud:31.0.14-apache

# Symlink/copy the app into the container's apps/ directory, then:
occ app:enable files_watermark
occ migrations:migrate files_watermark   # if tables are missing
```

Useful `occ` commands during development:

```bash
occ app:enable <appid>           # enable an app (runs migrations)
occ app:disable <appid>
occ maintenance:repair           # run repair steps
occ config:system:set debug --value true --type boolean   # verbose errors
occ log:tail                     # follow the log
```

## Coding style & general guidelines

- **PHP:** follow the Nextcloud coding standard (PSR-12 derived). Enforced via
  `nextcloud/coding-standard` and `php-cs-fixer`.
- **Naming/labelling:** consistent, descriptive class and method names; controllers in
  `lib/Controller`, services in `lib/Service`, etc.
- **License headers:** every source file must carry a proper SPDX/license header
  (AGPL-3.0-or-later for most Nextcloud apps).
- **UI considerations:** follow the design guidelines and `@nextcloud/vue` components for
  a consistent look.
- **Public vs internal API:** only depend on the `OCP\*` namespace. Anything in `OC\*` is
  private and may change without notice.

See also: [03-app-development.md](03-app-development.md) for the app skeleton and
[04-server-development.md](04-server-development.md) for build/test tooling.
