<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Db\WatermarkRendition;
use OCA\FilesWatermark\Db\WatermarkRenditionMapper;
use OCA\FilesWatermark\Service\DeliveryPadder;
use OCA\FilesWatermark\Service\DeliveryReservation;
use OCP\Files\Cache\ICache;
use OCP\Files\Cache\IPropagator;
use OCP\Files\File;
use OCP\Files\Storage\IStorage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\FilesWatermark\Service\DeliveryReservation
 *
 * What is under test is almost entirely *staleness*: a reservation that outlives the thing it
 * was measured against promises a length no download will have, and the client acts on that
 * promise before anything can correct it. Every case below is a way for the world to move on
 * underneath a stored row.
 */
class DeliveryReservationTest extends TestCase {

	private WatermarkRenditionMapper&MockObject $mapper;
	private DeliveryReservation $reservation;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(WatermarkRenditionMapper::class);
		$this->reservation = new DeliveryReservation(
			$this->mapper,
			new DeliveryPadder(new NullLogger()),
			new NullLogger(),
		);
	}

	/** @var list<string> etags written to the file cache, in order */
	private array $bumped = [];

	private function file(int $id = 42, string $etag = 'etag-1'): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getEtag')->willReturn($etag);
		$file->method('getInternalPath')->willReturn('files/x.pdf');
		$file->method('getMTime')->willReturn(1_700_000_000);

		// A storage that records what the reservation writes back, so the etag refresh is
		// observable - it is the whole reason a client ever re-reads a listing.
		$cache = $this->createMock(ICache::class);
		$cache->method('update')->willReturnCallback(function (int $_, array $data): void {
			if (isset($data['etag'])) {
				$this->bumped[] = (string)$data['etag'];
			}
		});

		$storage = $this->createMock(IStorage::class);
		$storage->method('getCache')->willReturn($cache);
		$storage->method('getPropagator')->willReturn($this->createMock(IPropagator::class));
		$file->method('getStorage')->willReturn($storage);

		return $file;
	}

	private function config(string $template = '{displayname}'): WatermarkConfig {
		$config = new WatermarkConfig();
		$config->setTextTemplate($template);
		$config->setUpdatedAt('2026-09-15 10:00:00');
		return $config;
	}

	/**
	 * The stored row is only usable when it was measured against this exact file and policy,
	 * so the round trip is the baseline every staleness case below is measured against.
	 */
	public function testAReservationMeasuredForThisFileAndPolicyIsUsable(): void {
		$file = $this->file();
		$config = $this->config();

		$captured = null;
		$this->mapper->method('reserve')
			->willReturnCallback(function (int $f, string $u, string $sig) use (&$captured): bool {
				$captured = $sig;
				return true;
			});
		$this->reservation->record($file, 'alice', $config, 1000, 'application/pdf');

		$stored = new WatermarkRendition();
		$stored->setSignature((string)$captured);
		$this->mapper->method('find')->willReturn($stored);

		// Against the file as the etag refresh inside `record` left it - which is what the
		// next PROPFIND actually sees.
		$this->assertSame(
			$stored,
			$this->reservation->current($this->file(etag: $this->bumped[0]), 'alice', $config),
		);
	}

	/** Editing the file changes what a render of it weighs. */
	public function testAReservationDoesNotSurviveTheFileChanging(): void {
		$config = $this->config();

		$captured = null;
		$this->mapper->method('reserve')
			->willReturnCallback(function (int $f, string $u, string $sig) use (&$captured): bool {
				$captured = $sig;
				return true;
			});
		$this->reservation->record($this->file(etag: 'etag-1'), 'alice', $config, 1000, 'application/pdf');

		$stored = new WatermarkRendition();
		$stored->setSignature((string)$captured);
		$this->mapper->method('find')->willReturn($stored);

		$this->assertNull(
			$this->reservation->current($this->file(etag: 'etag-2'), 'alice', $config),
			'a reservation measured against an older version of the file must not be reused',
		);
	}

	/** Every field of the policy moves the render's size, so any edit to it retires the row. */
	public function testAReservationDoesNotSurviveThePolicyChanging(): void {
		$file = $this->file();

		$captured = null;
		$this->mapper->method('reserve')
			->willReturnCallback(function (int $f, string $u, string $sig) use (&$captured): bool {
				$captured = $sig;
				return true;
			});
		$this->reservation->record($file, 'alice', $this->config('{displayname}'), 1000, 'application/pdf');

		$stored = new WatermarkRendition();
		$stored->setSignature((string)$captured);
		$this->mapper->method('find')->willReturn($stored);

		$this->assertNull(
			$this->reservation->current($file, 'alice', $this->config('{email} - {date}')),
			'a reservation measured under a different policy must not be reused',
		);
	}

	public function testNothingMeasuredMeansNoReservation(): void {
		$this->mapper->method('find')->willReturn(null);

		$this->assertNull($this->reservation->current($this->file(), 'alice', $this->config()));
	}

	/**
	 * A format with no safe place to put filler can never be padded up to a promise, so no
	 * promise is made for it - the download keeps its own unpredictable length.
	 */
	public function testAnUnpaddableFormatIsNeverPromisedALength(): void {
		$this->mapper->expects($this->never())->method('reserve');

		$this->assertNull($this->reservation->record(
			$this->file(),
			'alice',
			$this->config(),
			1000,
			'application/msword',
		));
	}

	/** With no reader there is nobody to have promised anything to. */
	public function testAnAbsentReaderIsNeverMeasuredFor(): void {
		$this->mapper->expects($this->never())->method('reserve');
		$this->mapper->expects($this->never())->method('find');

		$this->assertNull($this->reservation->record($this->file(), '', $this->config(), 1000, 'application/pdf'));
		$this->assertNull($this->reservation->current($this->file(), '', $this->config()));
	}

	public function testTheReservedLengthClearsTheRenderItWasMeasuredFrom(): void {
		$reserved = null;
		$this->mapper->method('reserve')
			->willReturnCallback(function (int $f, string $u, string $s, int $size) use (&$reserved): bool {
				$reserved = $size;
				return true;
			});

		$returned = $this->reservation->record($this->file(), 'alice', $this->config(), 50_000, 'application/pdf');

		// The number handed back is the number written down, or the download pads to one
		// length while the listing advertises another.
		$this->assertSame($reserved, $returned);
		$this->assertGreaterThan(50_000, (int)$reserved, 'a reservation with no slack cannot absorb a moving timestamp');
	}

	/**
	 * Recording a reservation has to move the stored file's etag, or no sync client ever
	 * looks at the listing again and the reserved length is published to nobody. This is the
	 * defect behind `The cloud operation is invalid` on Windows: the client kept hydrating
	 * against the size it cached before the file was ever marked.
	 */
	public function testRecordingAReservationRefreshesTheStoredEtag(): void {
		$this->mapper->method('reserve')->willReturn(true);

		$this->reservation->record($this->file(), 'alice', $this->config(), 1000, 'application/pdf');

		$this->assertCount(1, $this->bumped, 'the file cache etag must be refreshed exactly once');
		$this->assertNotSame('etag-1', $this->bumped[0]);
	}

	/**
	 * And the row has to be written against the etag the refresh *produced*, not the one it
	 * replaced - otherwise every download finds its own reservation stale, re-measures, bumps
	 * again, and the file re-downloads forever.
	 */
	public function testTheReservationIsRecordedAgainstTheRefreshedEtagSoItDoesNotLoop(): void {
		$captured = null;
		$this->mapper->method('reserve')
			->willReturnCallback(function (int $f, string $u, string $sig) use (&$captured): bool {
				$captured = $sig;
				return true;
			});

		$config = $this->config();
		$this->reservation->record($this->file(), 'alice', $config, 1000, 'application/pdf');

		$newEtag = $this->bumped[0];

		// What the very next PROPFIND will see: the file now carries the refreshed etag.
		$stored = new WatermarkRendition();
		$stored->setSignature((string)$captured);
		$this->mapper->method('find')->willReturn($stored);

		$this->assertSame(
			$stored,
			$this->reservation->current($this->file(etag: $newEtag), 'alice', $config),
			'the reservation must be valid for the file as the refresh left it',
		);
	}

	/** A storage that refuses the refresh must not take the download down with it. */
	public function testAFailedEtagRefreshStillRecordsAReservation(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getEtag')->willReturn('etag-1');
		$file->method('getStorage')->willThrowException(new \RuntimeException('read-only'));

		$this->mapper->method('reserve')->willReturn(true);

		$this->assertSame(
			5096,
			$this->reservation->record($file, 'alice', $this->config(), 1000, 'application/pdf'),
		);
	}

	/** A row the mapper would not write is not a promise, and must not be reported as one. */
	public function testAReservationThatCouldNotBeStoredIsNotReturned(): void {
		$this->mapper->method('reserve')->willReturn(false);

		$this->assertNull($this->reservation->record($this->file(), 'alice', $this->config(), 1000, 'application/pdf'));
	}

	/**
	 * The etag has to move when the reserved length does, or a client that already cached the
	 * old length never re-reads it - which is the whole mechanism by which a cold first
	 * download fixes itself on the second.
	 */
	public function testTheAdvertisedEtagTracksBothTheFileAndTheReservedLength(): void {
		$file = $this->file(etag: 'etag-1');

		$this->assertNotSame(
			$this->reservation->etag($file, 1000),
			$this->reservation->etag($file, 2000),
			'a revised reservation must be offered under a new etag',
		);
		$this->assertNotSame(
			$this->reservation->etag($this->file(etag: 'etag-1'), 1000),
			$this->reservation->etag($this->file(etag: 'etag-2'), 1000),
			'an edited file must be offered under a new etag',
		);
		// Stable for an unchanged file at an unchanged length - otherwise the client reads
		// every fetch as a fresh change and downloads forever.
		$this->assertSame(
			$this->reservation->etag($this->file(etag: 'etag-1'), 1000),
			$this->reservation->etag($this->file(etag: 'etag-1'), 1000),
		);
	}

	/** A listing drops rows whose signature has moved on, rather than handing them back. */
	public function testAListingReturnsOnlyTheRowsStillWorthTrusting(): void {
		$fresh = $this->file(1, 'etag-1');
		$edited = $this->file(2, 'etag-2');
		$config = $this->config();

		$captured = null;
		$this->mapper->method('reserve')
			->willReturnCallback(function (int $f, string $u, string $sig) use (&$captured): bool {
				$captured = $sig;
				return true;
			});
		$this->reservation->record($fresh, 'alice', $config, 1000, 'application/pdf');
		// `record` refreshed the etag, so the listing sees the file under the new one.
		$fresh = $this->file(1, $this->bumped[0]);

		$current = new WatermarkRendition();
		$current->setFileId(1);
		$current->setSignature((string)$captured);

		$stale = new WatermarkRendition();
		$stale->setFileId(2);
		$stale->setSignature('measured-against-something-else');

		$this->mapper->method('findByFileIds')->willReturn([1 => $current, 2 => $stale]);

		$valid = $this->reservation->currentForListing([1 => $fresh, 2 => $edited], 'alice', $config);

		$this->assertSame([1 => $current], $valid);
	}
}
