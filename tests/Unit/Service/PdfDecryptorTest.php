<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use Com\Tecnick\Pdf\Parser\Parser;
use OCA\FilesWatermark\Service\PdfDecryptor;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see PdfDecryptor}, which turns a PDF encrypted under an empty user password
 * into the plaintext equivalent so the renderer can import it.
 *
 * Two things are being pinned here and they pull in opposite directions. The decryptor
 * has to open every file whose "protection" is only a set of permission flags, across all
 * five revisions of the standard security handler and both file layouts - and it has to
 * refuse everything else, in particular a document with a real password, which it must
 * never try to guess or bypass through the owner entry.
 *
 * Assertions look at the **decoded content**, not at page counts. A rewrite that dropped
 * every content stream would still produce a file with the right number of pages of the
 * right size, so a test that stopped at geometry would pass on a blank document.
 */
class PdfDecryptorTest extends TestCase {
	use EncryptedObjectStreamFixture;
	use PdfFixtures;

	private PdfDecryptor $decryptor;
	private string $tmpDir;

	protected function setUp(): void {
		parent::setUp();
		$this->decryptor = new PdfDecryptor();
		$this->tmpDir = sys_get_temp_dir() . '/wm_decrypt_test_' . bin2hex(random_bytes(6));
		mkdir($this->tmpDir, 0700, true);
	}

	protected function tearDown(): void {
		foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
			@unlink($file);
		}

