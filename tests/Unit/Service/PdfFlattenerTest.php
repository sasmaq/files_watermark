<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Service\PdfFlattener;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for {@see PdfFlattener}, the app's one external-binary path.
 *
 * ---------------------------------------------------------------------------
 * **THE RENDERER IS FAKED, ON PURPOSE.**
 *
 * `pdftoppm` is optional - the whole feature is built around a host that may not have it -
 * so a suite that needed the real one would skip on most machines, and the flattener's own
 * logic (the command it builds, the ceilings, the rebuild, the cleanup) would go untested
 * exactly where it is most likely to be wrong. Instead each test puts a small shell script
 * called `pdftoppm` on `PATH`: it records the arguments it was given and copies a
 * pre-made PNG to the prefix it was asked for, which is precisely the contract the real
 * binary honours with `-png -singlefile`.
 *
 * What that leaves untested is poppler itself - whether a real page rasterises the way this
 * expects. `testAgainstTheRealRendererIfThisHostHasOne` covers that, and is the one case
 * that skips.
 * ---------------------------------------------------------------------------
 */
class PdfFlattenerTest extends TestCase {
	use PdfFixtures;

	private string $tmpDir;
	private string|false $originalPath;

	protected function setUp(): void {
		parent::setUp();
		$this->tmpDir = sys_get_temp_dir() . '/wm_flat_test_' . bin2hex(random_bytes(6));
		mkdir($this->tmpDir, 0700, true);
		$this->originalPath = getenv('PATH');
	}

