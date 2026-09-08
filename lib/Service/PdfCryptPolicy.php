<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use Com\Tecnick\Pdf\Encrypt\Decrypt;

/**
 * An authenticated cipher plus the rules for what it applies to.
 *
 * The cipher alone is not enough to decrypt a document, because a PDF's `/Encrypt`
 * dictionary can exempt whole categories of content from it. `/StmF /Identity` leaves
 * streams in the clear while strings stay encrypted; `/StrF /Identity` does the reverse;
 * `/EncryptMetadata false` exempts the XMP packet only. Applying the cipher to content
 * that was never enciphered produces bytes that decode to nothing, which surfaces much
 * later as a blank page rather than as an error - so the exemptions travel with the key.
 *
 * @see PdfDecryptor for how this is derived and used.
 */
final class PdfCryptPolicy {

	public function __construct(
		private Decrypt $cipher,
		private bool $encryptsStreams,
		private bool $encryptsStrings,
		private bool $encryptsMetadata,
	) {
	}

	/**
	 * The plaintext of `$data`, which belongs to object number `$objectNumber`.
	 *
	 * The object number is part of the key for every mode below AES-256, which is why a
	 * stream cannot be decrypted without knowing which object it came out of.
	 */
	public function decrypt(string $data, int $objectNumber): string {
		return $this->cipher->decryptString($data, $objectNumber);
	}

	public function encryptsStreams(): bool {
		return $this->encryptsStreams;
	}

	public function encryptsStrings(): bool {
		return $this->encryptsStrings;
	}

	public function encryptsMetadata(): bool {
		return $this->encryptsMetadata;
	}
}
