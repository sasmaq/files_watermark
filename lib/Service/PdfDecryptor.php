<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Service;

use Com\Tecnick\Pdf\Encrypt\Decrypt;
use Com\Tecnick\Pdf\Parser\Parser;

/**
 * Rewrites a PDF that is encrypted with an **empty user password** into the equivalent
 * plaintext document, so the renderer can import it.
 *
 * This is the case the app kept meeting and could not serve. A document locked with an
 * empty user password is not protected: every reader opens it without prompting, because
 * the empty string *is* the password. The encryption is there only to carry the
 * permission flags in `/P` - "do not print", "do not copy" - which are advisory bits a
 * viewer chooses to honour, not a lock. Office suites and scanner firmware emit these by
 * the thousand. To a parser, though, such a file is simply encrypted, and tc-lib-pdf
 * refuses it ({@see \Com\Tecnick\Pdf\Import\SourceDocument}) along with every genuinely
 * password-protected one.
 *
 * **A real password is still refused, and deliberately.** {@see decrypt()} authenticates
 * with the empty string and nothing else; it never guesses, never tries the owner
 * password as a bypass, and returns `null` the moment authentication fails. Permission
 * flags are dropped, which is the honest consequence of watermarking a file at all - the
 * output is a new document, and its flags would be the renderer's, not the source's.
 *
 * The cryptography is `tecnickcom/tc-lib-pdf-encrypt`, already present as a dependency of
 * tc-lib-pdf and until now used only to *write* encrypted files. Its `Decrypt` class
 * supplies key derivation and per-object ciphers for the standard security handler; what
 * it does not do - and what this class is - is walk a parsed document, decrypt every
 * string and stream in it, and serialise the result back into a well-formed file. Pure
 * PHP, in keeping with the rest of this app: no process is spawned, `qpdf --decrypt` is
 * not coming back.
 *
 * ## What is supported
 *
 * The standard security handler in all five of its revisions: RC4-40 (`/V 1`), RC4-128
 * (`/V 2`), AES-128 (`/V 4`, `/CFM /AESV2`), and AES-256 in both R5 and R6 - nominally
 * `/V 5`, though the cipher is read from `/CFM` and the revision from `/R`, because
 * producers that write `/V 6` for PDF 2.0 exist and tc-lib-pdf's own writer is one of them.
 * `/Identity` crypt filters, `/EncryptMetadata false`, and cross-reference streams with
 * object streams all round-trip. The public-key handler (`/Filter /Adobe.PubSec`) is not:
 * it needs a recipient's private key, which no server-side watermarker has.
 *
 * ## What the output is
 *
 * A freshly assembled file, not a patched one. Every top-level object is decrypted and
 * re-serialised in place, and a new cross-reference **stream** is written over the top,
 * because offsets move the moment AES strips a 16-byte IV from each payload. Object
 * numbers are preserved so that references stay valid without a rewrite pass.
 *
 * Objects that live inside an object stream are not touched individually, and that is not
 * an oversight: PDF 32000-1 §7.6.2 says strings in an object stream are covered by the
 * encryption of the stream itself and are never encrypted a second time. Decrypting the
 * `/ObjStm` payload therefore decrypts everything in it, and the compressed entries can be
 * copied into the new cross-reference stream verbatim - same object stream, same index.
 */
final class PdfDecryptor {

	/**
	 * The only password this class will ever try.
	 *
	 * Named rather than inlined because the constant is the policy: see the class
	 * docblock for why nothing else belongs here.
	 */
	private const EMPTY_PASSWORD = '';

	/** `/Filter` value of the standard (password) security handler. */
	private const STANDARD_HANDLER = 'Standard';

