# App Publishing & Maintenance

> https://docs.nextcloud.com/server/31/developer_manual/app_publishing_maintenance/

How to release, sign, distribute, and maintain an app on the Nextcloud App Store.

## Maintainer responsibilities

- Keep the app working across supported NC versions; update `max-version` after testing
  against new releases.
- Track issues, respond to security reports per the security guidelines, and keep
  dependencies current.

## Release process

Transform source into a deliverable package:

1. **Bump the version** in `appinfo/info.xml` `<version>` to match the release tag
   (semver).
2. **Build & commit frontend assets**: `npm run build` → commit `js/`.
3. **Install production PHP deps**: `composer install --no-dev -o`.
4. **Package** the app folder into a tarball, excluding dev files:
   ```bash
   tar -czf files_watermark.tar.gz \
       --exclude='.git' --exclude='node_modules' --exclude='tests' \
       --exclude='src' files_watermark/
   ```
   The package must be **self-contained** (vendored deps, compiled assets) and run with no
   build step. A `.nextcloudignore` file controls what `krankerl`/`nextcloud-cli` excludes.
5. **Tag the release**: `git tag v1.0.0 && git push --tags`.

Tooling that automates packaging: **krankerl** and the **nextcloud/cli** /
`appstore-build-publish` GitHub Action.

## Publishing on the App Store

- Register/login at https://apps.nextcloud.com with a Nextcloud account.
- Upload by providing a **download URL** to the signed tarball plus a **signature**;
  the store validates the signature against your registered certificate.
- App must satisfy the store **rules & guidelines** (valid `info.xml`, license, no
  malicious code, appropriate category, screenshots, description).
- Each app version declares its NC compatibility range; the store serves the right version
  per server.

## Code signing

Required for App Store visibility and install integrity:

1. Generate a key/CSR and request a certificate from Nextcloud
   (`occ integrity:sign-app` workflow / nextcloud/app-certificate-requests repo).
2. Sign the app: produces a `appinfo/signature.json` covering file hashes.
   ```bash
   occ integrity:sign-app \
       --path=/path/to/files_watermark \
       --privateKey=~/.nextcloud/certificates/files_watermark.key \
       --certificate=~/.nextcloud/certificates/files_watermark.crt
   ```
3. The store and servers verify the signature; mismatches raise integrity warnings.

## Release automation

Use **GitHub Actions** to build, sign, and publish on tag push (the
`nextcloud/appstore-build-publish` action). Store the signing key and App Store token as
encrypted repository secrets so tagging a release ships it automatically.

## Monetizing apps

- Accept **donations** (link in `info.xml`/App Store).
- Offer **enterprise support** contracts. (No paid App Store gating; distribution is free.)

## App upgrade guide

Per-version migration notes exist for NC 15 → 31. When raising your supported
`max-version`:

- Review the upgrade guide for that version for removed/deprecated APIs.
- Replace deprecated patterns (e.g. `app.php` → `IBootstrap`, hooks → typed events,
  `IConfig` app values → `IAppConfig`).
- Run Psalm and the test suite against the new server; verify migrations on MySQL,
  PostgreSQL, and SQLite.
- Bump `<dependencies><nextcloud max-version="..."/>` and release a new version.

See [03-app-development.md](03-app-development.md) for `info.xml` and
[04-server-development.md](04-server-development.md) for the build/test tooling referenced
above.
