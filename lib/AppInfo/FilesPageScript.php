<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\AppInfo;

/**
 * Decides which requests must load the Files integration bundle *early* - meaning ahead
 * of the Files app's own script rather than after it.
 *
 * ---------------------------------------------------------------------------
 * WHY EARLY IS NOT A PREFERENCE.
 *
 * The Files client builds its PROPFIND payload from the DAV properties registered at
 * the moment it fetches a directory, and it fetches from the `mounted()` hook of a
 * component mounted while `files-main.js` executes. Every Nextcloud script tag carries
 * `defer`, so scripts run in document order, and this app's bundle used to be added from
 * `LoadAdditionalScriptsEvent` - which the Files ViewController dispatches *after* it has
 * added its own script.
 *
 * The consequence was not a rare race, it was every page load: the first listing came
 * back with no `is-watermarked` property on any node, so every row rendered as
 * un-watermarked. Apply was offered on files that are watermarked, Remove was missing
 * from exactly the rows that need it, and no badge was drawn - until the user navigated
 * to another folder, whose PROPFIND is the second one and does carry the property.
 *
 * {@see Application::boot()} is the only hook that runs before the Files controller, and
 * Nextcloud emits scripts grouped by app in the order each app first asks for one
 * ({@see \OC\AppScriptSort}). So asking here puts the whole bundle - the property
 * registration, the two file actions and the listing subscription - in front of the Files
 * app, and the first render is decided with the real status instead of a guess.
 *
 * **The cost is stated rather than hidden:** the Files UI now waits on this app's bundle
 * before its own runs. It is a bundle that already loads on every one of these pages, so
 * this moves work rather than adding it, and it buys a first render that is correct.
 * ---------------------------------------------------------------------------
 *
 * Kept apart from `Application` so the rule is a pure function with tests, rather than a
 * condition buried in a bootstrap method no test can reach.
 */
final class FilesPageScript {

	/** The bundle under `js/`, named as {@see \OCP\Util::addScript()} wants it. */
	public const SCRIPT = 'files';

	/**
	 * Whether the request at $pathInfo renders the Files UI, and so needs this app's
	 * bundle in place before that UI starts fetching.
	 *
	 * **Every file list lives under `/apps/files`,** including the ones that read like
	 * other apps: Shared with you is `/apps/files/sharingin` and the trashbin is
	 * `/apps/files/trashbin`. One prefix covers them all.
	 *
	 * The prefix is matched with its separator (`/apps/files/`, or the bare
	 * `/apps/files`), which is what keeps this app's *own* API out - the eleven
	 * characters of `/apps/files_watermark/api/v1/...` start the same way and render no
	 * HTML at all. `/apps/files_sharing/...` is excluded on the same rule and rightly so:
	 * a public link page loads a *different* bundle, and by a different rule -
	 * {@see wantedForPublicShare()}.
	 *
	 * Everything else - the dashboard, settings, DAV, OCS, cron - gets nothing. Loading a
	 * bundle ahead of a page's own code is worth doing where it fixes what the page
	 * shows, and worth avoiding everywhere else.
	 *
	 * @param string|null $pathInfo `IRequest::getPathInfo()`, or null when there is none
	 *                              (CLI, or a request whose URI could not be processed)
	 */
	public static function wantedFor(?string $pathInfo): bool {
		if ($pathInfo === null || $pathInfo === '') {
			return false;
		}

		// getPathInfo() is documented to return the path below the script name, but not
		// to guarantee a leading slash on every server; normalising here keeps the prefix
		// test from depending on that.
		$path = '/' . ltrim($pathInfo, '/');

		return $path === '/apps/files' || str_starts_with($path, '/apps/files/');
	}

	/** The public share page's bundle - the badge and nothing else. */
	public const PUBLIC_SCRIPT = 'public';

	/**
	 * Whether the request at $pathInfo renders a **public share page**, which needs the
	 * badge bundle in place before the file list starts fetching.
	 *
	 * ---------------------------------------------------------------------------
	 * THE SAME RACE AS ABOVE, ON A PAGE WHERE IT NEVER RECOVERS.
	 *
	 * `PublicShareScriptsListener` asks for this bundle from `files_sharing`'s own
	 * template event, which - exactly like the Files app's - fires *after* the page has
	 * added `files-main.js`. Nextcloud emits scripts grouped by app in the order each app
	 * first asks for one, so the bundle landed behind the file list's, `registerDavProperty`
	 * ran after the PROPFIND had been built, and no node in the listing carried
	 * `is-watermarked`.
	 *
	 * On a folder page that is the familiar "first listing is wrong" symptom: navigate into
	 * a subfolder and the badges appear. **On a single-file share there is no second
	 * listing** - the view fetches its one node once - so the badge simply never appeared
	 * at all. That is the bug this method fixes, and it is why the fix is an ordering
	 * change rather than anything to do with single-file pages specifically: NC 31 renders
	 * a single-file share through the same file list, as the `public-file-share` view.
	 * ---------------------------------------------------------------------------
	 *
	 * **Only the page itself.** `/s/{token}/download` and the public DAV and preview
	 * endpoints serve bytes, not HTML, and adding a script to them is work that produces
	 * nothing. One segment after `/s/` is the page; anything deeper is not.
	 *
	 * @param string|null $pathInfo `IRequest::getPathInfo()`, or null when there is none
	 */
	public static function wantedForPublicShare(?string $pathInfo): bool {
		if ($pathInfo === null || $pathInfo === '') {
			return false;
		}

		$path = '/' . ltrim($pathInfo, '/');
		// A trailing slash is the same page; anything else after the token is not.
		$path = rtrim($path, '/');

		if (!str_starts_with($path, '/s/')) {
			return false;
		}

		return !str_contains(substr($path, 3), '/') && substr($path, 3) !== '';
	}
}