	/**
	 * The plaintext equivalent of `$raw`, or `null` when this class cannot produce one.
	 *
	 * `null` is returned - never an exception - for every "not our case": the document
	 * is not encrypted at all, it uses the public-key handler, it is locked with a real
	 * password, or it is malformed past the point of being read. The caller already has
	 * a message for a PDF it cannot open, and this class has nothing to add to it.
	 */
	public function decrypt(string $raw): ?string {
		try {
			// decode_streams: the payloads are ciphertext, so inflating them here would
			// fail on every one. They are decrypted first and decoded later, by the
			// importer, from the file this method returns.
			$parser = new Parser(['decode_streams' => false, 'ignore_filter_errors' => true]);
			[$xref, $objects] = $parser->parse($raw);
		} catch (\Throwable) {
			return null;
		}

		$encryptRef = (string)($xref['trailer']['encrypt'] ?? '');
		if ($encryptRef === '' || !isset($objects[$encryptRef])) {
			return null;
		}

		$fileId = self::binaryFileId($xref['trailer']['id'][0] ?? '');
		$handler = $this->openHandler(self::dictionary($objects[$encryptRef]), $fileId);
		if ($handler === null) {
			return null;
		}

		try {
			return $this->rewrite($xref, $objects, $encryptRef, $handler);
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Authenticate the empty user password against `$encrypt` and return the cipher.
	 *
	 * `null` means "not a document this class can open": the public-key handler, a
	 * cipher revision outside the standard five, or - the common and intended case - a
	 * document whose user password is not empty.
	 *
	 * @param array<string, array{0: string, 1: mixed, 2?: int, 3?: mixed}> $encrypt the `/Encrypt` dictionary
	 */
	private function openHandler(array $encrypt, string $fileId): ?PdfCryptPolicy {
		if (self::nameOf($encrypt['Filter'] ?? null) !== self::STANDARD_HANDLER) {
			// Adobe.PubSec and friends key off a recipient certificate the server does
			// not hold. There is no empty-password equivalent to try.
			return null;
		}

		$version = self::intOf($encrypt['V'] ?? null);
		$revision = self::intOf($encrypt['R'] ?? null);
		$method = self::cryptMethod($encrypt, $version);
		if ($method === null) {
			return null;
		}

		[$mode, $keyBits] = $method;
		$encryptMetadata = self::boolOf($encrypt['EncryptMetadata'] ?? null, true);

		$dictionary = [
			'V' => $version,
			'Length' => $keyBits,
			'O' => self::rawString($encrypt['O'] ?? null),
			'U' => self::rawString($encrypt['U'] ?? null),
			'OE' => self::rawString($encrypt['OE'] ?? null),
			'UE' => self::rawString($encrypt['UE'] ?? null),
			'P' => self::unsignedPermissions(self::intOf($encrypt['P'] ?? null)),
			'mode' => $mode,
			'EncryptMetadata' => $encryptMetadata,
		];

		$cipher = null;
		foreach (self::keySalts($fileId, $revision, $encryptMetadata) as $salt) {
			$cipher = self::authenticated($dictionary + ['fileid' => $salt]);
			if ($cipher instanceof Decrypt) {
				break;
			}
		}

		if (!$cipher instanceof Decrypt) {
			// A real password. Refusing here is the point of the class, not a
			// limitation of it.
			return null;
		}

		return new PdfCryptPolicy(
			$cipher,
			// /StmF and /StrF only exist from /V 4; before that everything is enciphered.
			$version < 4 || self::nameOf($encrypt['StmF'] ?? null) !== 'Identity',
			$version < 4 || self::nameOf($encrypt['StrF'] ?? null) !== 'Identity',
			$encryptMetadata,
		);
	}

	/**
	 * The `Decrypt` mode and key length in bits for this `/Encrypt` dictionary, or
	 * `null` when the algorithm is one the library has no cipher for.
	 *
	 * The mapping is not one-to-one with `/V`, which is why it needs a method. `/V 4`
	 * defers the choice to a named crypt filter, so a `/V 4` document may be AES-128 or
	 * RC4-128 depending on `/CFM`, and `/V 5` splits on `/R` between two key-derivation
	 * schemes that share a cipher. Key lengths are pinned by the algorithm rather than
	 * read from `/Length` wherever the spec fixes them, because a crypt filter's own
	 * `/Length` is specified in *bytes* and is written in *bits* by enough producers
	 * that trusting it means guessing.
	 *
	 * @param array<string, array{0: string, 1: mixed, 2?: int, 3?: mixed}> $encrypt
	 *
	 * @return array{0: int, 1: int}|null `[mode, key length in bits]`
	 */
	private static function cryptMethod(array $encrypt, int $version): ?array {
		if ($version === 1) {
			return [0, 40];
		}

		if ($version === 2) {
			return [1, self::keyBits($encrypt)];
		}

		if ($version < 4) {
			return null;
		}

		$revision = self::intOf($encrypt['R'] ?? null);

		return match (self::defaultCryptFilterMethod($encrypt)) {
			'AESV2' => [2, 128],
			// R5 was Adobe's extension to PDF 1.7 and R6 is the ISO 32000-2 form of the
			// same cipher; they differ only in how the password is hashed into the key.
			'AESV3' => [$revision === 5 ? 3 : 4, 256],
			'V2', 'RC4' => [1, self::keyBits($encrypt)],
			// A /V 5 document is required to name a crypt filter, but a producer that
			// omits /CF has still said which cipher it means by saying which revision.
			default => match ($revision) {
				5 => [3, 256],
				6 => [4, 256],
				default => null,
			},
		};
	}

	/**
	 * `/CFM` of the crypt filter named by `/StmF`, upper-cased for comparison.
	 *
	 * Streams and strings may in principle name different filters. They do not in
	 * practice, and the two cannot be handled separately anyway: the file-encryption key
	 * is one key, and `Decrypt` is built around a single mode.
	 *
	 * @param array<string, array{0: string, 1: mixed, 2?: int, 3?: mixed}> $encrypt
	 */
	private static function defaultCryptFilterMethod(array $encrypt): string {
		$name = self::nameOf($encrypt['StmF'] ?? null);
		if ($name === '' || $name === 'Identity') {
			// Streams in the clear, strings not: the strings still need a cipher, and
			// /StrF names the filter that holds it.
			$name = self::nameOf($encrypt['StrF'] ?? null);
		}

		$filters = self::dictionaryValue($encrypt['CF'] ?? null);
		$filter = self::dictionaryValue($filters[$name] ?? null);

		return strtoupper(self::nameOf($filter['CFM'] ?? null));
	}

	/**
	 * `/Length` in bits, defaulting to the 40 the spec gives it.
	 *
	 * @param array<string, array{0: string, 1: mixed, 2?: int, 3?: mixed}> $encrypt
	 */
	private static function keyBits(array $encrypt): int {
		$length = self::intOf($encrypt['Length'] ?? null);
		if ($length === 0) {
			return 40;
		}

		// Some producers write the crypt filter's byte count into the top-level entry.
		// Nothing legitimate asks for a 5-bit key, so a value that small is bytes.
		return $length < 40 ? $length * 8 : $length;
	}

	/**
	 * Build a cipher from `$dictionary` and return it only if the empty password opens
	 * it.
	 *
	 * @param array<string, mixed> $dictionary
	 */
	private static function authenticated(array $dictionary): ?Decrypt {
		try {
			/** @psalm-suppress ArgumentTypeCoercion */
			$cipher = new Decrypt($dictionary);

			return $cipher->authenticate(self::EMPTY_PASSWORD) ? $cipher : null;
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * The salts to hash into the key, in the order they should be tried.
	 *
	 * Normally there is one, and it is the file identifier. The exception is a document
	 * that is revision 4 or later with `/EncryptMetadata false`, where PDF 32000-1
	 * algorithm 2 step (f) appends `FF FF FF FF` to the hash input - a step
	 * `Decrypt::deriveKeyR24()` does not perform, so the conformant form has to be built
	 * here by extending the file identifier. Key derivation concatenates the two, so the
	 * hash input is identical and the correction needs no fork of the library.
	 *
	 * Both forms are offered because both exist in the wild. Acrobat, Word and
	 * LibreOffice take step (f); tc-lib-pdf-encrypt's own writer skips it, and it is not
	 * the only one - the step was added late and is easy to miss. Trying the conformant
	 * salt first and falling back costs one MD5 chain on the rare document that needs
	 * it, and is the difference between opening those files and reporting a password
	 * that was never set.
	 *
	 * Revisions 5 and 6 derive their key from the password and a salt in `/U` alone - the
	 * file identifier never enters it - so the step is scoped to revision 4, the only one
	 * where it can make a difference, and those produce a single candidate.
	 *
	 * @return list<string>
	 */
	private static function keySalts(string $fileId, int $revision, bool $encryptMetadata): array {
		if ($revision === 4 && !$encryptMetadata) {
			return [$fileId . "\xFF\xFF\xFF\xFF", $fileId];
		}

		return [$fileId];
	}

	/**
	 * `/P` as the unsigned 32-bit value the key derivation expects.
	 *
	 * The permission flags are a signed 32-bit integer with the high bits set, so they
	 * are almost always written negative - `-3904` and the like. `Compute` turns the
	 * value into bytes with `sprintf('%032b')`, which on a 64-bit PHP renders a negative
	 * number as 64 binary digits and silently slices the wrong four bytes out of it.
	 * Handing it the two's-complement equivalent produces exactly 32 digits and the
	 * bytes the spec asks for.
	 */
	private static function unsignedPermissions(int $permissions): int {
		return $permissions < 0 ? $permissions + 0x1_0000_0000 : $permissions;
	}

	/**
	 * The first `/ID` string as raw bytes.
	 *
	 * The parser only ever reports `/ID` in its hexadecimal form, which is what every
	 * producer writes, and hands back the digits without the angle brackets. An odd
	 * digit count is legal and means a trailing zero nibble.
	 */
	private static function binaryFileId(string $hex): string {
		$digits = (string)preg_replace('/[^0-9A-Fa-f]/', '', $hex);
		if ($digits === '') {
			return '';
		}

		if (strlen($digits) % 2 === 1) {
			$digits .= '0';
		}

		$binary = hex2bin($digits);

		return $binary === false ? '' : $binary;
	}

	/**
	 * Assemble the plaintext document.
	 *
	 * Every object the cross-reference table places at a byte offset is decrypted and
	 * written out again under its own number, so no reference anywhere in the document
	 * needs rewriting. Two kinds are dropped rather than copied: the `/Encrypt`
	 * dictionary, which now describes nothing, and any `/Type /XRef` stream, because the
	 * cross-reference is rebuilt from scratch below and a stale copy of the old one
	 * would point into a file that no longer exists.
	 *
	 * @param array{trailer: array{encrypt?: string, id: array<int, string>, info: string, root: string, size: int}, xref: array<string, int|string>} $xref
	 * @param array<string, array<int, array{0: string, 1: mixed, 2?: int, 3?: mixed}>> $objects
	 */
	private function rewrite(array $xref, array $objects, string $encryptRef, PdfCryptPolicy $policy): string {
		$pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";

		/** @var array<int, array{0: int, 1: int}> $inUse object number => [offset, generation] */
		$inUse = [];
		/** @var array<int, array{0: int, 1: int}> $compressed object number => [container, index] */
		$compressed = [];

		foreach ($xref['xref'] as $ref => $location) {
			[$number, $generation] = self::reference($ref);
			if ($number === null) {
				continue;
			}

			if (is_string($location)) {
				$container = self::compressedLocation($location);
				if ($container !== null) {
					$compressed[$number] = $container;
				}
				continue;
			}

			$object = $objects[$ref] ?? null;
			if ($ref === $encryptRef || !is_array($object) || $object === []) {
				continue;
			}

			$body = $this->objectBody($object, $number, $policy);
			if ($body === null) {
				continue;
			}

			$inUse[$number] = [strlen($pdf), $generation];
			$pdf .= $number . ' ' . $generation . " obj\n" . $body . "\nendobj\n";
		}

		return $pdf . $this->crossReferenceStream($pdf, $xref['trailer'], $inUse, $compressed);
	}

	/**
	 * The serialised body of one indirect object with its strings and stream decrypted,
	 * or `null` when the object should not be carried over at all.
	 *
	 * @param array<int, array{0: string, 1: mixed, 2?: int, 3?: mixed}> $object
	 */
	private function objectBody(array $object, int $number, PdfCryptPolicy $policy): ?string {
		$dictionary = null;
		$stream = null;

		foreach ($object as $element) {
			if (!is_array($element)) {
				continue;
			}

			if ($element[0] === '<<' && $dictionary === null) {
				$dictionary = $element;
			} elseif ($element[0] === 'stream' && is_string($element[1])) {
				$stream = $element[1];
			}
		}

		if ($dictionary === null) {
			// A bare value - a stream's /Length held in its own object, most often.
			// Nothing in it is enciphered that is not a string, so the ordinary walk
			// covers it.
			return self::serialise($this->decryptStrings($object, $number, $policy->encryptsStrings(), $policy));
		}

		$entries = self::dictionary([$dictionary]);
		$type = self::nameOf($entries['Type'] ?? null);
		if ($type === 'XRef') {
			return null;
		}

		if ($stream === null) {
			return self::element($this->decryptElement($dictionary, $number, self::encryptsDictionary($entries, $policy), $policy));
		}

		$plaintext = $this->decryptStream($stream, $entries, $type, $number, $policy);
		$decrypted = $this->decryptElement($dictionary, $number, self::encryptsDictionary($entries, $policy), $policy);

		return self::element(self::withLength($decrypted, strlen($plaintext)))
			. "\nstream\n" . $plaintext . "\nendstream";
	}

	/**
	 * The plaintext of one stream payload.
	 *
	 * The payload the parser hands back runs to the `endstream` keyword, so it carries
	 * whatever end-of-line bytes the producer put between the data and that keyword.
	 * They have to go before the cipher sees it: AES rejects a length that is not a
	 * multiple of its block size, and RC4 would happily turn the extra bytes into
	 * trailing garbage inside the decoded stream. `/Length` is the authority on where
	 * the data actually ends.
	 *
	 * @param array<string, array{0: string, 1: mixed, 2?: int, 3?: mixed}> $entries the stream dictionary
	 */
	private function decryptStream(
		string $payload,
		array $entries,
		string $type,
		int $number,
		PdfCryptPolicy $policy,
	): string {
		$declared = self::intOf($entries['Length'] ?? null);
		if ($declared > 0 && $declared < strlen($payload)) {
			$payload = substr($payload, 0, $declared);
		} else {
			$payload = rtrim($payload, "\r\n");
		}

		if (!$policy->encryptsStreams()) {
			return $payload;
		}

		// The XMP packet is exempt when /EncryptMetadata is false, and is the only thing
		// that is - which is what makes such a file readable by a search indexer that
		// has no password.
		//
		// The second half of the test is not a guess about what the bytes might be. A
		// packet that opens with its own literal marker cannot have come out of a
		// cipher, so a document declaring /EncryptMetadata true over a stream that
		// begins `<?xpacket` has mis-declared itself - which tc-lib-pdf's own writer
		// does, and it is not alone. Decrypting plaintext would corrupt the only part of
		// the file that was readable to begin with.
		if ($type === 'Metadata' && (!$policy->encryptsMetadata() || str_starts_with($payload, '<?xpacket'))) {
			return $payload;
		}

		// A /Crypt filter naming /Identity is the per-stream way of saying the same
		// thing. It has to be first in the filter chain, and it is the only filter this
		// class has an opinion about.
		if (self::hasIdentityCryptFilter($entries)) {
			return $payload;
		}

		return $policy->decrypt($payload, $number);
	}

	/**
	 * Whether the strings inside this dictionary are enciphered.
	 *
	 * A signature dictionary's `/Contents` holds the PKCS#7 blob that signs the file's
	 * own bytes, and is exempt from encryption for the obvious reason - it cannot be
	 * covered by a transformation applied after it was computed. The blob is recognised
	 * by the `/ByteRange` that accompanies it, which no other dictionary carries.
	 *
	 * @param array<string, array{0: string, 1: mixed, 2?: int, 3?: mixed}> $entries
	 */
	private static function encryptsDictionary(array $entries, PdfCryptPolicy $policy): bool {
		return $policy->encryptsStrings() && !isset($entries['ByteRange']);
	}

	/**
	 * `$elements` with every string in them decrypted.
	 *
	 * @param array<int, array{0: string, 1: mixed, 2?: int, 3?: mixed}> $elements
	 *
	 * @return array<int, array{0: string, 1: mixed, 2?: int, 3?: mixed}>
	 */
	private function decryptStrings(array $elements, int $number, bool $enabled, PdfCryptPolicy $policy): array {
		foreach ($elements as $index => $element) {
			if (is_array($element)) {
				$elements[$index] = $this->decryptElement($element, $number, $enabled, $policy);
			}
		}

		return $elements;
	}

	/**
	 * One element with its strings - and the strings of everything nested inside it -
	 * decrypted.
	 *
	 * Decrypted bytes are emitted as a hexadecimal string whatever form they arrived in.
	 * Plaintext is arbitrary binary, and re-escaping it as a literal `(...)` means
	 * getting backslashes, unbalanced parentheses and end-of-line normalisation right on
	 * data that has no reason to be text at all; the hexadecimal form has no escapes to
	 * get wrong.
	 *
	 * @param array{0: string, 1: mixed, 2?: int, 3?: mixed} $element
	 *
	 * @return array{0: string, 1: mixed, 2?: int, 3?: mixed}
	 */
	private function decryptElement(array $element, int $number, bool $enabled, PdfCryptPolicy $policy): array {
		if ($element[0] === '<<' && is_array($element[1])) {
			$entries = self::dictionary([$element]);
			$element[1] = $this->decryptStrings(
				$element[1],
				$number,
				$enabled && self::encryptsDictionary($entries, $policy),
				$policy,
			);

			return $element;
		}

		if ($element[0] === '[' && is_array($element[1])) {
			$element[1] = $this->decryptStrings($element[1], $number, $enabled, $policy);

			return $element;
		}

		if (!$enabled || !is_string($element[1])) {
			return $element;
		}

		if ($element[0] === '(') {
			return ['<', bin2hex($policy->decrypt(self::unescapeLiteral($element[1]), $number))];
		}

		if ($element[0] === '<') {
			return ['<', bin2hex($policy->decrypt(self::binaryFileId($element[1]), $number))];
		}

		return $element;
	}

	/**
	 * The new cross-reference stream, plus the trailer that points at it.
	 *
	 * A stream rather than a classic `xref` table, and not by preference: a table has no
	 * way to say "this object lives at index 3 of object stream 42", so a document whose
	 * objects are compressed cannot be described by one. Those entries are carried over
	 * unchanged - the object streams themselves are re-emitted intact, only decrypted,
	 * so every index inside them still points where it did.
	 *
	 * `/Encrypt` is not carried over, which is the whole point, and neither are the
	 * permission flags it held.
	 *
	 * @param array{encrypt?: string, id: array<int, string>, info: string, root: string, size: int} $trailer
	 * @param array<int, array{0: int, 1: int}> $inUse object number => [offset, generation]
	 * @param array<int, array{0: int, 1: int}> $compressed object number => [container, index]
	 */
	private function crossReferenceStream(string $pdf, array $trailer, array $inUse, array $compressed): string {
		$offset = strlen($pdf);
		$self = max([0, ...array_keys($inUse), ...array_keys($compressed)]) + 1;
		$inUse[$self] = [$offset, 0];

		// /W [1 4 4]: a type byte, then two fields wide enough for any offset a PDF this
		// side of 4 GiB can hold and any generation or in-stream index that can be
		// written. Sizing them to the document would save a few hundred bytes and add a
		// second thing to get wrong.
		$entries = '';
		for ($number = 0; $number <= $self; $number++) {
			if (isset($inUse[$number])) {
				$entries .= pack('CNN', 1, $inUse[$number][0], $inUse[$number][1]);
			} elseif (isset($compressed[$number])) {
				$entries .= pack('CNN', 2, $compressed[$number][0], $compressed[$number][1]);
			} else {
				// Free, and the head of the list points nowhere: object 0 is the only
				// free entry any reader looks at.
				$entries .= pack('CNN', 0, 0, 65535);
			}
		}

		$stream = (string)gzcompress($entries, 9);
		$dictionary = '<< /Type /XRef /Size ' . ($self + 1)
			. ' /W [1 4 4] /Index [0 ' . ($self + 1) . ']'
			. ' /Root ' . self::indirect($trailer['root'] ?? '')
			. self::optionalInfo($trailer['info'] ?? '')
			. self::optionalId($trailer['id'] ?? [])
			. ' /Filter /FlateDecode /Length ' . strlen($stream) . ' >>';

		return $self . " 0 obj\n" . $dictionary . "\nstream\n" . $stream . "\nendstream\nendobj\n"
			. 'startxref' . "\n" . $offset . "\n" . '%%EOF' . "\n";
	}

	private static function optionalInfo(string $info): string {
		return $info === '' ? '' : ' /Info ' . self::indirect($info);
	}

	/**
	 * The original `/ID`, kept because it identifies the document rather than its
	 * encryption and readers use it to recognise incremental updates of the same file.
	 *
	 * @param array<int, string> $id
	 */
	private static function optionalId(array $id): string {
		if (($id[0] ?? '') === '') {
			return '';
		}

		return ' /ID [<' . $id[0] . '><' . ($id[1] ?? $id[0]) . '>]';
	}

	/** `"12_0"` as the reference `12 0 R`. */
	private static function indirect(string $ref): string {
		[$number, $generation] = self::reference($ref);

		return $number === null ? 'null' : $number . ' ' . $generation . ' R';
	}

	/**
	 * `"12_0"` split into its object and generation numbers, or `[null, 0]` when the
	 * string is not a reference at all.
	 *
	 * @return array{0: int|null, 1: int}
	 */
	private static function reference(string $ref): array {
		if (preg_match('/^(\d+)_(\d+)$/', $ref, $parts) !== 1) {
			return [null, 0];
		}

		return [(int)$parts[1], (int)$parts[2]];
	}

	/**
	 * `"42_0_3"` - the parser's locator for a compressed object - as the container
	 * object number and the index within it.
	 *
	 * @return array{0: int, 1: int}|null
	 */
	private static function compressedLocation(string $locator): ?array {
		if (preg_match('/^(\d+)_\d+_(\d+)$/', $locator, $parts) !== 1) {
			return null;
		}

		return [(int)$parts[1], (int)$parts[2]];
	}

	/**
	 * `$dictionary` with its `/Length` set to `$length`.
	 *
	 * Always rewritten, never adjusted: decryption changes the payload's size in every
	 * mode - AES strips an initialisation vector and its padding - and the declared
	 * length may have been an indirect reference to an object that is about to be stale.
	 * A direct integer settles both.
	 *
	 * @param array{0: string, 1: mixed, 2?: int, 3?: mixed} $dictionary
	 *
	 * @return array{0: string, 1: mixed, 2?: int, 3?: mixed}
	 */
	private static function withLength(array $dictionary, int $length): array {
		if (!is_array($dictionary[1])) {
			return $dictionary;
		}

		$entries = [];
		$count = count($dictionary[1]);
		for ($index = 0; $index < $count; $index += 2) {
			$key = $dictionary[1][$index] ?? null;
			if (!is_array($key) || $key[0] !== '/') {
				continue;
			}

			if ($key[1] !== 'Length') {
				$entries[] = $key;
				$entries[] = $dictionary[1][$index + 1] ?? ['null', 'null'];
			}
		}

		$entries[] = ['/', 'Length'];
		$entries[] = ['numeric', (string)$length];
		$dictionary[1] = $entries;

		return $dictionary;
	}

	/**
	 * A dictionary's entries as a name-keyed map, from the flat key/value list the
	 * parser produces. The first occurrence of a duplicated key wins, which is what a
	 * reader does with one.
	 *
	 * @param array<int, array{0: string, 1: mixed, 2?: int, 3?: mixed}> $object
	 *
	 * @return array<string, array{0: string, 1: mixed, 2?: int, 3?: mixed}>
	 */
	private static function dictionary(array $object): array {
		foreach ($object as $element) {
			if (is_array($element) && $element[0] === '<<' && is_array($element[1])) {
				return self::dictionaryValue($element);
			}
		}

		return [];
	}

	/**
	 * The same for a value already known to be a dictionary element.
	 *
	 * @param mixed $element
	 *
	 * @return array<string, array{0: string, 1: mixed, 2?: int, 3?: mixed}>
	 */
	private static function dictionaryValue($element): array {
		if (!is_array($element) || ($element[0] ?? '') !== '<<' || !is_array($element[1])) {
			return [];
		}

		$entries = [];
		$count = count($element[1]);
		for ($index = 0; $index + 1 < $count; $index += 2) {
			$key = $element[1][$index];
			$value = $element[1][$index + 1];
			if (is_array($key) && $key[0] === '/' && is_string($key[1]) && is_array($value)) {
				$entries[$key[1]] ??= $value;
			}
		}

		return $entries;
	}

	/**
	 * Whether the stream's filter chain opens with a `/Crypt` filter set to `/Identity`,
	 * the per-stream opt-out of document encryption.
	 *
	 * @param array<string, array{0: string, 1: mixed, 2?: int, 3?: mixed}> $entries
	 */
	private static function hasIdentityCryptFilter(array $entries): bool {
		$filter = $entries['Filter'] ?? null;
		$first = is_array($filter) && $filter[0] === '[' && is_array($filter[1]) ? ($filter[1][0] ?? null) : $filter;
		if (self::nameOf($first) !== 'Crypt') {
			return false;
		}

		$parms = $entries['DecodeParms'] ?? null;
		$firstParms = is_array($parms) && $parms[0] === '[' && is_array($parms[1]) ? ($parms[1][0] ?? null) : $parms;
		$name = self::nameOf(self::dictionaryValue($firstParms)['Name'] ?? null);

		// /Name defaults to /Identity when the crypt filter's parameters omit it.
		return $name === '' || $name === 'Identity';
	}

	/**
	 * One element written back out as PDF syntax.
	 *
	 * @param array{0: string, 1: mixed, 2?: int, 3?: mixed} $element
	 */
	private static function element(array $element): string {
		$value = $element[1] ?? '';

		return match ($element[0]) {
			// Names, literal strings and hexadecimal strings come back from the parser
			// in their source form - escapes intact, hexadecimal digits unpacked - so
			// putting the delimiters back is the whole of the work.
			'/' => '/' . (is_string($value) ? $value : ''),
			'(' => '(' . (is_string($value) ? $value : '') . ')',
			'<' => '<' . (is_string($value) ? $value : '') . '>',
			'[' => '[' . self::elements($value) . ']',
			'<<' => '<<' . self::elements($value) . '>>',
			'objref' => is_string($value) ? self::indirect($value) : 'null',
			'numeric', 'boolean' => is_string($value) ? $value : 'null',
			'null' => 'null',
			default => '',
		};
	}

	/** @param mixed $elements */
	private static function elements($elements): string {
		if (!is_array($elements)) {
			return '';
		}

		$out = [];
		foreach ($elements as $element) {
			if (is_array($element)) {
				$out[] = self::element($element);
			}
		}

		return ' ' . implode(' ', $out) . ' ';
	}

	/**
	 * A whole object's elements written back out, for the rare object that is a bare
	 * value rather than a dictionary.
	 *
	 * @param array<int, array{0: string, 1: mixed, 2?: int, 3?: mixed}> $elements
	 */
	private static function serialise(array $elements): string {
		return trim(self::elements($elements));
	}

	/**
	 * A literal `(...)` string's source form resolved to the bytes it denotes.
	 *
	 * Needed because the cipher operates on the string's *value*, not on the way it was
	 * written: `\(`, `\101`, and a backslash before a real newline are all encoding, and
	 * feeding them to the cipher decrypts the wrong bytes.
	 */
	private static function unescapeLiteral(string $literal): string {
		$out = '';
		$length = strlen($literal);

		for ($index = 0; $index < $length; $index++) {
			if ($literal[$index] !== '\\') {
				$out .= $literal[$index];
				continue;
			}

			$next = $literal[++$index] ?? '';
			if ($next >= '0' && $next <= '7') {
				// Up to three octal digits, fewer if the run ends early.
				$octal = $next;
				while (strlen($octal) < 3 && ($literal[$index + 1] ?? '') >= '0' && ($literal[$index + 1] ?? '') <= '7') {
					$octal .= $literal[++$index];
				}

				$out .= chr((int)octdec($octal) & 0xFF);
				continue;
			}

			// A backslash before an end-of-line is a line continuation and contributes
			// nothing, CRLF included; before anything else the character stands for
			// itself, so an unknown escape drops only the backslash.
			if ($next === "\r" && ($literal[$index + 1] ?? '') === "\n") {
				$index++;
				continue;
			}

			$out .= match ($next) {
				'n' => "\n",
				'r' => "\r",
				't' => "\t",
				'b' => "\x08",
				'f' => "\x0C",
				"\n", "\r" => '',
				default => $next,
			};
		}

		return $out;
	}

	/** The `/Name` value of a name element, or `''` for anything else. */
	private static function nameOf(mixed $element): string {
		if (!is_array($element) || ($element[0] ?? '') !== '/' || !is_string($element[1] ?? null)) {
			return '';
		}

		return $element[1];
	}

	/** The value of a numeric element, or `0` for anything else. */
	private static function intOf(mixed $element): int {
		if (!is_array($element) || ($element[0] ?? '') !== 'numeric' || !is_scalar($element[1] ?? null)) {
			return 0;
		}

		return (int)$element[1];
	}

	private static function boolOf(mixed $element, bool $default): bool {
		if (!is_array($element) || ($element[0] ?? '') !== 'boolean') {
			return $default;
		}

		return ($element[1] ?? '') === 'true';
	}

	/** The raw bytes of a string element, whichever form it was written in. */
	private static function rawString(mixed $element): string {
		if (!is_array($element) || !is_string($element[1] ?? null)) {
			return '';
		}

		return match ($element[0]) {
			'(' => self::unescapeLiteral($element[1]),
			'<' => self::binaryFileId($element[1]),
			default => '',
		};
	}
}
