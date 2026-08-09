<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Controller;

use OCA\FilesWatermark\Controller\ApiController;
use OCA\FilesWatermark\Db\WatermarkConfigMapper;
use OCA\FilesWatermark\Db\WatermarkLog;
use OCA\FilesWatermark\Db\WatermarkLogMapper;
use OCA\FilesWatermark\Service\WatermarkImageStore;
use OCA\FilesWatermark\Service\WatermarkService;
use OCA\FilesWatermark\Tests\Unit\InstanceTimeZoneMock;
use OCA\FilesWatermark\Tests\Unit\L10nMock;
use OCP\AppFramework\Http;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * `GET /api/v1/log`, which feeds the activity table in the admin settings.
 *
 * The rows name **who downloaded what and when**, across every account on the server, so
 * the admin gate is the whole of this endpoint's security - there is no per-user view to
 * fall back to. It is checked before the query rather than after, which is also what keeps a
 * non-admin from measuring the table's size through the response time.
 */
class ApiControllerLogTest extends TestCase {

	use InstanceTimeZoneMock;
	use L10nMock;

	private WatermarkLogMapper&MockObject $logMapper;
	private ApiController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->logMapper = $this->createMock(WatermarkLogMapper::class);
		$this->controller = $this->controllerFor('admin', true);
	}

	/**
	 * A controller whose session holds `$uid` (null for an anonymous request) and whose
	 * group manager answers `$isAdmin`. Built per test rather than reconfigured, because a
	 * PHPUnit mock cannot have the same method stubbed twice.
	 */
	private function controllerFor(?string $uid, bool $isAdmin, string $zone = 'UTC'): ApiController {
		$session = $this->createMock(IUserSession::class);
		$groupManager = $this->createMock(IGroupManager::class);

		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
			$groupManager->method('isAdmin')->with($uid)->willReturn($isAdmin);
		}

		return new ApiController(
			'files_watermark',
			$this->createMock(IRequest::class),
			$this->createMock(WatermarkConfigMapper::class),
			$this->logMapper,
			$this->createMock(WatermarkService::class),
			$this->createMock(IRootFolder::class),
			$session,
			$groupManager,
			$this->createMock(WatermarkImageStore::class),
			$this->createMock(ISystemTagManager::class),
			$this->l10n(),
			$this->timeZone($zone),
		);
	}

	private function logEntry(int $id, string $uid, string $trigger): WatermarkLog {
		$entry = new WatermarkLog();
		$entry->setId($id);
		$entry->setUserId($uid);
		$entry->setFileId(1000 + $id);
		$entry->setFilePath("/$uid/files/report.pdf");
		$entry->setTrigger($trigger);
		$entry->setCreatedAt('2026-08-04 09:00:00');
		return $entry;
	}

	public function testReturnsTheSerialisedEntries(): void {
		$this->logMapper->method('findAll')->willReturn([
			$this->logEntry(2, 'alice', 'on_download'),
			$this->logEntry(1, 'bob', 'on_demand'),
		]);

		$response = $this->controller->getLog();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		// A bare list, not an envelope: AuditLog.vue iterates the response itself.
		$this->assertCount(2, $response->getData());
		$this->assertSame('alice', $response->getData()[0]['userId']);
		$this->assertSame('on_download', $response->getData()[0]['trigger']);
		$this->assertSame(1001, $response->getData()[1]['fileId']);
	}

	/**
	 * Rows are stored as a fixed instant and **shown** in the instance's timezone, so a log
	 * read on an Aden server does not report a download as having happened three hours
	 * before it did.
	 *
	 * The column itself is deliberately left in the clock that wrote it: `prune-log` does
	 * date arithmetic against it, and a stored value whose meaning depends on a `config.php`
	 * line is a retention command that deletes the wrong rows the day somebody edits it.
	 * Converting on the way out also means changing `default_timezone` re-reads the whole
	 * history rather than leaving a seam at the moment it changed.
	 */
	public function testTimestampsAreShownInTheInstanceTimeZone(): void {
		$this->logMapper->method('findAll')->willReturn([$this->logEntry(1, 'alice', 'on_demand')]);

		// The entry is stored as 09:00 UTC; Aden is UTC+3 and does not observe DST.
		$controller = $this->controllerFor('admin', true, 'Asia/Aden');

		$this->assertSame('2026-08-04 12:00:00', $controller->getLog()->getData()[0]['createdAt']);
	}

	/** A row this app did not write is shown as stored rather than dropped or blanked. */
	public function testAnUnparseableTimestampIsPassedThrough(): void {
		$entry = $this->logEntry(1, 'alice', 'on_demand');
		$entry->setCreatedAt('not a date');
		$this->logMapper->method('findAll')->willReturn([$entry]);

		$this->assertSame('not a date', $this->controller->getLog()->getData()[0]['createdAt']);
	}

	public function testAnEmptyLogIsAnEmptyList(): void {
		$this->logMapper->method('findAll')->willReturn([]);

		$response = $this->controller->getLog();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData());
	}

	/**
	 * Paging reaches the query. The delivery triggers write a row per fetch, so this table
	 * is the one that grows without bound - a limit that silently stayed at its default
	 * would mean the admin page could never show anything past the first hundred rows.
	 */
	public function testLimitAndOffsetArePassedThrough(): void {
		$this->logMapper->expects($this->once())
			->method('findAll')
			->with(25, 50)
			->willReturn([]);

		$this->assertSame(Http::STATUS_OK, $this->controller->getLog(25, 50)->getStatus());
	}

	public function testDefaultsToTheFirstHundred(): void {
		$this->logMapper->expects($this->once())
			->method('findAll')
			->with(100, 0)
			->willReturn([]);

		$this->controller->getLog();
	}

	/** @dataProvider nonAdminProvider */
	public function testIsForbiddenWithoutAdmin(?string $uid, bool $isAdmin): void {
		// Never queried: the rows name other people's files, and the gate is checked before
		// the table is touched.
		$this->logMapper->expects($this->never())->method('findAll');

		$response = $this->controllerFor($uid, $isAdmin)->getLog();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('Forbidden', $response->getData()['error']);
	}

	/** @return array<string, array{?string, bool}> */
	public static function nonAdminProvider(): array {
		return [
			'signed in, not an admin' => ['bob', false],
			'anonymous' => [null, false],
		];
	}
}
