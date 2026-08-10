#!/bin/sh
#
# Enables files_watermark on the Docker dev instances, so `docker compose up -d` is the
# whole of "start the app" and there is no hand-run occ step to forget between a fresh
# `down -v` and wondering why the settings page is missing.
#
# Mounted into the Nextcloud image's `before-starting` hook directory by both
# `docker-compose.yml` and `docker-compose.s3.yml`. The image's entrypoint runs every
# executable `*.sh` in there **as www-data** on every container start, after the first-run
# install and after `occ upgrade` - which is the only point where occ is guaranteed usable.
# `before-starting` rather than `post-installation` because the latter fires only on the
# very first boot: a later `docker compose up` on a kept volume would skip it, and so would
# every restart after somebody disabled the app to test something.
#
# It is a development convenience and nothing else. Nothing here runs in production, where
# enabling an app is a deliberate act - see the README's Installation section.
set -u

APP_ID="files_watermark"
APP_DIR="/var/www/html/custom_apps/$APP_ID"
OCC="php /var/www/html/occ"

log() {
	echo "[$APP_ID] $*"
}

# ---------------------------------------------------------------------------
# THIS SCRIPT NEVER FAILS THE BOOT.
#
# `run_path()` in the image's entrypoint aborts start-up when a hook exits non-zero, so a
# bad exit code here does not mean "the app is not enabled", it means the instance does not
# come up at all. That is a terrible trade for a convenience: an app that will not enable is
# a reason to read the log, not a reason to lose the instance you would read it on. Every
# path below ends at `exit 0` and says what it found instead.
# ---------------------------------------------------------------------------

if [ ! -f "$APP_DIR/appinfo/info.xml" ]; then
	log "not mounted at $APP_DIR - skipping (is the bind mount in the compose file intact?)"
	exit 0
fi

# Enabling the app without its dependencies breaks *the instance*, not just the app: the
# bootstrap resolves classes out of `vendor/` on every request, so a missing autoloader is
# fatal on pages that have nothing to do with watermarking. Refusing here keeps a forgotten
# build step to a warning about the app.
if [ ! -f "$APP_DIR/vendor/autoload.php" ]; then
	log "vendor/ is missing - run 'composer install' on the host, then restart the container"
	exit 0
fi

if ! $OCC status 2>/dev/null | grep -q 'installed: true'; then
	log "Nextcloud is not installed yet - finish the web installer, then restart the container"
	exit 0
fi

if $OCC app:enable "$APP_ID"; then
	# Already-enabled is success and prints the same line, which is what makes this safe to
	# run on every start.
	log "ready"
else
	log "WARNING: could not enable the app - the output above says why. The instance is up regardless."
fi

# The backend is fine without these; the settings page and the file action are not. Checked
# after enabling rather than before, because an unbuilt frontend is a broken *screen*, not a
# broken instance, and the rest is worth having in the meantime.
if [ ! -f "$APP_DIR/js/admin-settings.js" ] || [ ! -f "$APP_DIR/js/files.js" ]; then
	log "WARNING: js/ is not built - run 'npm install && npm run build' on the host, then hard-refresh"
fi

exit 0
