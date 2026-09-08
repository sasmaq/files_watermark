<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Service\PdfObjectHeaderNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see PdfObjectHeaderNormalizer}, which rewrites indirect object headers
 * into the single-space form tc-lib-pdf-parser can find.
 *
 * The assertions are about **byte count and resolution**, not about page counts. A
 * repair that shifted the file by a single byte would leave every cross-reference
 * offset pointing one byte off; the parser would then read most objects correctly and
 * lose the odd one, which is the kind of damage a page count cannot see.
 */
class PdfObjectHeaderNormalizerTest extends TestCase {
	use ObjectHeaderWhitespaceFixture;

	private PdfObjectHeaderNormalizer $normalizer;

	protected function setUp(): void {
		parent::setUp();
		$this->normalizer = new PdfObjectHeaderNormalizer();
	}

	/**
	 * The headline case: line feeds, as Google's exporters emit them.
	 *
	 * The fixture is asserted to be broken *first*. `3\n0\nobj` is exactly as wide as
	 * `3 0 obj`, so a normaliser that compared widths instead of bytes would decline
	 * to touch it and this test would pass against a class that does nothing - which
	 * is precisely the bug the first version of it had.
	 */
	public function testRepairsLineFeedSeparatedHeaders(): void {
		$raw = $this->buildPdfWithHeaderSeparator("\n");
		$this->assertFalse($this->rootResolves($raw), 'fixture should reproduce the failure');

		$repaired = $this->normalizer->normalize($raw);

		$this->assertNotNull($repaired);
		$this->assertSame(strlen($raw), strlen($repaired), 'byte count must be preserved');
		$this->assertTrue($this->rootResolves($repaired));
	}

	/**
	 * A two-byte separator, which cannot be swapped one-for-one.
	 *
	 * `1\r\n0\r\nobj` is nine bytes and `1 0 obj` is seven, so the header is padded back
	 * to width with leading zeros - `001 0 obj` - which the parser skips before it looks
	 * for the header it wants. Without that the file would shorten and every offset
	 * after the first object would be wrong.
	 */
	public function testRepairsCarriageReturnSeparatedHeadersByPadding(): void {
		$raw = $this->buildPdfWithHeaderSeparator("\r\n");
		$this->assertFalse($this->rootResolves($raw), 'fixture should reproduce the failure');

		$repaired = $this->normalizer->normalize($raw);

		$this->assertNotNull($repaired);
		$this->assertSame(strlen($raw), strlen($repaired), 'byte count must be preserved');
		$this->assertStringContainsString('001 0 obj', $repaired, 'expected leading-zero padding');
		$this->assertTrue($this->rootResolves($repaired));
	}

	/**
	 * Content that merely looks like a header is not one.
	 *
	 * Only the offsets the cross-reference table declares are examined, so the
	 * `9 0 obj` drawn inside the page's content stream stays as it is. A whole-file
	 * regular expression would rewrite it and quietly corrupt the stream.
	 */
	public function testLeavesStreamPayloadsAlone(): void {
		$repaired = $this->normalizer->normalize($this->buildPdfWithHeaderSeparator("\n"));

		$this->assertNotNull($repaired);
		$this->assertStringContainsString('(9 0 obj) Tj', $repaired);
	}

	/**
	 * A file the parser already reads is not this class's case, and saying so matters:
	 * the caller reports the *import's* exception when every rewriter declines, and a
	 * normaliser that returned a copy of its input would replace that message with a
	 * second failure from a pointless retry.
	 */
	public function testDeclinesWhenHeadersAreAlreadyCanonical(): void {
		$raw = $this->buildPdfWithHeaderSeparator(' ');
		$this->assertTrue($this->rootResolves($raw), 'fixture should already be readable');

		$this->assertNull($this->normalizer->normalize($raw));
	}

	/** Nothing to repair in something that is not a PDF, and nothing thrown either. */
	public function testDeclinesOnUnparseableInput(): void {
		$this->assertNull($this->normalizer->normalize('not a pdf at all'));
		$this->assertNull($this->normalizer->normalize(''));
	}
}