	protected function tearDown(): void {
		putenv($this->originalPath === false ? 'PATH' : 'PATH=' . $this->originalPath);
		$this->deleteTree($this->tmpDir);
		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// Availability - what the admin form and the API gate both hang off
	// -----------------------------------------------------------------------

	public function testTheRendererIsFoundOnPath(): void {
		$this->fakeRenderer();

		$this->assertTrue($this->flattener()->isAvailable());
	}

	public function testAHostWithoutTheRendererReportsUnavailable(): void {
		putenv('PATH=' . $this->tmpDir . '/empty');

		$this->assertFalse($this->flattener()->isAvailable());
	}

	public function testAnUnexecutableFileOfTheRightNameIsNotARenderer(): void {
		// A `pdftoppm` that cannot be run is the same to us as no `pdftoppm` at all, and
		// answering true here would offer the admin a setting every download then fails on.
		$bin = $this->tmpDir . '/bin';
		mkdir($bin, 0700, true);
		file_put_contents($bin . '/pdftoppm', "#!/bin/sh\nexit 0\n");
		chmod($bin . '/pdftoppm', 0o644);
		putenv('PATH=' . $bin);

		$this->assertFalse($this->flattener()->isAvailable());
	}

	public function testTheReasonForBeingUnavailableIsLoggedOnce(): void {
		// The control is hidden when the probe fails, so the log is the only place an admin
		// can find out why. Once per request, however many files are rendered.
		putenv('PATH=' . $this->tmpDir . '/empty');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('info')
			->with($this->anything(), $this->callback(
				static fn (array $context): bool => str_contains($context['reason'], 'pdftoppm'),
			));
		$flattener = new PdfFlattener($logger);

		$flattener->isAvailable();
		$flattener->isAvailable();
	}

	// -----------------------------------------------------------------------
	// The rebuild
	// -----------------------------------------------------------------------

	public function testEachSourcePageYieldsExactlyOnePage(): void {
		// Catches the margin / auto-page-break spill: a full-bleed image on a page with
		// default margins pushes onto a second page, doubling the document.
		$this->fakeRenderer();
		$source = $this->sourcePdf(['One', 'Two', 'Three']);
		$dest = $this->tmpDir . '/pages.pdf';

		$this->flattener()->flatten($source, $dest, 72);

		$this->assertStringStartsWith('%PDF', (string)file_get_contents($dest));
		$this->assertSame(3, $this->readPageCount($dest));
	}

	public function testFlattenedOutputHasNoExtractableTextLayer(): void {
		// The actual security claim. If text survives, the watermark is still a separate
		// object and the feature has not done its job.
		$this->fakeRenderer();
		$source = $this->sourcePdf(['Sensitive body text', 'CONFIDENTIAL watermark']);
		$dest = $this->tmpDir . '/flat.pdf';

		$this->flattener()->flatten($source, $dest, 72);

		$this->assertStringNotContainsString('Sensitive body text', $this->extractText($dest));
		$this->assertStringNotContainsString('CONFIDENTIAL watermark', $this->extractText($dest));
		// ...while the source, for contrast, does carry it.
		$this->assertStringContainsString('Sensitive body text', $this->extractText($source));
	}

	public function testPageGeometrySurvivesForNonA4AndLandscapePages(): void {
		$this->fakeRenderer();
		$source = $this->tmpDir . '/mixed.pdf';
		// A5 portrait then A4 landscape, in points. Explicit sizes rather than format names
		// so the fixture states the geometry the assertion is about.
		$this->writePdf($source, [
			['width' => self::A5_WIDTH, 'height' => self::A5_HEIGHT],
			['width' => self::A4_HEIGHT, 'height' => self::A4_WIDTH],
		]);
		$expected = $this->readPageSizes($source);
		$dest = $this->tmpDir . '/mixed-flat.pdf';

		$this->flattener()->flatten($source, $dest, 72);

		$actual = $this->readPageSizes($dest);
		$this->assertCount(2, $actual);
		foreach ($expected as $page => $size) {
			$this->assertEqualsWithDelta($size['width'], $actual[$page]['width'], 1.0, "page $page width");
			$this->assertEqualsWithDelta($size['height'], $actual[$page]['height'], 1.0, "page $page height");
		}
	}

	public function testEveryPageIsRenderedIndividually(): void {
		// `-f N -l N -singlefile`, one call per page. Rendering the whole document in one
		// call would put every page bitmap on disk at once, which is the memory and disk
		// bound the page-at-a-time loop exists to avoid.
		$this->fakeRenderer();
		$source = $this->sourcePdf(['One', 'Two', 'Three']);

		$this->flattener()->flatten($source, $this->tmpDir . '/out.pdf', 72);

		$calls = $this->rendererCalls();
		$this->assertCount(3, $calls);
		foreach ([1, 2, 3] as $page) {
			$this->assertStringContainsString("-f $page -l $page", $calls[$page - 1]);
			$this->assertStringContainsString('-singlefile', $calls[$page - 1]);
		}
	}

	// -----------------------------------------------------------------------
	// Bounds
	// -----------------------------------------------------------------------

	/**
	 * A caller cannot make the renderer produce a 20000-DPI page: that is a memory and disk
	 * denial of service, not a quality setting. Asserted on the command line rather than on
	 * output size, because it is the operand handed to the binary that does the damage.
	 *
	 * @dataProvider dpiProvider
	 */
	public function testDpiIsClampedToTheSupportedRange(int $requested, int $expected): void {
		$this->fakeRenderer();
		$source = $this->sourcePdf(['Clamp']);

		$this->flattener()->flatten($source, $this->tmpDir . '/clamped.pdf', $requested);

		$this->assertStringContainsString("-r $expected ", $this->rendererCalls()[0]);
	}

	/** @return array<string, array{int, int}> */
	public static function dpiProvider(): array {
		return [
			'in range' => [200, 200],
			'absurdly high' => [20000, PdfFlattener::MAX_DPI],
			'below the floor' => [10, PdfFlattener::MIN_DPI],
			'negative' => [-1, PdfFlattener::MIN_DPI],
		];
	}

	public function testPageCeilingIsEnforced(): void {
		$this->fakeRenderer();
		$source = $this->sourcePdf(array_fill(0, 201, 'page'));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('exceeds the 200 page ceiling');

		$this->flattener()->flatten($source, $this->tmpDir . '/too-many.pdf', 72);
	}

	// -----------------------------------------------------------------------
	// Failure - always without a destination file, so the caller can fall back
	// -----------------------------------------------------------------------

	public function testAMissingRendererThrowsAndNamesThePackage(): void {
		putenv('PATH=' . $this->tmpDir . '/empty');
		$dest = $this->tmpDir . '/never.pdf';

		try {
			$this->flattener()->flatten($this->sourcePdf(['One']), $dest);
			$this->fail('Expected a RuntimeException with no renderer on PATH');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('poppler-utils', $e->getMessage());
		}

		$this->assertFileDoesNotExist($dest);
	}

	public function testAFailingRenderPassIsReportedAndWritesNothing(): void {
		// The caller keeps the overlay-watermarked file it passed in and serves that, so
		// what matters here is that no half-built rebuild is left where it might be served
		// instead.
		$this->fakeRenderer(exitStatus: 1);
		$dest = $this->tmpDir . '/failed.pdf';

		try {
			$this->flattener()->flatten($this->sourcePdf(['One']), $dest, 72);
			$this->fail('Expected a RuntimeException when the renderer fails');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('rendering page 1 failed', $e->getMessage());
		}

		$this->assertFileDoesNotExist($dest);
	}

	public function testCorruptSourceThrowsBeforeTheRendererIsEverCalled(): void {
		$this->fakeRenderer();
		$bad = $this->tmpDir . '/bad.pdf';
		file_put_contents($bad, 'this is not a real PDF document');
		$dest = $this->tmpDir . '/never.pdf';

		try {
			$this->flattener()->flatten($bad, $dest, 72);
			$this->fail('Expected a RuntimeException for a corrupt source');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('Cannot flatten PDF', $e->getMessage());
		}

		$this->assertFileDoesNotExist($dest);
		$this->assertSame([], $this->rendererCalls());
	}

	public function testMissingSourceThrows(): void {
		$this->fakeRenderer();

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Cannot flatten PDF');

		$this->flattener()->flatten($this->tmpDir . '/absent.pdf', $this->tmpDir . '/out.pdf');
	}

	/**
	 * **The renderer writes into a directory only this process can reach.**
	 *
	 * `pdftoppm` creates its own output file, so the name it is given is a name something
	 * else could get to first in a world-writable `/tmp` - by planting a symlink the
	 * renderer then follows, or a file it truncates. A 0700 directory with a random name
	 * removes the race rather than narrowing it: no other account can create that name.
	 */
	public function testPageBitmapsAreWrittenIntoAPrivateDirectory(): void {
		$this->fakeRenderer();
		$source = $this->sourcePdf(['One', 'Two']);

		$this->flattener()->flatten($source, $this->tmpDir . '/private.pdf', 72);

		$modes = $this->outputDirModes();
		$this->assertCount(2, $modes, 'both pages should have been rendered');
		foreach ($modes as $mode) {
			$this->assertSame('drwx------', $mode, 'the render directory was reachable by other accounts');
		}

		// And it is not the shared temp directory itself, whatever its mode happens to be.
		foreach ($this->rendererCalls() as $call) {
			$prefix = trim((string)strrchr($call, ' '));
			$this->assertNotSame(
				realpath(sys_get_temp_dir()),
				realpath(dirname($prefix)),
				'page bitmaps must not be written straight into the shared temp directory',
			);
		}
	}

	public function testTheWorkDirectoryIsRemovedAfterAFailedRender(): void {
		// Not just the bitmaps - the directory holding them goes too, so a server that
		// flattens on every download does not accumulate empty directories in /tmp.
		$this->fakeRenderer(exitStatus: 1);
		$before = glob(sys_get_temp_dir() . '/wm_flat_*') ?: [];

		try {
			$this->flattener()->flatten($this->sourcePdf(['One']), $this->tmpDir . '/x.pdf', 72);
		} catch (\RuntimeException) {
			// The subject of this test is what is left on disk, not the message.
		}

		$this->assertSame($before, glob(sys_get_temp_dir() . '/wm_flat_*') ?: []);
	}

	/**
	 * **The source is never written, on any path.**
	 *
	 * The caller's fallback depends on it entirely: when a flatten fails, what it serves is
	 * the very file it passed in here. A flattener that truncated or replaced its source on
	 * the way to failing would take the overlay-watermarked copy down with it and turn a
	 * recoverable failure into a refused download.
	 *
	 * @dataProvider sourcePreservationProvider
	 */
	public function testTheSourceIsLeftByteIdentical(int $exitStatus): void {
		$this->fakeRenderer(exitStatus: $exitStatus);
		$source = $this->sourcePdf(['One', 'Two']);
		$before = (string)file_get_contents($source);

		try {
			$this->flattener()->flatten($source, $this->tmpDir . '/out.pdf', 72);
		} catch (\RuntimeException) {
			// Both outcomes are under test; only the source matters here.
		}

		$this->assertSame($before, (string)file_get_contents($source));
	}

	/** @return array<string, array{int}> */
	public static function sourcePreservationProvider(): array {
		return [
			'a successful rebuild' => [0],
			'a failed render' => [1],
		];
	}

	/**
	 * The renderer is asked to read the source and write somewhere else - never to write
	 * over what it is reading. Asserted on the command line because that is where the
	 * mistake would be made, and where it would be invisible to every other test until a
	 * host had the real binary.
	 */
	public function testTheRendererIsNeverPointedAtItsOwnSource(): void {
		$this->fakeRenderer();
		$source = $this->sourcePdf(['One']);

		$this->flattener()->flatten($source, $this->tmpDir . '/out.pdf', 72);

		$call = $this->rendererCalls()[0];
		$prefix = trim((string)strrchr($call, ' '));
		$this->assertNotSame($source, $prefix);
		$this->assertNotSame($source, $prefix . '.png');
		$this->assertStringContainsString($source, $call, 'the source is still the input');
	}

	public function testNoPageBitmapsAreLeftBehind(): void {
		$this->fakeRenderer();
		$before = count(glob(sys_get_temp_dir() . '/wm_flat_*') ?: []);
		$source = $this->sourcePdf(['One', 'Two']);

		$this->flattener()->flatten($source, $this->tmpDir . '/clean.pdf', 72);

		$this->assertSame($before, count(glob(sys_get_temp_dir() . '/wm_flat_*') ?: []));
	}

	public function testNoBitmapSurvivesAFailedRender(): void {
		$this->fakeRenderer(exitStatus: 1);
		$before = count(glob(sys_get_temp_dir() . '/wm_flat_*') ?: []);

		try {
			$this->flattener()->flatten($this->sourcePdf(['One']), $this->tmpDir . '/x.pdf', 72);
		} catch (\RuntimeException) {
			// The subject of this test is what is left on disk, not the message.
		}

		$this->assertSame($before, count(glob(sys_get_temp_dir() . '/wm_flat_*') ?: []));
	}

	// -----------------------------------------------------------------------
	// The one case that needs poppler itself
	// -----------------------------------------------------------------------

	/**
	 * Everything above fakes the binary, which cannot tell us whether a *real* page
	 * rasterises into a page this app can rebuild. This is that check, and it is the only
	 * test in the suite that depends on a host package - it skips where there is none.
	 */
	public function testAgainstTheRealRendererIfThisHostHasOne(): void {
		if (!$this->flattener()->isAvailable()) {
			$this->markTestSkipped(PdfFlattener::RENDERER . ' is not installed on this host');
		}

		$source = $this->sourcePdf(['Real render']);
		$dest = $this->tmpDir . '/real.pdf';

		$this->flattener()->flatten($source, $dest, 72);

		$this->assertSame(1, $this->readPageCount($dest));
		$this->assertStringNotContainsString('Real render', $this->extractText($dest));
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	private function flattener(): PdfFlattener {
		// A fresh one per call: the availability probe is memoised for the life of the
		// instance, which is a request in production and would otherwise carry one test's
		// PATH into the next assertion here.
		return new PdfFlattener($this->createMock(LoggerInterface::class));
	}

	/**
	 * Put a stand-in `pdftoppm` on PATH.
	 *
	 * It writes each invocation's arguments to a log and copies a real PNG to
	 * `<prefix>.png`, which is what `-png -singlefile` produces. `for last; do :; done`
	 * leaves the final positional argument - the output prefix - in `$last`.
	 */
	private function fakeRenderer(int $exitStatus = 0): void {
		$bin = $this->tmpDir . '/bin';
		mkdir($bin, 0700, true);

		$png = $this->tmpDir . '/page.png';
		$image = imagecreatetruecolor(60, 80);
		imagefilledrectangle($image, 0, 0, 59, 79, imagecolorallocate($image, 220, 220, 220));
		imagepng($image, $png);
		imagedestroy($image);

		// PATH is the fake's own directory while this runs, so the script has to name where
		// its own tools live or `cp` is not found either.
		$script = "#!/bin/sh\n"
			. "PATH=/usr/bin:/bin\n"
			. 'printf \'%s\n\' "$*" >> ' . escapeshellarg($this->rendererLog()) . "\n"
			. "for last; do :; done\n"
			// The permissions of the directory the renderer is told to write into, as they
			// stand at the moment it runs. `ls -ld` reads the same on Linux and macOS, which
			// `stat` does not.
			. 'ls -ld "$(dirname "$last")" | cut -c1-10 >> ' . escapeshellarg($this->dirModeLog()) . "\n"
			. ($exitStatus === 0 ? 'cp ' . escapeshellarg($png) . " \"\$last.png\"\n" : '')
			. "exit $exitStatus\n";

		file_put_contents($bin . '/pdftoppm', $script);
		chmod($bin . '/pdftoppm', 0o755);
		putenv('PATH=' . $bin);
	}

	private function rendererLog(): string {
		return $this->tmpDir . '/renderer.log';
	}

	private function dirModeLog(): string {
		return $this->tmpDir . '/dirmode.log';
	}

	/** @return list<string> the `ls -ld` mode string of each render's output directory */
	private function outputDirModes(): array {
		if (!file_exists($this->dirModeLog())) {
			return [];
		}

		return array_values(array_filter(explode("\n", (string)file_get_contents($this->dirModeLog()))));
	}

	/** @return list<string> one entry per invocation of the fake renderer */
	private function rendererCalls(): array {
		if (!file_exists($this->rendererLog())) {
			return [];
		}

		return array_values(array_filter(explode("\n", (string)file_get_contents($this->rendererLog()))));
	}

	/**
	 * @param list<string> $pages one line of body text per page
	 */
	private function sourcePdf(array $pages): string {
		$path = $this->tmpDir . '/source.pdf';
		$this->writePdf($path, array_map(static fn (string $text): array => ['text' => $text], $pages));
		return $path;
	}

	/**
	 * Text recoverable from the PDF's content streams. Crude next to a real extractor, but
	 * it is looking for the *absence* of glyphs, and the renderer writes page text as plain
	 * `(...) Tj` / `[(...)] TJ` operators.
	 */
	private function extractText(string $pdf): string {
		$raw = (string)file_get_contents($pdf);
		preg_match_all('#stream\r?\n(.*?)endstream#s', $raw, $matches);

		$text = $raw;
		foreach ($matches[1] as $stream) {
			$inflated = @gzuncompress($stream);
			if ($inflated === false) {
				$inflated = @gzinflate($stream);
			}
			if ($inflated !== false) {
				$text .= $inflated;
			}
		}

		return $text;
	}

	private function deleteTree(string $dir): void {
		foreach (glob($dir . '/*') ?: [] as $path) {
			is_dir($path) ? $this->deleteTree($path) : @unlink($path);
		}
		@rmdir($dir);
	}
}
