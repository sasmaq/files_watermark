<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit;

use OCA\FilesWatermark\Service\InstanceTimeZone;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * An {@see InstanceTimeZone} answering a zone the test chooses.
 *
 * Defaulting to **UTC** rather than to whatever `config.php` would resolve to, because the
 * suite runs on developer machines, in CI containers and on the RHEL target, and a rendered
 * `{date}` or a formatted log row must not depend on which. A test that cares about the
 * conversion names its own zone; every other test wants the timestamps it asserts to be the
 * ones it wrote.
 *
 * What the zone is resolved *from* - the `default_timezone` line, the fallbacks, the bad
 * value - is `InstanceTimeZoneTest`'s subject, and is deliberately not re-tested through
 * every consumer.
 */
trait InstanceTimeZoneMock {

	/** @return InstanceTimeZone&MockObject */
	private function timeZone(string $zone = 'UTC'): InstanceTimeZone {
		$timeZone = $this->createMock(InstanceTimeZone::class);
		$timeZone->method('get')->willReturn(new \DateTimeZone($zone));

		return $timeZone;
	}
}
