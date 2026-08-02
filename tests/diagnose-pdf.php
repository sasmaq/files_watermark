<?php

declare(strict_types=1);

/**
 * Reports why a specific PDF loses its content when watermarked.
 *
 *   php tests/diagnose-pdf.php /path/to/file.pdf
 *
 * Watermarks the file into a temp copy and inspects both sides: what the source page
 * declares, what reached the imported form, and whether every resource the copied
 * content stream names is still defined. Prints a verdict per page.
 *
 * Written for the "blank page with only the watermark on it" report, where the file is
 * intact by every ordinary measure — the bytes are all still there — and the failure is
 * only visible in how a *reader* resolves the page. Nothing here is part of the app;
 * it is a bench instrument for a file that cannot be shared.
 */

$root = dirname(__DIR__);
$loader = require $root . '/vendor/autoload.php';
if ($loader instanceof \Composer\Autoload\ClassLoader) {
	$loader->addPsr4('OCP\\', $root . '/vendor/nextcloud/ocp/OCP/');
	$loader->addPsr4('NCU\\', $root . '/vendor/nextcloud/ocp/NCU/');
}

use Com\Tecnick\Pdf\Parser\Parser;
use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Service\PdfWatermarker;

$source = $argv[1] ?? '';
if ($source === '' || !is_file($source)) {
	fwrite(STDERR, "usage: php tests/diagnose-pdf.php /path/to/file.pdf\n");
	exit(2);
}

/**
 * A parsed object's dictionary as name => value token.
 *
 * The parser hands back a dictionary as a flat *token list* — `[["/","Type"],
 * ["/","XObject"], ["/","Subtype"], ["/","Form"], …]` — not as pairs, so the pairing
 * has to happen here. Which is convenient for this tool: pairing the tokens in order
 * is exactly what a reader does, so a dictionary with a missing value shows up as the
 * wrong value on a key rather than being silently repaired.
 */
function pairTokens(array $tokens): array {
	$dict = [];
	$count = count($tokens);
	for ($i = 0; $i < $count; $i += 2) {
		$key = $tokens[$i] ?? null;
		if (!is_array($key) || ($key[0] ?? null) !== '/') {
			// Not a name where a key belongs: the dictionary is misaligned from here on.
			break;
		}
		$dict[(string)$key[1]] = $tokens[$i + 1] ?? null;
	}

	return $dict;
}

function objDict(array $obj): array {
	foreach ($obj as $el) {
		if (is_array($el) && ($el[0] ?? null) === '<<' && is_array($el[1] ?? null)) {
			return pairTokens($el[1]);
		}
	}

	return [];
}

/** A dictionary-valued entry, whether it is inline or an indirect reference. */
function subDict(mixed $value, array $objects): array {
	if (is_array($value) && ($value[0] ?? null) === '<<' && is_array($value[1] ?? null)) {
		return pairTokens($value[1]);
	}
	if (is_array($value) && ($value[0] ?? null) === 'objref') {
		return objDict($objects[(string)$value[1]] ?? []);
	}

	return [];
}

/** Content-stream bytes as a reader would see them, decompressed where possible. */
function decoded(string $bytes): string {
	$plain = @gzuncompress($bytes);
	if ($plain === false) {
		$plain = @gzinflate($bytes);
	}

	return $plain === false ? $bytes : $plain;
}

function objStream(array $obj): ?string {
	foreach ($obj as $el) {
		if (is_array($el) && ($el[0] ?? null) === 'stream') {
			return (string)($el[1] ?? '');
		}
	}
	return null;
}

/** Resource names a content stream actually uses, by category. */
function namesUsed(string $content): array {
	$used = [];
	foreach ([
		'Font' => '~/([^\s/<>\[\]()]+)\s+[\d.-]+\s+Tf~',
		'XObject' => '~/([^\s/<>\[\]()]+)\s+Do~',
		'ExtGState' => '~/([^\s/<>\[\]()]+)\s+gs~',
		'ColorSpace' => '~/([^\s/<>\[\]()]+)\s+(?:cs|CS)\b~',
		'Shading' => '~/([^\s/<>\[\]()]+)\s+sh~',
		'Pattern' => '~/([^\s/<>\[\]()]+)\s+scn~',
	] as $type => $pattern) {
		if (preg_match_all($pattern, $content, $m) === 0) {
			continue;
		}
		$used[$type] = array_values(array_unique($m[1]));
	}
	return $used;
}

