<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\AppInfo;

use OCA\FilesWatermark\AppInfo\FilesPageScript;
use PHPUnit\Framework\TestCase;

/**
 * Which requests load the Files integration bundle early.
 *
 * The rule exists because of *when* it is asked, not what it matches: `boot()` runs
 * before the request is routed, so a path is the only thing available to decide with -
 * and getting the decision wrong is what put this app's bundle behind the Files app's
 * and left every first listing without a watermark status. See {@see FilesPageScript}.
 */
class FilesPageScriptTest extends TestCase {

	/**
	 * @dataProvider pathProvider
	 */
	public function testWantedForPath(?string $pathInfo, bool $expected, string $why): void {
		$this->assertSame($expected, FilesPageScript::wantedFor($pathInfo), $why);
	}

	/** @return array<string, array{string|null, bool, string}> */
	public static function pathProvider(): array {
		return [
			'the Files app itself' => ['/apps/files', true, 'the bare app path renders the file list'],
			'a Files view' => ['/apps/files/files', true, 'the default view'],
			'a Files view with a trailing slash' => ['/apps/files/', true, 'a trailing slash is still the Files app'],
			// Every file list is a view inside the Files app, whatever app named it:
			// a listener matching on app ids would miss both of these.
			'shared with you' => ['/apps/files/sharingin', true, 'shares are a Files view'],
			'the trashbin' => ['/apps/files/trashbin', true, 'the trashbin is a Files view'],
			'no leading slash' => ['apps/files/files', true, 'a path info without its leading slash still matches'],

			// The prefix has to be matched with its separator. This app's own API shares
			// the first eleven characters with the Files app's path and renders no HTML,
			// so a plain str_starts_with('/apps/files') would load a bundle into a JSON
			// response on every status lookup the file list makes.
			'this app\'s API' => ['/apps/files_watermark/api/v1/watermarked', false, 'an API call renders no page'],
			'this app\'s apply endpoint' => ['/apps/files_watermark/api/v1/apply', false, 'an API call renders no page'],
			'another files_ app' => ['/apps/files_sharing/publicpreview/abc', false, 'public pages never load these bundles'],

			'the dashboard' => ['/apps/dashboard', false, 'no file list, no reason to load first'],
			'settings' => ['/settings/admin/files_watermark', false, 'the admin page loads its own bundle'],
			'a DAV request' => ['/remote.php/dav/files/alice/report.pdf', false, 'not an HTML page'],
			'the root' => ['/', false, 'core redirects to the default app, and that request matches instead'],
			'an empty path' => ['', false, 'nothing to classify'],
			'no path at all' => [null, false, 'CLI and unparseable URIs have no page to load into'],
		];
	}

	/**
	 * The public share page's own rule.
	 *
	 * Same reason as above and one difference that matters: a public *file* share fetches
	 * its single node exactly once, so a bundle that arrives late is a badge that never
	 * appears at all, rather than one that appears after the next navigation.
	 *
	 * @dataProvider publicPathProvider
	 */
	public function testWantedForPublicShare(?string $pathInfo, bool $expected, string $why): void {
		$this->assertSame($expected, FilesPageScript::wantedForPublicShare($pathInfo), $why);
	}

	/** @return array<string, array{string|null, bool, string}> */
	public static function publicPathProvider(): array {
		return [
			'a share page' => ['/s/RWY9iz2wR4xN3ZJ', true, 'the page a link visitor lands on'],
			'with a trailing slash' => ['/s/abc123/', true, 'a trailing slash is the same page'],
			'no leading slash' => ['s/abc123', true, 'a path info without its leading slash still matches'],

			// Everything below the page serves bytes, not HTML: adding a script there is
			// work that produces nothing, and on the download route it would run on every
			// file a visitor fetches.
			'the download route' => ['/s/abc123/download', false, 'serves the file, not a page'],
			'a file inside a shared folder' => ['/s/abc123/download/sub/report.pdf', false, 'serves bytes'],
			'the public DAV endpoint' => ['/public.php/dav/files/abc123', false, 'the listing itself, not the page'],
			'a public preview' => ['/apps/files_sharing/publicpreview/abc123', false, 'an image, not a page'],

			'the token is missing' => ['/s/', false, 'no token, no share page'],
			'the token is missing entirely' => ['/s', false, 'not a share URL'],
			'an unrelated path that starts the same' => ['/settings/user', false, 'not a share URL'],
			'the Files app' => ['/apps/files/files', false, 'that is the other rule'],
			'no path at all' => [null, false, 'CLI or an unparseable URI'],
			'an empty path' => ['', false, 'nothing to classify'],
		];
	}
}
