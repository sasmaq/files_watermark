<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Dav;

use OCA\FilesWatermark\Dav\HideOriginalsPlugin;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Server;
use Sabre\HTTP\Request;

/**
 * The guard that keeps preserved originals off WebDAV.
 *
 * Driven through a **real** `Sabre\DAV\Server` rather than by calling the handlers
 * directly: what is being pinned is that these two hooks exist and fire under the names
 * used here. A test that called `refuseRequest()` itself would keep passing if the
 * subscription were misspelled, which is exactly the failure that leaves the folder
 * exposed while every test stays green.
 */
class HideOriginalsPluginTest extends TestCase {

	private Server $server;

	protected function setUp(): void {
		parent::setUp();
		$this->server = new Server();
		$this->server->addPlugin(new HideOriginalsPlugin());
	}

	/**
	 * @dataProvider sealedPathProvider
	 */
	public function testSealedPathsAreRefusedForEveryMethod(string $path, string $label): void {
		foreach (['GET', 'PUT', 'DELETE', 'MOVE', 'COPY', 'PROPFIND', 'MKCOL'] as $method) {
			try {
				$this->server->emit('beforeMethod:' . $method, [new Request($method, '/' . $path)]);
				$this->fail("$method on $label ($path) was allowed through");
			} catch (NotFound) {
				$this->addToAssertionCount(1);
			}
		}
	}

	/** @return array<string, array{string, string}> */
	public static function sealedPathProvider(): array {
		return [
			// The folder itself. Matching the name with a trailing slash misses this,
			// which left DELETE on the folder answering 204 and taking every preserved
			// original with it.
			'the folder itself' => ['files/alice/.files_watermark', 'the folder itself'],
			'a file inside it' => ['files/alice/.files_watermark/originals/11', 'a preserved original'],
			'the legacy endpoint' => ['.files_watermark/originals/11', 'the legacy webdav path'],
			'url-encoded' => ['files/alice/%2Efiles_watermark/originals/11', 'a percent-encoded path'],
			// The trashbin renames what it takes in.
			'trashed' => ['trashbin/alice/trash/.files_watermark.d1785710850', 'the trashed folder'],
		];
	}

	/**
	 * @dataProvider ordinaryPathProvider
	 */
	public function testOrdinaryPathsAreUntouched(string $path): void {
		foreach (['GET', 'PUT', 'DELETE', 'PROPFIND'] as $method) {
			$this->server->emit('beforeMethod:' . $method, [new Request($method, '/' . $path)]);
		}

		// Reaching here without a NotFound is the assertion.
		$this->addToAssertionCount(1);
	}

	/** @return array<string, array{string}> */
	public static function ordinaryPathProvider(): array {
		return [
			'an ordinary file' => ['files/alice/report.pdf'],
			'the user root' => ['files/alice'],
			// Only the exact folder name is sealed, not anything that merely starts the
			// same way - a user's own "files_watermark_notes" folder is their business.
			'a similarly named folder' => ['files/alice/files_watermark_notes/report.pdf'],
			'a dotted lookalike' => ['files/alice/.files_watermarkish/report.pdf'],
		];
	}

	public function testListingsDropPreservedOriginals(): void {
		$properties = [
			['href' => '/remote.php/dav/files/alice/'],
			['href' => '/remote.php/dav/files/alice/.files_watermark/'],
			['href' => '/remote.php/dav/files/alice/.files_watermark/originals/11'],
			['href' => '/remote.php/dav/files/alice/report.pdf'],
			['href' => '/remote.php/dav/trashbin/alice/trash/.files_watermark.d1785710850/'],
		];

		$this->server->emit('beforeMultiStatus', [&$properties]);

		$this->assertSame(
			[
				'/remote.php/dav/files/alice/',
				'/remote.php/dav/files/alice/report.pdf',
			],
			array_column($properties, 'href'),
		);
	}

	public function testTheFilteredListIsARealListAgain(): void {
		// The entries are re-indexed rather than left with gaps: sabre writes the
		// multistatus by iterating, and a caller that json-encoded a gapped array would
		// get an object where a list belongs.
		$properties = [
			['href' => '/remote.php/dav/files/alice/.files_watermark/'],
			['href' => '/remote.php/dav/files/alice/report.pdf'],
		];

		$this->server->emit('beforeMultiStatus', [&$properties]);

		$this->assertSame([0], array_keys($properties));
	}

	public function testAnEntryWithoutAnHrefIsKept(): void {
		// Nothing here should decide the fate of a response shape it does not recognise.
		$properties = [['404' => []]];

		$this->server->emit('beforeMultiStatus', [&$properties]);

		$this->assertCount(1, $properties);
	}
}