echo "source: $source (" . number_format((int)filesize($source)) . " bytes)\n";
$head = (string)file_get_contents($source, false, null, 0, 1024);
if (preg_match('/%PDF-([\d.]+)/', $head, $m) === 1) {
	echo "version: PDF $m[1]\n";
}
$raw = (string)file_get_contents($source);
if (preg_match('~/Producer\s*\(([^)]{0,80})~', $raw, $m) === 1) {
	echo 'producer: ' . trim($m[1]) . "\n";
}
echo 'structure: ' . (str_contains($raw, '/Type /XRef') || str_contains($raw, '/Type/XRef')
	? 'cross-reference stream' : 'classic xref table')
	. (str_contains($raw, '/ObjStm') ? ', object streams' : '')
	. (str_contains($raw, '/Encrypt') ? ', ENCRYPTED' : '')
	. "\n\n";

$config = new WatermarkConfig();
$config->setType('text');
$config->setTextTemplate('DIAGNOSTIC');
$config->setPosition('diagonal');
$config->setOpacity(80);
$config->setFontSize(24);
$config->setColor('#cccccc');
$config->setRotation(45);

$dest = sys_get_temp_dir() . '/diagnose-' . bin2hex(random_bytes(4)) . '.pdf';

try {
	(new PdfWatermarker())->apply($source, $dest, $config, ['username' => 'Diagnostic']);
} catch (\Throwable $e) {
	echo 'REFUSED: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
	echo "The file was skipped rather than blanked — this is not the reported failure.\n";
	exit(1);
}

echo 'watermarked to ' . $dest . ' (' . number_format((int)filesize($dest)) . " bytes)\n\n";

[, $objects] = (new Parser(['decode_streams' => true]))->parse((string)file_get_contents($dest));

$forms = 0;
$problems = 0;
foreach ($objects as $num => $obj) {
	$dict = objDict($obj);
	if (($dict['Type'][1] ?? null) !== 'XObject' || ($dict['Subtype'][1] ?? null) !== 'Form') {
		continue;
	}

	$forms++;
	$stored = objStream($obj) ?? '';
	$content = decoded($stored);
	echo "imported page $forms (object $num)\n";
	echo '  content stream: ' . strlen($stored) . ' bytes stored, '
		. strlen($content) . " bytes of operators\n";

	if (strlen($stored) === 0) {
		echo "  PROBLEM: the page's content did not survive the import at all\n";
		$problems++;
		continue;
	}

	// Was the copied stream readable, or did it stay compressed because /Filter was
	// mis-paired? The parser decodes what it can; operators mean it came through.
	if (preg_match('~(BT|Do|re|cm|Tj|TJ)~', $content) !== 1) {
		echo "  PROBLEM: no drawing operators in the copied stream — it is being read as\n"
			. "           raw bytes, which is what a mis-paired /Filter entry causes\n";
		$problems++;
	}

	$resRaw = $dict['Resources'] ?? null;
	$resDict = subDict($resRaw, $objects);
	if (is_array($resRaw) && ($resRaw[0] ?? null) === '/') {
		echo '  PROBLEM: /Resources holds the name /' . $resRaw[1] . " instead of a dictionary —\n"
			. "           it has swallowed the entry after it, and every later key in this\n"
			. "           dictionary is now paired with the wrong value\n";
		$problems++;
	}

	foreach (namesUsed($content) as $type => $names) {
		$defined = subDict($resDict[$type] ?? null, $objects);

		$missing = array_values(array_filter(
			$names,
			static fn (string $n): bool => !array_key_exists($n, $defined),
		));
		echo "  $type: uses " . implode(', ', $names)
			. ($missing === [] ? ' — all defined' : ' — MISSING ' . implode(', ', $missing)) . "\n";
		if ($missing !== []) {
			$problems++;
		}
	}
}

echo "\n";
if ($forms === 0) {
	echo "VERDICT: no imported pages in the output — nothing of the original was carried over.\n";
	exit(1);
}
echo $problems === 0
	? "VERDICT: $forms page(s) imported, content and resources intact. If this file still\n"
		. "         renders blank, the cause is in how it is *drawn*, not in what was copied —\n"
		. "         send this output along with the file.\n"
	: "VERDICT: $problems problem(s) across $forms page(s) — see above.\n";
