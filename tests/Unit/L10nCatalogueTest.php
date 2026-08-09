<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The Arabic catalogue against the strings the app actually asks for.
 *
 * A missing translation is invisible: `t()` falls back to its English source, so an
 * untranslated string looks exactly like a correctly translated one to every other test
 * and to anyone reading the code. The only way to see it is to compare the two lists,
 * which is what this does.
 *
 * It also pins the two file formats against each other. Nextcloud reads `l10n/ar.json`
 * from PHP and `l10n/ar.js` from the browser, and nothing in the platform checks that
 * they agree - so a string fixed in one and not the other yields a settings page whose
 * server messages and interface are in different languages.
 */
class L10nCatalogueTest extends TestCase {

	/**
	 * The app root, *resolved*. `__DIR__ . '/../..'` would do for reading files, but it
	 * leaves `/tests/` in the middle of every path built from it - which quietly matched
	 * the "skip test files" filter below and excluded the entire source tree, leaving a
	 * coverage check that compared the catalogue against nothing and passed.
	 */
	private static function appRoot(): string {
		return (string)realpath(__DIR__ . '/../..');
	}

	/** Arabic has six plural forms; anything else here breaks every plural call. */
	private const EXPECTED_PLURAL_FORM
		= 'nplurals=6; plural=(n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : '
		. 'n%100>=3 && n%100<=10 ? 3 : n%100>=11 && n%100<=99 ? 4 : 5);';

	public function testEveryTranslatableStringIsInTheArabicCatalogue(): void {
		$missing = array_diff($this->sourceStrings(), array_keys($this->json()['translations']));

		$this->assertSame([], array_values($missing), 'Untranslated string(s): these fall back to English silently.');
	}

	/**
	 * The reverse direction, which catches the other half of the same drift: a string
	 * reworded or deleted in the source leaves its old translation behind, still looking
	 * like coverage.
	 */
	public function testTheCatalogueCarriesNothingTheAppNoLongerAsksFor(): void {
		$stale = array_diff(array_keys($this->json()['translations']), $this->sourceStrings());

		$this->assertSame([], array_values($stale), 'Stale translation(s): no source string asks for these.');
	}

	public function testTheJsAndJsonCataloguesAreIdentical(): void {
		[$jsTranslations, $jsPluralForm] = $this->js();

		$this->assertSame($this->json()['translations'], $jsTranslations);
		$this->assertSame($this->json()['pluralForm'], $jsPluralForm);
	}

	public function testArabicDeclaresItsSixPluralForms(): void {
		$this->assertSame(self::EXPECTED_PLURAL_FORM, $this->json()['pluralForm']);
	}

	/**
	 * A plural entry short of a form is not a partial translation - the lookup indexes
	 * into the array by form number, so a missing sixth form is a missing string for
	 * every count of 100 or more.
	 */
	public function testEveryPluralEntryHasAllSixForms(): void {
		foreach ($this->json()['translations'] as $source => $target) {
			if (!is_array($target)) {
				continue;
			}
			$this->assertCount(6, $target, "Plural '$source' does not carry six forms");
			$this->assertStringContainsString('_::_', $source, 'Plural keys are written _singular_::_plural_');
		}
	}

	/**
	 * **The catalogue is written without harakat**, and stays that way.
	 *
	 * Interface Arabic is set unvowelled - every other app on the server is, and Nextcloud's
	 * own catalogue is - so a settings page carrying fatha, damma and shadda reads as
	 * children's or liturgical text beside the panels around it. The marks were removed in
	 * one pass; the point of this test is that they were creeping *back* in, a string at a
	 * time, because a translator writing one line has no way to see the convention the other
	 * 143 follow.
	 *
	 * The range is U+064B-U+0652 exactly. **U+0653-U+0655 are deliberately outside it**:
	 * maddah and the two hamzas are combining marks too, but they are orthography rather than
	 * vowelling, and stripping them changes how a word is spelled rather than how fully it is
	 * pointed.
	 *
	 * @dataProvider translationProvider
	 */
	public function testTranslationsCarryNoHarakat(string $source, string $target): void {
		$this->assertSame(
			0,
			preg_match('/[\x{064B}-\x{0652}]/u', $target),
			"Arabic diacritics in a translation; the catalogue is written unvowelled:\n$source\n$target",
		);
	}

