<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use Com\Tecnick\Pdf\Encrypt\Encrypt;

/**
 * Builds an encrypted PDF 1.5 whose catalogue, page tree and page all live inside an
 * **object stream**, indexed by a cross-reference **stream**.
 *
 * This is the shape {@see \OCA\FilesWatermark\Service\PdfDecryptor} has to get right and
 * that {@see PdfFixtures::writeEncryptedPdf()} cannot produce: tc-lib-pdf writes a classic
 * `xref` table with every object at top level whatever it is asked for, so a fixture built
 * with it exercises only the easy half of the rewrite. Compressed objects are the half
 * with the real constraint - a decrypted file cannot describe them with a classic table,
 * and their entries have to be carried across pointing at the same container and index.
 *
 * It is also the shape that proves the rule the rewrite depends on. `/Title` here is
 * encrypted once, as part of the object stream's own payload, and is **not** enciphered a
 * second time as a string (PDF 32000-1 §7.6.2). A decryptor that treated it as an ordinary
 * string would decrypt it twice and produce noise, and the test would see it.
 *
 * Built byte by byte, in the manner of {@see CompressedXrefFixture}, because the offsets
 * have to be real - a reader seeks to `startxref` and works from what it finds there. The
 * ciphertext comes from the same library that decrypts it, so this suite spawns no
 * processes either.
 */
trait EncryptedObjectStreamFixture {

	/** The string sealed inside the object stream, for a test to look for afterwards. */
	private const OBJECT_STREAM_TITLE = 'Quarterly figures';

	/** The text drawn on the fixture's single page. */
	private const OBJECT_STREAM_TEXT = 'Encrypted object stream';

	/**
	 * @param string $userPassword empty for the permission-flags-only case
	 * @param int $mode cipher, in the library's numbering; see {@see PdfFixtures::writeEncryptedPdf()}
	 * @param bool $encryptMetadata false adds `/EncryptMetadata false`, which changes key derivation
	 */
	private function buildEncryptedObjectStreamPdf(
		string $userPassword = '',
		int $mode = 2,
		bool $encryptMetadata = true,
	): string {
		$encrypt = new Encrypt(
			enabled: true,
			file_id: md5('files_watermark object stream fixture'),
			mode: $mode,
			permissions: ['copy'],
			user_pass: $userPassword,
			owner_pass: 'owner-pass',
			encryptMetadata: $encryptMetadata,
		);

		// Objects 1, 2, 3 and 5 are packed into object stream 6. Object 4 is a content
		// stream and object 9 an information dictionary, and neither may be packed: a
		// stream cannot live in an object stream, and nor can anything the trailer
		// points at directly.
		$packed = [
			1 => '<< /Type /Catalog /Pages 2 0 R >>',
			2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
				. '/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R '
				. '/Title (' . self::OBJECT_STREAM_TITLE . ') >>',
			5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
		];

		// An object stream opens with a table of "object number, offset" pairs; /First
		// says where that table ends and the bodies begin.
		$pairs = '';
		$bodies = '';
		foreach ($packed as $number => $body) {
			$pairs .= $number . ' ' . strlen($bodies) . ' ';
			$bodies .= $body . ' ';
		}

		// Compression first, encryption last: the cipher applies to the encoded bytes,
		// which is what /Length then measures.
		$objectStream = $encrypt->encryptString((string)gzcompress($pairs . $bodies, 9), 6);
		$content = $encrypt->encryptString(
			'BT /F1 24 Tf 72 700 Td (' . self::OBJECT_STREAM_TEXT . ") Tj ET\n",
			4,
		);

		$pdf = "%PDF-1.5\n%\xE2\xE3\xCF\xD3\n";
		$offsets = [];
		$emit = function (int $number, string $body) use (&$pdf, &$offsets): void {
			$offsets[$number] = strlen($pdf);
			$pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
		};

		$emit(4, '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream");
		$emit(6, '<< /Type /ObjStm /N ' . count($packed) . ' /First ' . strlen($pairs)
			. ' /Filter /FlateDecode /Length ' . strlen($objectStream) . " >>\nstream\n"
			. $objectStream . "\nendstream");
		// Both string forms in one dictionary: a hexadecimal string, and a literal one
		// whose ciphertext is escaped as a producer would have to escape it. The
		// decryptor has to read the value out of either without tripping over a
		// backslash or a stray parenthesis in the random bytes.
		$emit(9, '<< /Author <' . bin2hex($encrypt->encryptString('Records Office', 9)) . '>'
			. ' /Subject (' . self::escapeLiteral($encrypt->encryptString('Board pack', 9)) . ') >>');

		// The /Encrypt object is written by the library itself, so its /O and /U match
		// the key that enciphered everything above. getPdfEncryptionObj() takes the
		// object counter by reference and returns the next number, which makes this
		// object 7.
		$counter = 6;
		$offsets[7] = strlen($pdf);
		$pdf .= $encrypt->getPdfEncryptionObj($counter);

		return $pdf . self::objectStreamXref($pdf, $offsets, $encrypt);
	}

	/**
	 * The cross-reference stream for the fixture, plus its trailer.
	 *
	 * Type 2 rows are what this fixture exists for: object 1 is not at a byte offset at
	 * all, it is "index 0 of object stream 6". `/W [1 4 2]` sizes the three fields.
	 *
	 * @param array<int, int> $offsets object number => byte offset, for the top-level objects
	 */
	private static function objectStreamXref(string $pdf, array $offsets, Encrypt $encrypt): string {
		$xrefNumber = 8;
		$xrefOffset = strlen($pdf);
		$offsets[$xrefNumber] = $xrefOffset;

		/** @var array<int, int> $packedAt object number => index within object stream 6 */
		$packedAt = [1 => 0, 2 => 1, 3 => 2, 5 => 3];

		$entries = pack('CNn', 0, 0, 65535);
		for ($number = 1; $number <= 9; $number++) {
			if (isset($packedAt[$number])) {
				$entries .= pack('CNn', 2, 6, $packedAt[$number]);
			} elseif (isset($offsets[$number])) {
				$entries .= pack('CNn', 1, $offsets[$number], 0);
			} else {
				$entries .= pack('CNn', 0, 0, 65535);
			}
		}

		$stream = (string)gzcompress($entries, 9);
		$id = $encrypt->convertStringToHexString($encrypt->getEncryptionData()['fileid']);

		return $xrefNumber . " 0 obj\n"
			. '<< /Type /XRef /Size 10 /W [1 4 2] /Index [0 10] /Root 1 0 R /Info 9 0 R'
			. ' /Encrypt 7 0 R /ID [<' . $id . '><' . $id . '>]'
			. ' /Filter /FlateDecode /Length ' . strlen($stream) . " >>\nstream\n"
			. $stream . "\nendstream\nendobj\n"
			. "startxref\n" . $xrefOffset . "\n%%EOF\n";
	}

	/**
	 * Ciphertext escaped for a literal `(...)` string.
	 *
	 * Random bytes contain parentheses and backslashes, and a fixture that pasted them
	 * in raw would produce a file no parser could read - which would look like a
	 * decryptor bug rather than a fixture one.
	 */
	private static function escapeLiteral(string $bytes): string {
		return (string)preg_replace('/([\\\\()\r])/', '\\\\$1', $bytes);
	}
}
