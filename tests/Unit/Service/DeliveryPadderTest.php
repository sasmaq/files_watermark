<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Service\DeliveryPadder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\FilesWatermark\Service\DeliveryPadder
 *
 * The assertions that matter here are not about byte counts - those are arithmetic - but
 * about the padded file still being the file it was. Every format case reopens its output
 * with a real decoder and compares what it draws against the original, because a padding
 * scheme that hits the target length and produces something a reader rejects is worse than
 * no padding at all.
 */
class DeliveryPadderTest extends TestCase {

	private LoggerInterface&MockObject $logger;
	private DeliveryPadder $padder;

	/** @var string[] */
	private array $tmpFiles = [];

	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->padder = new DeliveryPadder($this->logger);
	}

	protected function tearDown(): void {
		foreach ($this->tmpFiles as $path) {
			if (file_exists($path)) {
				@unlink($path);
			}
		}
		parent::tearDown();
	}

	private function tmp(string $contents = ''): string {
		$path = tempnam(sys_get_temp_dir(), 'wm_pad_');
		$this->tmpFiles[] = $path;
		if ($contents !== '') {
			file_put_contents($path, $contents);
		}
		return $path;
	}

	/** A minimal but structurally real PDF - object, xref, trailer, startxref, %%EOF. */
	private function pdf(): string {
		$body = "%PDF-1.4\n"
			. "1 0 obj\n<< /Type /Catalog >>\nendobj\n";
		$xrefAt = strlen($body);
		$body .= "xref\n0 2\n0000000000 65535 f \n0000000009 00000 n \n"
			. "trailer\n<< /Size 2 /Root 1 0 R >>\n"
			. 'startxref' . "\n" . $xrefAt . "\n%%EOF\n";

		return $this->tmp($body);
	}

	private function png(): string {
		$image = imagecreatetruecolor(20, 10);
		imagefilledrectangle($image, 0, 0, 19, 9, imagecolorallocate($image, 10, 120, 200));
		$path = $this->tmp();
		imagepng($image, $path);
		imagedestroy($image);
		return $path;
	}

	private function jpeg(): string {
		$image = imagecreatetruecolor(20, 10);
		imagefilledrectangle($image, 0, 0, 19, 9, imagecolorallocate($image, 200, 60, 10));
		$path = $this->tmp();
		imagejpeg($image, $path, 90);
		imagedestroy($image);
		return $path;
	}

	private function webp(): string {
		$image = imagecreatetruecolor(20, 10);
		imagefilledrectangle($image, 0, 0, 19, 9, imagecolorallocate($image, 30, 190, 90));
		$path = $this->tmp();
		imagewebp($image, $path);
		imagedestroy($image);
		return $path;
	}

	/** Every pixel of $a and $b, so "still decodes" is not mistaken for "still correct". */
	private function assertPixelsIdentical(string $before, string $after, string $loader): void {
		$a = $loader($before);
		$b = $loader($after);
		$this->assertNotFalse($a, 'the original no longer decodes - the fixture is wrong');
		$this->assertNotFalse($b, 'the padded file no longer decodes');

		$this->assertSame(imagesx($a), imagesx($b));
		$this->assertSame(imagesy($a), imagesy($b));
		for ($x = 0; $x < imagesx($a); $x++) {
			for ($y = 0; $y < imagesy($a); $y++) {
				$this->assertSame(
					imagecolorat($a, $x, $y),
					imagecolorat($b, $x, $y),
					"pixel $x,$y changed",
				);
			}
		}
		imagedestroy($a);
		imagedestroy($b);
	}

	public function testPadsAPdfToTheExactTargetAndKeepsEofAtTheEnd(): void {
		$path = $this->pdf();
		$original = file_get_contents($path);
		$target = filesize($path) + 5000;

		$this->assertTrue($this->padder->padTo($path, $target, 'application/pdf'));
		$this->assertSame($target, filesize($path));

		$padded = file_get_contents($path);

		// The marker readers look for must still be the last thing in the file - this is the
		// whole reason a PDF is not padded by appending.
		$this->assertStringEndsWith("%%EOF\n", $padded);
		// And comfortably inside the 1024-byte window a reader searches, despite 5000 bytes
		// of filler having gone in.
		$this->assertLessThan(1024, strlen($padded) - strrpos($padded, '%%EOF'));

		// Nothing before `startxref` may move, or every offset in the xref table is wrong.
		$upToStartxref = substr($original, 0, strpos($original, 'startxref'));
		$this->assertStringStartsWith($upToStartxref, $padded);
	}

	public function testPadsAPngWithAChunkADecoderStillReads(): void {
		$path = $this->png();
		$before = $this->tmp(file_get_contents($path));
		$target = filesize($path) + 3000;

		$this->assertTrue($this->padder->padTo($path, $target, 'image/png'));
		$this->assertSame($target, filesize($path));

		$padded = file_get_contents($path);
		// IEND stays last: the padding is a chunk *before* it, not filler after it.
		$this->assertSame('IEND', substr($padded, -8, 4));
		$this->assertStringContainsString('wmPd', $padded);

		$this->assertPixelsIdentical($before, $path, 'imagecreatefrompng');
	}

	public function testPadsAJpegADecoderStillReads(): void {
		$path = $this->jpeg();
		$before = $this->tmp(file_get_contents($path));
		$target = filesize($path) + 2500;

		$this->assertTrue($this->padder->padTo($path, $target, 'image/jpeg'));
		$this->assertSame($target, filesize($path));

		$this->assertPixelsIdentical($before, $path, 'imagecreatefromjpeg');
	}

	public function testPadsAWebpWithoutDisturbingItsRiffLength(): void {
		if (!function_exists('imagewebp') || !function_exists('imagecreatefromwebp')) {
			$this->markTestSkipped('GD has no WebP support in this build');
		}

		$path = $this->webp();
		$before = $this->tmp(file_get_contents($path));
		$riffLengthBefore = substr(file_get_contents($path), 4, 4);
		$target = filesize($path) + 2000;

		$this->assertTrue($this->padder->padTo($path, $target, 'image/webp'));
		$this->assertSame($target, filesize($path));

		// The filler sits *outside* the file the RIFF header describes, which is precisely
		// what makes a decoder ignore it. Rewriting the length would pull it back inside.
		$this->assertSame($riffLengthBefore, substr(file_get_contents($path), 4, 4));

		$this->assertPixelsIdentical($before, $path, 'imagecreatefromwebp');
	}

	/** A render that already fits needs no filler, and must not be touched. */
	public function testAnExactFitIsLeftAlone(): void {
		$path = $this->pdf();
		$before = file_get_contents($path);

		$this->assertTrue($this->padder->padTo($path, filesize($path), 'application/pdf'));
		$this->assertSame($before, file_get_contents($path));
	}

	/**
	 * A render can outgrow its reservation. Nothing here can shrink a file without changing
	 * what it shows, so this has to be reported rather than approximated.
	 */
	public function testARenderOverItsReservationIsRefused(): void {
		$path = $this->pdf();
		$before = file_get_contents($path);

		$this->assertFalse($this->padder->padTo($path, filesize($path) - 10, 'application/pdf'));
		$this->assertSame($before, file_get_contents($path), 'a refused pad must not alter the file');
	}

	/**
	 * A gap under the format's smallest legal filler is a gap that cannot be filled exactly,
	 * and a Content-Length cannot be approximately right.
	 */
	public function testAGapBelowTheFormatMinimumIsRefused(): void {
		$png = $this->png();
		// A PNG chunk is 12 bytes of frame before any payload.
		$this->assertFalse($this->padder->padTo($png, filesize($png) + 11, 'image/png'));

		$pdf = $this->pdf();
		// A PDF comment is `%` and its newline.
		$this->assertFalse($this->padder->padTo($pdf, filesize($pdf) + 1, 'application/pdf'));
	}

	public function testTheSmallestLegalGapIsStillFilled(): void {
		$png = $this->png();
		$this->assertTrue($this->padder->padTo($png, filesize($png) + 12, 'image/png'));

		$pdf = $this->pdf();
		$this->assertTrue($this->padder->padTo($pdf, filesize($pdf) + 2, 'application/pdf'));
	}

	public function testAFormatWithNoSafeHoleIsNotPadded(): void {
		$path = $this->tmp('whatever');

		$this->assertFalse($this->padder->canPad('application/msword'));
		$this->assertFalse($this->padder->padTo($path, 9999, 'application/msword'));
	}

	public function testEverySupportedDeliveryFormatCanBePadded(): void {
		foreach (['application/pdf', 'image/jpeg', 'image/png', 'image/webp'] as $mime) {
			$this->assertTrue($this->padder->canPad($mime), "$mime must be paddable");
		}
	}

	/** The reservation has to clear the render, with room for the timestamp to move. */
	public function testReservationLeavesSlackAboveTheRender(): void {
		$this->assertGreaterThan(1000, $this->padder->reservationFor(1000));
		// A flat floor for small files, so a 10-byte render is not reserved 10 bytes.
		$this->assertSame(10 + 4096, $this->padder->reservationFor(10));
		// And a proportional term once 2% outgrows the floor, for documents where a text
		// change ripples through more compressed streams.
		$this->assertSame(1_000_000 + 20_000, $this->padder->reservationFor(1_000_000));
	}

	/** A PDF with no end marker has nowhere safe to put filler. */
	public function testAPdfWithNoEofMarkerIsRefused(): void {
		$path = $this->tmp('%PDF-1.4 and then nothing that ends it properly');

		$this->assertFalse($this->padder->padTo($path, filesize($path) + 500, 'application/pdf'));
	}
}