	/**
	 * Every substitution in the source has to survive into the translation.
	 *
	 * This is the failure that hurts most and shows least: `System tag ID "%s" does not
	 * exist` translated without its `%s` still renders as a fluent Arabic sentence - one
	 * that has quietly dropped the only part telling the admin *which* tag is wrong.
	 *
	 * @dataProvider translationProvider
	 */
	public function testPlaceholdersSurviveTranslation(string $source, string $target): void {
		$this->assertSame(
			$this->placeholders($source),
			$this->placeholders($target),
			"Placeholders differ between the source and its Arabic translation:\n$source\n$target",
		);
	}

	/** @return array<string, array{string, string}> */
	public static function translationProvider(): array {
		$cases = [];
		foreach (self::catalogue() as $source => $target) {
			if (!is_array($target)) {
				$cases[$source] = [$source, $target];
			}
		}

		return $cases;
	}

	/**
	 * Plural forms are held to a weaker rule, and the difference is grammatical rather
	 * than a relaxation for convenience.
	 *
	 * Arabic inflects the noun for one and for two - "ثانية واحدة", "ثانيتين" - so the
	 * count is carried by the word itself and printing the numeral as well reads as a
	 * mistake ("نحو 2 ثانيتين"). Those forms are allowed to drop `%n`. What no form may
	 * do is *introduce* a placeholder the source cannot fill, which would render as a
	 * literal `%s` in an admin's face.
	 *
	 * @dataProvider pluralFormProvider
	 */
	public function testAPluralFormNeverIntroducesAPlaceholderTheSourceCannotFill(
		string $source,
		string $form,
	): void {
		$this->assertSame(
			[],
			array_values(array_diff($this->placeholders($form), $this->placeholders($source))),
			"A plural form asks for a substitution its source has no value for:\n$source\n$form",
		);
	}

	/**
	 * Above two, Arabic states the numeral outright, so those forms *must* keep `%n` -
	 * without it "about 47 seconds" becomes an unqualified "about seconds".
	 *
	 * @dataProvider pluralFormProvider
	 */
	public function testPluralFormsForThreeAndAboveKeepTheirCount(
		string $source,
		string $form,
		int $index,
	): void {
		if ($index < 3 || !str_contains($source, '%n')) {
			$this->assertTrue(true, 'Not a counted form');
			return;
		}

		$this->assertStringContainsString('%n', $form, "Plural form $index dropped its count");
	}

	/**
	 * @return array<string, array{string, string, int}>
	 */
	public static function pluralFormProvider(): array {
		$cases = [
			// The app has no plural strings at the moment - the one it had described a
			// render that no longer happens when the button is pressed. The two checks
			// above are kept rather than deleted with it: `pluralForm` is still declared in
			// both catalogues, still asserted, and the first `n()` call anyone adds needs
			// these waiting for it. An empty provider is a PHPUnit error, not a pass, so
			// the case has to be here.
			'no plural strings in the app' => ['', '', 0],
		];

		foreach (self::catalogue() as $source => $target) {
			if (!is_array($target)) {
				continue;
			}
			// Each form is measured against the source form it renders: index 1 is the
			// singular, everything else the plural.
			[$singular, $plural] = explode('_::_', trim($source, '_'));
			foreach ($target as $i => $form) {
				$cases["$source #$i"] = [$i === 1 ? $singular : $plural, $form, $i];
			}
		}

		return $cases;
	}

	/** @return array<string, string|string[]> */
	private static function catalogue(): array {
		$json = json_decode((string)file_get_contents(self::appRoot() . '/l10n/ar.json'), true);

		return $json['translations'];
	}

