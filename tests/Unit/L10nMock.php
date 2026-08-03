<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit;

use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * An `IL10N` that behaves like the untranslated (English) locale.
 *
 * Deliberately *not* a bare `createMock(IL10N::class)`: that returns an empty string from
 * `t()`, which would turn every error message in the suite into `''` and let the
 * assertions that check what an admin is told pass on nothing at all.
 *
 * It also formats, because the parameters are the part that breaks. A translated string
 * carries `%s` / `%1$s` placeholders instead of interpolated PHP variables, so a message
 * that names the offending MIME type or tag id only names it if the substitution happens
 * - and a mock that ignored the parameters would report the same green suite either way.
 */
trait L10nMock {

	/** @return IL10N&MockObject */
	private function l10n(): IL10N {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string
			=> $parameters === [] ? $text : vsprintf($text, $parameters),
		);
		$l->method('n')->willReturnCallback(
			static fn (string $singular, string $plural, int $count, array $parameters = []): string
			=> str_replace('%n', (string)$count, vsprintf($count === 1 ? $singular : $plural, $parameters)),
		);

		return $l;
	}
}