		@rmdir($this->tmpDir);
		parent::tearDown();
	}

	/**
	 * The headline case, across every cipher the standard security handler defines.
	 *
	 * Each mode reaches the plaintext by a different route - RC4 against AES, a key
	 * derived per object against one derived per document, R5's single SHA-256 against
	 * R6's iterated hash - so covering AES-128 alone would leave four untested paths that
	 * differ in exactly the place a decryptor gets things wrong.
	 *
	 * @dataProvider encryptionModeProvider
	 */
	public function testEmptyPasswordDocumentIsDecrypted(int $mode): void {
		$source = $this->tmpDir . '/encrypted.pdf';
		$this->writeEncryptedPdf($source, '', $mode, 'Permission flags only');

		$plaintext = $this->decryptor->decrypt((string)file_get_contents($source));

		$this->assertIsString($plaintext, 'an empty user password must be opened, not refused');
		$this->assertStringContainsString(
			'(Permission flags only)',
			$this->decodedStreams($plaintext),
			'the page content did not survive decryption',
		);
	}

	/**
	 * The refusal, and the reason the class exists in the shape it does.
	 *
	 * A real user password is protection the app has no business bypassing, and the
	 * owner password is not a back door either: `Decrypt::authenticate()` would happily
	 * try the string it is given as both, so the only thing keeping this honest is that
	 * the string it is given is always empty.
	 *
	 * @dataProvider encryptionModeProvider
	 */
	public function testPasswordProtectedDocumentIsRefused(int $mode): void {
		$source = $this->tmpDir . '/protected.pdf';
		$this->writeEncryptedPdf($source, 's3cret', $mode, 'Genuinely protected');

		$this->assertNull(
			$this->decryptor->decrypt((string)file_get_contents($source)),
			'a document with a real user password must not be opened',
		);
	}

	/**
	 * A document that was never encrypted is not this class's business.
	 *
	 * Returning `null` rather than a rebuilt copy matters for more than tidiness: the
	 * renderer only calls the decryptor after its own import has failed, and a decryptor
	 * that "succeeded" on an unencrypted file would replace tc-lib-pdf's own diagnosis of
	 * why the file could not be read with a second, less informative failure.
	 */
	public function testUnencryptedDocumentIsDeclined(): void {
		$source = $this->tmpDir . '/plain.pdf';
		$this->writePdf($source, [['text' => 'Nothing to unlock']]);

		$this->assertNull($this->decryptor->decrypt((string)file_get_contents($source)));
	}

	public function testGarbageIsDeclinedRatherThanThrowing(): void {
		$this->assertNull($this->decryptor->decrypt('this is not a real PDF document'));
		$this->assertNull($this->decryptor->decrypt(''));
	}

	/**
	 * The `/Encrypt` dictionary has to be *gone*, not merely unused.
	 *
	 * Leaving it behind would be worse than useless: a reader that finds it would try to
	 * decrypt content that is now plaintext, and tc-lib-pdf's importer refuses any
	 * document whose trailer names one - so the file would still not render, having been
	 * rewritten for nothing.
	 */
	public function testDecryptedDocumentDeclaresNoEncryption(): void {
		$source = $this->tmpDir . '/encrypted.pdf';
		$this->writeEncryptedPdf($source, '', 2, 'Flags only');

		$plaintext = (string)$this->decryptor->decrypt((string)file_get_contents($source));

		$this->assertArrayNotHasKey('encrypt', $this->trailerOf($plaintext));
	}

	/**
	 * The compressed-object case, which the classic-layout fixtures cannot reach.
	 *
	 * Everything of consequence in this fixture - catalogue, page tree, page - lives
	 * inside an object stream, so the rewrite has to carry the cross-reference's type 2
	 * rows across pointing at the same container and the same index. Get that wrong and
	 * the document has no catalogue at all.
	 *
	 * @dataProvider encryptionModeProvider
	 */
	public function testObjectStreamDocumentIsDecrypted(int $mode): void {
		$plaintext = $this->decryptor->decrypt($this->buildEncryptedObjectStreamPdf('', $mode));

		$this->assertIsString($plaintext, 'an object-stream document must be opened');
		$this->assertStringContainsString(
			'(' . self::OBJECT_STREAM_TEXT . ')',
			$this->decodedStreams($plaintext),
			'the page content did not survive decryption',
		);
		$this->assertSame(1, $this->pageCountOf($plaintext), 'the page tree was lost in the rewrite');
	}

	/**
	 * Strings inside an object stream are enciphered **once**, by the stream that holds
	 * them, and must not be decrypted a second time as strings (PDF 32000-1 §7.6.2).
	 *
	 * This is the rule the whole rewrite leans on: because it holds, decrypting the
	 * container is enough, and the objects inside it never have to be unpacked, rewritten
	 * or renumbered. A decryptor that applied the cipher again would leave noise where
	 * this title is, and the document would still open - which is exactly the kind of
	 * corruption that gets noticed months later.
	 */
	public function testStringsInsideAnObjectStreamAreDecryptedOnlyOnce(): void {
		$plaintext = (string)$this->decryptor->decrypt($this->buildEncryptedObjectStreamPdf());

		$this->assertStringContainsString(
			'(' . self::OBJECT_STREAM_TITLE . ')',
			$this->decodedStreams($plaintext),
			'a string inside an object stream was decrypted twice',
		);
	}

	/**
	 * Top-level strings, in both the forms a producer may write them in.
	 *
	 * A literal `(...)` string carries backslash escapes that encode the ciphertext's own
	 * bytes, so the cipher has to see the *value* rather than the source text; a
	 * hexadecimal string needs no unescaping but does need its digits paired up. Both
	 * appear in the fixture's information dictionary, and both are checked, because
	 * getting one right proves nothing about the other.
	 */
	public function testTopLevelStringsAreDecryptedInBothWrittenForms(): void {
		$plaintext = (string)$this->decryptor->decrypt($this->buildEncryptedObjectStreamPdf());
		$strings = $this->stringsOf($plaintext, '9_0');

		$this->assertSame('Records Office', $strings['Author'] ?? null, 'a hexadecimal string');
		$this->assertSame('Board pack', $strings['Subject'] ?? null, 'an escaped literal string');
	}

	/**
	 * `/EncryptMetadata false` changes how the key is derived, not just what is
	 * enciphered.
	 *
	 * PDF 32000-1 algorithm 2 step (f) appends four `FF` bytes to the hash input for such
	 * a document from revision 4 onwards. The library's own `Decrypt` omits that step and
	 * its `Encrypt` omits it too, so conformant files and tc-lib-pdf's own output disagree
	 * about the key - and the decryptor has to open both, or it reports a password that
	 * was never set.
	 */
	public function testDocumentWithUnencryptedMetadataIsDecrypted(): void {
		$plaintext = $this->decryptor->decrypt(
			$this->buildEncryptedObjectStreamPdf('', 2, encryptMetadata: false),
		);

		$this->assertIsString($plaintext, '/EncryptMetadata false must not be mistaken for a bad password');
		$this->assertStringContainsString('(' . self::OBJECT_STREAM_TEXT . ')', $this->decodedStreams($plaintext));
	}

	/**
	 * Every byte value survives being written as an escaped literal string and read back.
	 *
	 * The round-trip above proves this for whatever bytes the cipher happened to produce
	 * on the day, which is not the same thing: ciphertext is random, so a hole in the
	 * escaping shows up as a test that fails on roughly one run in eight and passes
	 * everywhere else. That is exactly what happened - {@see
	 * EncryptedObjectStreamFixture::escapeLiteral()} used to protect a carriage return
	 * with a backslash, which in PDF syntax is a *line continuation* and deletes the byte
	 * rather than preserving it, so the string came back empty whenever the random IV
	 * contained a `0x0D`. Enumerating all 256 values turns that from luck into a fact.
	 *
	 * Reflection because `unescapeLiteral()` is private and should stay that way - it is
	 * an implementation detail of reading one token, not an API. The suite already reaches
	 * for private members this way where the alternative is exposing something for the
	 * tests' benefit alone.
	 */
	public function testEveryByteSurvivesTheEscapedLiteralRoundTrip(): void {
		$allBytes = implode('', array_map('chr', range(0, 255)));

		$unescape = new \ReflectionMethod(PdfDecryptor::class, 'unescapeLiteral');
		$unescape->setAccessible(true);

		$this->assertSame(
			$allBytes,
			$unescape->invoke(null, self::escapeLiteral($allBytes)),
			'a byte was lost or altered between escaping and unescaping a literal string',
		);
	}

	/** Every decoded stream in `$pdf`, concatenated, for content assertions. */
	private function decodedStreams(string $pdf): string {
		$decoded = '';
		foreach ($this->parse($pdf)[1] as $object) {
			foreach ($object as $element) {
				if ($element[0] === 'stream' && is_string($element[3][0] ?? null)) {
					$decoded .= $element[3][0] . "\n";
				}
			}
		}

		return $decoded;
	}

	/**
	 * The string values of one object's dictionary, keyed by entry name and resolved to
	 * the bytes they denote.
	 *
	 * @return array<string, string>
	 */
	private function stringsOf(string $pdf, string $ref): array {
		$object = $this->parse($pdf)[1][$ref] ?? [];
		$entries = [];
		foreach ($object as $element) {
			if ($element[0] !== '<<' || !is_array($element[1])) {
				continue;
			}

			$count = count($element[1]);
			for ($index = 0; $index + 1 < $count; $index += 2) {
				[$key, $value] = [$element[1][$index], $element[1][$index + 1]];
				if ($key[0] === '/' && $value[0] === '<') {
					$entries[$key[1]] = (string)hex2bin($value[1]);
				}
			}
		}

		return $entries;
	}

	/** The trailer of `$pdf`, for asserting on what the rewrite did and did not keep. */
	private function trailerOf(string $pdf): array {
		return $this->parse($pdf)[0]['trailer'];
	}

	/** Pages in `$pdf`, as the renderer's own importer counts them. */
	private function pageCountOf(string $pdf): int {
		$document = $this->newPdfDocument();

		return $document->getSourcePageCount($document->setImportSourceData($pdf));
	}

	/** @return array{0: array<string, mixed>, 1: array<string, array<int, array{0: string, 1: mixed, 2?: int, 3?: mixed}>>} */
	private function parse(string $pdf): array {
		return (new Parser(['ignore_filter_errors' => true]))->parse($pdf);
	}
}