	/**
	 * `{token}` interpolations and printf conversions, sorted.
	 *
	 * Deliberately not every `%`: `30% of its width` is prose, and a check that read it
	 * as a conversion would fail on a correct translation.
	 *
	 * @return string[]
	 */
	private function placeholders(string $text): array {
		preg_match_all('/\{[a-zA-Z]+\}|%(?:\d+\$)?[sdn]/', $text, $matches);
		$found = $matches[0];
		sort($found);

		return $found;
	}

	/**
	 * Every string the app passes to `t()` / `n()`, read out of the sources.
	 *
	 * Regex rather than a parser on purpose: this has to see the same thing Nextcloud's
	 * own extractor does, which is also a scan for the literal call. A string built by
	 * concatenation inside `t()` is invisible to both, and stays invisible here - which
	 * is why the one place that used to do it does not any more.
	 *
	 * @return string[]
	 */
	private function sourceStrings(): array {
		$strings = [];

		foreach ($this->sourceFiles('src', ['vue', 'js']) as $file) {
			$code = (string)file_get_contents($file);
			preg_match_all("/\bt\(\s*'files_watermark',\s*'((?:[^'\\\\]|\\\\.)*)'/s", $code, $singles);
			foreach ($singles[1] as $raw) {
				$strings[] = $this->unescape($raw);
			}
			preg_match_all(
				"/\bn\(\s*'files_watermark',\s*'((?:[^'\\\\]|\\\\.)*)',\s*'((?:[^'\\\\]|\\\\.)*)'/s",
				$code,
				$plurals,
			);
			foreach ($plurals[1] as $i => $singular) {
				$strings[] = '_' . $this->unescape($singular) . '_::_' . $this->unescape($plurals[2][$i]) . '_';
			}
		}

		foreach ($this->sourceFiles('lib', ['php']) as $file) {
			preg_match_all("/->t\(\s*'((?:[^'\\\\]|\\\\.)*)'/s", (string)file_get_contents($file), $matches);
			foreach ($matches[1] as $raw) {
				$strings[] = $this->unescape($raw);
			}
		}

		return array_values(array_unique($strings));
	}

	/**
	 * @param string[] $extensions
	 * @return string[]
	 */
	private function sourceFiles(string $directory, array $extensions): array {
		$files = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(self::appRoot() . '/' . $directory),
		);
		foreach ($iterator as $file) {
			/** @var \SplFileInfo $file */
			// The Jest specs and their mocks carry t() calls of their own, and they are
			// not interface text.
			if (
				!$file->isFile() || !in_array($file->getExtension(), $extensions, true)
				|| str_contains($file->getPathname(), '/tests/')
			) {
				continue;
			}
			$files[] = $file->getPathname();
		}

		return $files;
	}

	private function unescape(string $raw): string {
		return str_replace(['\\\'', '\\\\'], ['\'', '\\'], $raw);
	}

	/** @return array{array<string, string|string[]>, string} */
	private function js(): array {
		$js = (string)file_get_contents(self::appRoot() . '/l10n/ar.js');

		$this->assertSame(1, preg_match('/\{(.*)\}/s', $js, $body), 'ar.js is not an OC.L10N.register call');
		$this->assertSame(1, preg_match('/\},\s*"(.*)"\);/s', $js, $plural), 'ar.js declares no plural form');

		$translations = json_decode('{' . $body[1] . '}', true);
		$this->assertIsArray($translations, 'ar.js translation map is not valid JSON: ' . json_last_error_msg());

		return [$translations, $plural[1]];
	}

	/** @return array{translations: array<string, string|string[]>, pluralForm: string} */
	private function json(): array {
		$json = json_decode((string)file_get_contents(self::appRoot() . '/l10n/ar.json'), true);
		$this->assertIsArray($json, 'ar.json is not valid JSON: ' . json_last_error_msg());

		return $json;
	}
}
