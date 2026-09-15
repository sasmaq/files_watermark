<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Db\WatermarkConfigMapper;
use OCA\FilesWatermark\Db\WatermarkLogMapper;
use OCA\FilesWatermark\Db\WatermarkMark;
use OCA\FilesWatermark\Db\WatermarkMarkMapper;
use OCA\FilesWatermark\Service\ApplyLimits;
use OCA\FilesWatermark\Service\DeliveryReservation;
use OCA\FilesWatermark\Service\FileTooLargeException;
use OCA\FilesWatermark\Service\ImageLimits;
use OCA\FilesWatermark\Service\ImageTooLargeException;
use OCA\FilesWatermark\Service\ImageWatermarker;
use OCA\FilesWatermark\Service\InstanceTimeZone;
use OCA\FilesWatermark\Service\PdfFlattener;
use OCA\FilesWatermark\Service\PdfWatermarker;
use OCA\FilesWatermark\Service\ShareAccess;
use OCA\FilesWatermark\Service\ShareRecipient;
use OCA\FilesWatermark\Service\WatermarkImageStore;
use OCA\FilesWatermark\Service\WatermarkRequiredException;
use OCA\FilesWatermark\Service\WatermarkService;
use OCA\FilesWatermark\Tests\Unit\L10nMock;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUser;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The service, against the model it actually implements now.
 *
 * Roughly half of what this file used to assert is gone rather than rewritten, and the
 * deletions are the point: there is no in-place burn, so nothing preserves an original and
 * nothing can fail to restore one; there is no `on_share`, so there is no owner to exempt
 * and no Team folder to detect; and there is no `on_download`, so a delivery no longer has
 * a trigger of its own to resolve. What is left divides in two - **which files get marked**,
 * and **what a fetch of a marked file produces** - and that is how this file is laid out.
 */
class WatermarkServiceTest extends TestCase {

	use L10nMock;

	private WatermarkConfigMapper&MockObject $configMapper;
	private WatermarkLogMapper&MockObject $logMapper;
	private WatermarkMarkMapper&MockObject $markMapper;
	private PdfWatermarker&MockObject $pdfWatermarker;
	private PdfFlattener&MockObject $pdfFlattener;
	private ImageWatermarker&MockObject $imageWatermarker;
	private IUserSession&MockObject $userSession;
	private ISystemTagObjectMapper&MockObject $tagObjectMapper;
	private LoggerInterface&MockObject $logger;
	private WatermarkImageStore&MockObject $imageStore;
	private ImageLimits&MockObject $imageLimits;
	private ApplyLimits&MockObject $applyLimits;
	private ShareAccess&MockObject $shareAccess;
	private ShareRecipient&MockObject $shareRecipient;
	private InstanceTimeZone&MockObject $timeZone;
	private DeliveryReservation&MockObject $reservation;
	private WatermarkService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->configMapper = $this->createMock(WatermarkConfigMapper::class);
		$this->logMapper = $this->createMock(WatermarkLogMapper::class);
		$this->markMapper = $this->createMock(WatermarkMarkMapper::class);
		$this->pdfWatermarker = $this->createMock(PdfWatermarker::class);
		// Available unless a test says otherwise, so the flatten leg is reachable; the
		// config's own `flattenPdf` is off by default, which is what keeps it unused.
		$this->pdfFlattener = $this->createMock(PdfFlattener::class);
		$this->imageWatermarker = $this->createMock(ImageWatermarker::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->tagObjectMapper = $this->createMock(ISystemTagObjectMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->imageStore = $this->createMock(WatermarkImageStore::class);
		// The shipped defaults unless a test says otherwise: an unstubbed mock answers 0,
		// which every file and every image exceeds.
		$this->imageLimits = $this->createMock(ImageLimits::class);
		$this->imageLimits->method('maxPixels')->willReturn(ImageLimits::DEFAULT_MAX_PIXELS);
		$this->applyLimits = $this->createMock(ApplyLimits::class);
		$this->applyLimits->method('maxBytes')->willReturn(ApplyLimits::DEFAULT_MAX_BYTES);
		// Owner access unless a test says otherwise: an unstubbed mock answers false to
		// both questions, which is exactly "not a share".
		$this->shareAccess = $this->createMock(ShareAccess::class);
		// Not a share by email unless a test says otherwise: an unstubbed mock answers
		// null, which is "this fetch names nobody the share knows about".
		$this->shareRecipient = $this->createMock(ShareRecipient::class);
		// Fixed, so a `{date}` assertion cannot depend on where the suite is run. What the
		// zone resolves *from* is InstanceTimeZoneTest's business.
		$this->timeZone = $this->createMock(InstanceTimeZone::class);
		$this->timeZone->method('get')->willReturn(new \DateTimeZone('UTC'));

		$this->reservation = $this->createMock(DeliveryReservation::class);

		$this->service = new WatermarkService(
			$this->configMapper,
			$this->logMapper,
			$this->markMapper,
			$this->pdfWatermarker,
			$this->pdfFlattener,
			$this->imageWatermarker,
			$this->userSession,
			$this->tagObjectMapper,
			$this->logger,
			$this->imageStore,
			$this->imageLimits,
			$this->applyLimits,
			$this->shareAccess,
			$this->shareRecipient,
			$this->l10n(),
			$this->timeZone,
			$this->reservation,
		);
	}

	/**
	 * Marking has to leave a promised length behind it, because the *first* fetch is already
	 * too late: discovery publishes the stored length, a Windows client sizes its
	 * virtual-file placeholder from it, and the first open fails before any lazy measurement
	 * can happen.
	 */
	public function testMarkingMeasuresTheDeliveryLengthForTheOwner(): void {
		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('owner-uid');
		$file = $this->file('application/pdf', 42, '%PDF-1.4 original', 1024, $owner);

		$this->markMapper->method('mark')->willReturn(true);
		$this->configMapper->method('findGlobal')->willReturn($this->config());
		$this->pdfWatermarker->method('apply')
			->willReturnCallback(static function (string $src, string $dest): void {
				file_put_contents($dest, '%PDF-overlaid');
			});

		// For the owner - whose sync client holds the file - not for whoever clicked Apply.
		$this->reservation->expects($this->once())
			->method('record')
			->with($file, 'owner-uid', $this->anything(), $this->greaterThan(0), 'application/pdf');

		$this->service->mark($file, WatermarkService::TRIGGER_ON_DEMAND);
	}

	/** A file that was already marked places nothing, so there is nothing new to measure. */
	public function testReMarkingAnAlreadyMarkedFileMeasuresNothing(): void {
		$file = $this->file();
		$this->markMapper->method('mark')->willReturn(false);
		$this->configMapper->method('findGlobal')->willReturn($this->config());

		$this->reservation->expects($this->never())->method('record');

		$this->service->mark($file, WatermarkService::TRIGGER_ON_DEMAND);
	}

	/**
	 * The measurement is an optimisation over the lazy path, never a precondition for it.
	 * A render that throws here must still leave the file marked - the download measures it
	 * lazily exactly as it did before.
	 */
	public function testAFailedMeasurementStillMarksTheFile(): void {
		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('owner-uid');
		$file = $this->file('application/pdf', 42, '%PDF-1.4 original', 1024, $owner);

		$this->markMapper->method('mark')->willReturn(true);
		$this->configMapper->method('findGlobal')->willReturn($this->config());
		$this->pdfWatermarker->method('apply')
			->willThrowException(new \RuntimeException('renderer exploded'));

		$this->assertTrue(
			$this->service->mark($file, WatermarkService::TRIGGER_ON_DEMAND),
			'a file must end up marked whether or not its length could be measured',
		);
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	private function config(string $trigger = WatermarkService::TRIGGER_ON_DEMAND): WatermarkConfig {
		$config = new WatermarkConfig();
		$config->setType('text');
		$config->setTextTemplate('{displayname}');
		$config->setTrigger($trigger);
		return $config;
	}

	private function user(string $uid, string $displayName = '', string $email = ''): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($displayName !== '' ? $displayName : $uid);
		$user->method('getEMailAddress')->willReturn($email);
		return $user;
	}

	/**
	 * @param int $size bytes, as the file cache reports them
	 */
	private function file(
		string $mime = 'application/pdf',
		int $id = 42,
		string $content = 'ORIGINAL',
		int $size = 1024,
		?IUser $owner = null,
	): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getMimeType')->willReturn($mime);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn('report.pdf');
		$file->method('getPath')->willReturn('/alice/files/report.pdf');
		$file->method('getContent')->willReturn($content);
		$file->method('getSize')->willReturn($size);
		$file->method('getOwner')->willReturn($owner);
		// Only images are header-checked, and only PNG/JPEG/WEBP reach that path; a stream
		// of the content is enough for `getimagesizefromstring()` to decline, which is the
		// documented "cannot tell, allow through" case.
		$file->method('fopen')->willReturnCallback(static function () use ($content) {
			$handle = fopen('php://memory', 'r+');
			fwrite($handle, $content);
			rewind($handle);
			return $handle;
		});
		return $file;
	}

	private function markedFile(string $mime = 'application/pdf', int $id = 42): File&MockObject {
		$this->markMapper->method('isMarked')->with($id)->willReturn(true);
		$this->markMapper->method('markedFileIds')->willReturn([$id]);
		return $this->file($mime, $id);
	}

	// -----------------------------------------------------------------------
	// Which files get marked
	// -----------------------------------------------------------------------

	public function testIsSupportedMatchesKnownTypes(): void {
		$this->assertTrue($this->service->isSupported('application/pdf'));
		$this->assertTrue($this->service->isSupported('image/jpeg'));
		$this->assertTrue($this->service->isSupported('image/png'));
		$this->assertTrue($this->service->isSupported('image/webp'));
		$this->assertFalse($this->service->isSupported('text/plain'));
		$this->assertFalse($this->service->isSupported('application/zip'));
	}

	public function testMarkWritesTheMarkAndAnAuditRow(): void {
		$this->configMapper->method('findGlobal')->willReturn($this->config());
		$file = $this->file();
		$alice = $this->user('alice');

		$this->markMapper->expects($this->once())
			->method('mark')
			->with(42, 'alice', WatermarkService::TRIGGER_ON_DEMAND, null)
			->willReturn(true);
		$this->logMapper->expects($this->once())
			->method('insertLog')
			->with('alice', 42, '/alice/files/report.pdf', WatermarkService::TRIGGER_ON_DEMAND, null);

		$this->assertTrue($this->service->mark($file, WatermarkService::TRIGGER_ON_DEMAND, $alice));
	}

	/**
	 * Marking never touches the file. It is the whole premise, and it is cheap to assert
	 * outright rather than leave to be inferred from what the test does not stub.
	 */
	public function testMarkNeverReadsOrWritesTheFile(): void {
		$this->configMapper->method('findGlobal')->willReturn($this->config());
		$this->markMapper->method('mark')->willReturn(true);

		$file = $this->createMock(File::class);
		$file->method('getMimeType')->willReturn('application/pdf');
		$file->method('getId')->willReturn(42);
		$file->method('getPath')->willReturn('/alice/files/report.pdf');
		$file->method('getSize')->willReturn(1024);
		$file->expects($this->never())->method('getContent');
		$file->expects($this->never())->method('putContent');
		$this->pdfWatermarker->expects($this->never())->method('apply');
		$this->imageWatermarker->expects($this->never())->method('apply');

		$this->service->mark($file, WatermarkService::TRIGGER_ON_DEMAND, $this->user('alice'));
	}

	/**
	 * A second mark is a no-op, not a failure - and it says so by returning false rather
	 * than by throwing. `on_upload` fires on every write, so this is the ordinary path for
	 * every file after its first save.
	 */
	public function testMarkingAnAlreadyMarkedFileIsANoOp(): void {
		$this->configMapper->method('findGlobal')->willReturn($this->config());
		$this->markMapper->method('mark')->willReturn(false);
		$this->logMapper->expects($this->never())->method('insertLog');

		$this->assertFalse(
			$this->service->mark($this->file(), WatermarkService::TRIGGER_ON_UPLOAD, $this->user('alice')),
		);
	}

	public function testMarkRefusesAnUnsupportedType(): void {
		$this->configMapper->method('findGlobal')->willReturn($this->config());
		$this->markMapper->expects($this->never())->method('mark');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Unsupported file type');
		$this->service->mark($this->file('text/plain'), WatermarkService::TRIGGER_ON_DEMAND);
	}

	public function testMarkRefusesAMimeOutsideTheWhitelist(): void {
		$config = $this->config();
		$config->setMimeTypes('image/png');
		$this->configMapper->method('findGlobal')->willReturn($config);
		$this->markMapper->expects($this->never())->method('mark');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('not in the configured whitelist');
		$this->service->mark($this->file(), WatermarkService::TRIGGER_ON_DEMAND);
	}

	/**
	 * The byte ceiling is enforced when the file is marked, not when it is fetched.
	 *
	 * That is the only moment where refusing is still a choice: a marked file is promised a
	 * watermark on every fetch, so a ceiling discovered at download time would deny a file
	 * nobody was ever warned about.
	 */
	public function testMarkRefusesAFileOverTheByteCeiling(): void {
		$this->configMapper->method('findGlobal')->willReturn($this->config());
		$this->markMapper->expects($this->never())->method('mark');

		$this->expectException(FileTooLargeException::class);
		$this->service->mark(
			$this->file('application/pdf', 42, 'ORIGINAL', ApplyLimits::DEFAULT_MAX_BYTES + 1),
			WatermarkService::TRIGGER_ON_DEMAND,
		);
	}

	/** The refusal names both numbers, so an admin knows what to raise `apply_max_bytes` to. */
	public function testTheByteRefusalNamesTheSizeAndTheLimit(): void {
		$this->configMapper->method('findGlobal')->willReturn($this->config());

		try {
			$this->service->mark(
				$this->file('application/pdf', 42, 'ORIGINAL', 100_000_000),
				WatermarkService::TRIGGER_ON_DEMAND,
			);
			$this->fail('an oversized file must be refused');
		} catch (FileTooLargeException $e) {
			$this->assertStringContainsString('100 MB', $e->getMessage());
			$this->assertStringContainsString('67.1 MB', $e->getMessage());
		}
	}

	/**
	 * A decompression bomb is refused a mark, from its header alone.
	 *
	 * The pixel ceiling used to be enforced inside the render, on a temp copy that only
	 * existed because a render was happening. With no render at mark time the check has to
	 * read the image's first bytes instead - and reading the *whole* file to decide whether
	 * it is safe to read the whole file would not be a check at all.
	 */
	public function testMarkRefusesAnImageOverThePixelCeilingFromItsHeader(): void {
		$this->configMapper->method('findGlobal')->willReturn($this->config());
		$this->imageLimits = $this->createMock(ImageLimits::class);
		$this->markMapper->expects($this->never())->method('mark');

		// A real 2×2 PNG, with the ceiling set below it - the smallest honest way to prove
		// the header is being parsed rather than the size guessed at.
		$png = (string)base64_decode(
			'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAEklEQVR4nGP8//8/AzJgYkAD'
			. 'RAsAAJUsBQXbHu4ZAAAAAElFTkSuQmCC',
		);
		$service = $this->serviceWithPixelCeiling(1);

		$this->expectException(ImageTooLargeException::class);
		$service->mark($this->file('image/png', 42, $png), WatermarkService::TRIGGER_ON_DEMAND);
	}

	/**
	 * An unreadable header is allowed through, deliberately.
	 *
	 * `getimagesizefromstring()` returns false for anything it cannot parse, corrupt files
	 * and unknown formats alike. Refusing on that would turn "this guard cannot tell" into
	 * "this file is a bomb" and reject files the renderer handles perfectly well.
	 */
	public function testAnImageWhoseHeaderCannotBeParsedIsStillMarkable(): void {
		$this->configMapper->method('findGlobal')->willReturn($this->config());
		$this->markMapper->expects($this->once())->method('mark')->willReturn(true);

		$service = $this->serviceWithPixelCeiling(1);
		$service->mark($this->file('image/png', 42, 'not-a-png'), WatermarkService::TRIGGER_ON_DEMAND);
	}

	private function serviceWithPixelCeiling(int $maxPixels): WatermarkService {
		$limits = $this->createMock(ImageLimits::class);
		$limits->method('maxPixels')->willReturn($maxPixels);

		return new WatermarkService(
			$this->configMapper,
			$this->logMapper,
			$this->markMapper,
			$this->pdfWatermarker,
			$this->pdfFlattener,
			$this->imageWatermarker,
			$this->userSession,
			$this->tagObjectMapper,
			$this->logger,
			$this->imageStore,
			$limits,
			$this->applyLimits,
			$this->shareAccess,
			$this->shareRecipient,
			$this->l10n(),
			$this->timeZone,
		);
	}

	public function testMarkRefusesAFileOutsideTheTaggedFolder(): void {
		$config = $this->config();
		$config->setFolderTag('7');
		$this->configMapper->method('findGlobal')->willReturn($config);
		$this->tagObjectMapper->method('getObjectIdsForTags')->willReturn(['999']);

		$parent = $this->createMock(Folder::class);
		$parent->method('getId')->willReturn(1);
		$file = $this->file();
		$file->method('getParent')->willReturn($parent);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('required system tag');
		$this->service->mark($file, WatermarkService::TRIGGER_ON_DEMAND);
	}

	/**
	 * A stored tag that is not a usable id degrades to this app's ordinary refusal rather
	 * than escaping as an HTTP 500. `InvalidArgumentException` is not a `RuntimeException`,
	 * so uncaught it sailed past every caller's handling.
	 *
	 * @dataProvider unusableTagProvider
	 */
	public function testAnUnusableStoredFolderTagDegradesInsteadOfCrashing(\Throwable $thrown): void {
		$config = $this->config();
		$config->setFolderTag('not-an-id');
		$this->configMapper->method('findGlobal')->willReturn($config);
		$this->tagObjectMapper->method('getObjectIdsForTags')->willThrowException($thrown);

		$parent = $this->createMock(Folder::class);
		$parent->method('getId')->willReturn(1);
		$file = $this->file();
		$file->method('getParent')->willReturn($parent);

		$this->expectException(\RuntimeException::class);
		$this->service->mark($file, WatermarkService::TRIGGER_ON_DEMAND);
	}

	/** @return array<string, array{\Throwable}> */
	public static function unusableTagProvider(): array {
		return [
			'tag no longer exists' => [new TagNotFoundException('gone')],
			'tag id is not numeric' => [new \InvalidArgumentException('Tag id must be integer')],
		];
	}

	/**
	 * The scope checks apply when a file is marked and are deliberately not consulted
	 * again on delivery.
	 *
	 * The mark *is* the decision. An admin who narrows the whitelist afterwards has changed
	 * what gets marked next; a marked file that silently stopped being watermarked because
	 * someone moved it out of a tagged folder is the failure this app exists to prevent.
	 */
	public function testDeliveryDoesNotReapplyTheScopeChecks(): void {
		$config = $this->config();
		$config->setMimeTypes('image/png');
		$config->setFolderTag('7');
		$this->configMapper->method('findGlobal')->willReturn($config);
		$this->tagObjectMapper->expects($this->never())->method('getObjectIdsForTags');

		$file = $this->markedFile('application/pdf');
		$this->pdfWatermarker->expects($this->once())->method('apply');

		$tmpPath = $this->service->watermarkForDownload($file);

		$this->assertNotNull($tmpPath);
		$this->cleanup($tmpPath);
	}

	public function testUnmarkRemovesTheMarkAndRecordsIt(): void {
		$this->markMapper->expects($this->once())->method('unmark')->with(42)->willReturn(true);
		$this->userSession->method('getUser')->willReturn($this->user('alice'));
		$this->logMapper->expects($this->once())
			->method('insertLog')
			->with('alice', 42, '/alice/files/report.pdf', WatermarkService::TRIGGER_UNMARKED, null);

		$this->assertTrue($this->service->unmark($this->file()));
	}

	public function testUnmarkingAnUnmarkedFileRecordsNothing(): void {
		$this->markMapper->method('unmark')->willReturn(false);
		$this->logMapper->expects($this->never())->method('insertLog');

		$this->assertFalse($this->service->unmark($this->file()));
	}

	// -----------------------------------------------------------------------
	// What a fetch produces
	// -----------------------------------------------------------------------

	public function testAnUnmarkedFileIsNotADeliveryCandidate(): void {
		$this->markMapper->method('isMarked')->willReturn(false);

		$this->assertFalse($this->service->isDeliveryCandidate($this->file()));
		$this->assertNull($this->service->watermarkForDownload($this->file()));
	}

	public function testAnUnsupportedTypeIsNotADeliveryCandidateUnlessItIsMarked(): void {
		$this->markMapper->method('isMarked')->willReturn(false);

		// Unmarked and named as something unrenderable: the bytes are never read. The mark
		// is what makes the question worth asking at all.
		$file = $this->file('text/plain', content: 'just text');
		$file->expects($this->never())->method('fopen');

		$this->assertFalse($this->service->isDeliveryCandidate($file));
	}

	/**
	 * Renaming a marked file used to strip its watermark.
	 *
	 * Nextcloud derives the MIME type from the extension and re-derives it on every rename,
	 * so `report.pdf` renamed to `report.txt` reported `text/plain` while the mark - which is
	 * a row against the file id - stayed exactly where it was. Delivery consulted the type
	 * first and handed over the stored bytes: the clean original, one rename away, with the
	 * Files list still showing the file as watermarked throughout.
	 */
	public function testARenamedMarkedFileIsStillWatermarkedFromItsContent(): void {
		$this->configMapper->method('findGlobal')->willReturn($this->config());
		$this->markMapper->method('isMarked')->with(42)->willReturn(true);
		$renamed = $this->file('text/plain', content: "%PDF-1.7\nbody");

		$this->assertTrue($this->service->isDeliveryCandidate($renamed));

		$this->pdfWatermarker->expects($this->once())->method('apply');
		$this->assertNotNull($this->service->watermarkForDownload($renamed));
	}

	/**
	 * @dataProvider provideRenamedContent
	 */
	public function testTheContentDecidesWhichRendererARenamedFileGoesTo(
		string $content,
		bool $expectPdf,
	): void {
		$this->configMapper->method('findGlobal')->willReturn($this->config());
		$this->markMapper->method('isMarked')->with(42)->willReturn(true);

		$this->pdfWatermarker->expects($expectPdf ? $this->once() : $this->never())->method('apply');
		$this->imageWatermarker->expects($expectPdf ? $this->never() : $this->once())->method('apply');

		$this->assertNotNull($this->service->watermarkForDownload(
			$this->file('application/octet-stream', content: $content),
		));
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function provideRenamedContent(): array {
		return [
			'pdf' => ["%PDF-1.4\ntrailer", true],
			'pdf behind a byte-order mark' => ["\xEF\xBB\xBF%PDF-1.4", true],
			'jpeg' => ["\xFF\xD8\xFF\xE0\x00\x10JFIF", false],
			'png' => ["\x89PNG\x0D\x0A\x1A\x0A\x00\x00\x00\x0DIHDR", false],
			'webp' => ["RIFF\x24\x00\x00\x00WEBPVP8 ", false],
		];
	}

	/**
	 * The rename dodge only works in one direction, and this is the other one: a marked file
	 * whose content is genuinely nothing this app renders is served as it is stored. There is
	 * no watermark to withhold, so refusing the download would take a file hostage over a
	 * policy that could never have applied to it.
	 */
	public function testAMarkedFileWhoseContentIsUnrenderableIsServedAsStored(): void {
		$this->markMapper->method('isMarked')->with(42)->willReturn(true);

		$this->pdfWatermarker->expects($this->never())->method('apply');
		$this->imageWatermarker->expects($this->never())->method('apply');

		$this->assertNull($this->service->watermarkForDownload(
			$this->file('text/plain', content: 'PK not a pdf'),
		));
	}

	/**
	 * The read costs something on object storage, so it happens only where the alternative
	 * is serving an unwatermarked copy - never for a file whose name already says there is
	 * work to do.
	 */
	public function testAMarkedFileWithASupportedNameIsNeverSniffed(): void {
		$this->configMapper->method('findGlobal')->willReturn($this->config());
		$this->markMapper->method('isMarked')->with(42)->willReturn(true);

		$file = $this->file('application/pdf');
		// `getContent()` supplies the render; `fopen()` is the type probe and the header
		// check, neither of which a plainly-named PDF has any reason to reach.
		$file->expects($this->never())->method('fopen');

		$this->assertNotNull($this->service->watermarkForDownload($file));
	}

	public function testAMarkedPdfIsRenderedThroughThePdfWatermarker(): void {
		$this->configMapper->method('findGlobal')->willReturn($this->config());
		$file = $this->markedFile('application/pdf');

		$this->pdfWatermarker->expects($this->once())->method('apply');
		$this->imageWatermarker->expects($this->never())->method('apply');

		$tmpPath = $this->service->watermarkForDownload($file);

		$this->assertNotNull($tmpPath);
		$this->cleanup($tmpPath);
	}

	// -----------------------------------------------------------------------
	// Flattening - the one leg that shells out, and the one that may fail without
	// costing the reader their download
	// -----------------------------------------------------------------------

	public function testFlatteningRunsAfterTheOverlayWhenThePolicyAsksForIt(): void {
		// Order matters: rasterising before the overlay would capture a clean page and
		// leave the watermark as a removable layer on top of it.
		$this->configMapper->method('findGlobal')->willReturn($this->flattenConfig(true));
		$calls = [];
		$this->pdfWatermarker->method('apply')
			->willReturnCallback(static function (string $src, string $dest) use (&$calls): void {
				$calls[] = 'overlay';
				file_put_contents($dest, '%PDF-overlaid');
			});
		$this->pdfFlattener->method('isAvailable')->willReturn(true);
		$this->pdfFlattener->expects($this->once())
			->method('flatten')
			->willReturnCallback(static function (string $src, string $dest) use (&$calls): void {
				$calls[] = 'flatten';
				file_put_contents($dest, '%PDF-flattened');
			});

		$tmpPath = $this->service->watermarkForDownload($this->markedFile('application/pdf'));

		$this->assertSame(['overlay', 'flatten'], $calls);
		// The rebuild is what the reader gets, not the overlay-only file.
		$this->assertSame('%PDF-flattened', (string)file_get_contents($tmpPath));
		$this->assertFileDoesNotExist($tmpPath . '_flat');
		$this->cleanup($tmpPath);
	}

	public function testFlatteningIsSkippedWhenThePolicyDoesNotAskForIt(): void {
		$this->configMapper->method('findGlobal')->willReturn($this->flattenConfig(false));
		$this->pdfWatermarker->expects($this->once())->method('apply');
		$this->pdfFlattener->expects($this->never())->method('flatten');

		$this->cleanup($this->service->watermarkForDownload($this->markedFile('application/pdf')));
	}

	public function testAnImageIsNeverFlattenedHoweverThePolicyIsSet(): void {
		$this->configMapper->method('findGlobal')->willReturn($this->flattenConfig(true));
		$this->pdfFlattener->expects($this->never())->method('flatten');

		$this->cleanup($this->service->watermarkForDownload($this->markedFile('image/png')));
	}

	public function testAStrandedFlattenSettingFallsBackToTheOverlayAndIsLogged(): void {
		// The policy asks for flattening but this host has no renderer - a restore, a host
		// migration, or someone removing the package. The UI hides the control in that
		// state, so the server treats the setting as off and says why in the log.
		$this->configMapper->method('findGlobal')->willReturn($this->flattenConfig(true));
		$this->pdfWatermarker->expects($this->once())
			->method('apply')
			->willReturnCallback(static function (string $src, string $dest): void {
				file_put_contents($dest, '%PDF-overlaid');
			});
		$this->pdfFlattener->method('isAvailable')->willReturn(false);
		$this->pdfFlattener->expects($this->never())->method('flatten');
		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('pdftoppm'), $this->anything());

		$tmpPath = $this->service->watermarkForDownload($this->markedFile('application/pdf'));

		$this->assertSame('%PDF-overlaid', (string)file_get_contents($tmpPath));
		$this->cleanup($tmpPath);
	}

	/**
	 * **The fallback the feature is built around, and the reversal of how this behaved
	 * when flattening last existed.**
	 *
	 * A failed rasterise used to refuse the download outright, on the grounds that the
	 * overlay-only PDF is the strippable version the setting exists to avoid handing out.
	 * It now delivers that file: flattening is hardening on top of a watermark that is
	 * already present and already names its reader, and one document the renderer chokes on
	 * is not a reason to make a marked file undownloadable.
	 */
	public function testAFailedFlattenFallsBackToTheOverlayWatermark(): void {
		$this->configMapper->method('findGlobal')->willReturn($this->flattenConfig(true));
		$this->pdfWatermarker->method('apply')
			->willReturnCallback(static function (string $src, string $dest): void {
				file_put_contents($dest, '%PDF-overlaid');
			});
		$this->pdfFlattener->method('isAvailable')->willReturn(true);
		$this->pdfFlattener->method('flatten')
			->willThrowException(new \RuntimeException('Cannot flatten PDF: rendering page 1 failed'));
		// Loud, because "some downloads are flattened and some are not" is otherwise
		// invisible to an admin looking at a setting that is switched on.
		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('could not flatten'), $this->anything());

		$tmpPath = $this->service->watermarkForDownload($this->markedFile('application/pdf'));

		$this->assertNotNull($tmpPath, 'the download must not be refused for a failed flatten');
		$this->assertSame('%PDF-overlaid', (string)file_get_contents($tmpPath));
		$this->assertFileDoesNotExist($tmpPath . '_flat');
		$this->assertFileDoesNotExist($tmpPath . '_src');
		$this->cleanup($tmpPath);
	}

	public function testARebuildThatCannotReplaceTheOverlayIsDiscarded(): void {
		// The one failure that can leave a finished rebuild on disk: `flatten` succeeded and
		// the rename over it did not. The reader still gets the overlay, and the orphan goes.
		$this->configMapper->method('findGlobal')->willReturn($this->flattenConfig(true));
		$this->pdfWatermarker->method('apply')
			->willReturnCallback(static function (string $src, string $dest): void {
				file_put_contents($dest, '%PDF-overlaid');
			});
		$this->pdfFlattener->method('isAvailable')->willReturn(true);
		$this->pdfFlattener->method('flatten')
			->willReturnCallback(static function (string $src, string $dest): void {
				file_put_contents($dest, '%PDF-flattened');
				// Turn the rename's *target* into a non-empty directory, which no platform
				// lets a file be moved over. Contrived, and the only way to reach the branch
				// without a filesystem that fails on demand.
				unlink($src);
				mkdir($src);
				file_put_contents($src . '/occupied', 'x');
			});
		$this->logger->expects($this->once())->method('warning');

		$tmpPath = $this->service->watermarkForDownload($this->markedFile('application/pdf'));

		$this->assertNotNull($tmpPath);
		$this->assertFileDoesNotExist($tmpPath . '_flat', 'the orphaned rebuild must be discarded');
		@unlink($tmpPath . '/occupied');
		@rmdir($tmpPath);
		@rmdir($tmpPath . '_flat_blocker');
		@rmdir(dirname($tmpPath));
	}

	/**
	 * A file nobody marked, going out through a public link the policy watermarks, is
	 * flattened too.
	 *
	 * The two features meet here and neither knows about the other: the share switch decides
	 * *that* this fetch is watermarked, and the policy's `flattenPdf` decides *how*. A
	 * flatten that only ran for marked files would leave every blanket-watermarked public
	 * download with the strippable overlay the setting exists to avoid.
	 */
	public function testAShareForcedDeliveryIsFlattenedToo(): void {
		$config = $this->flattenConfig(true);
		$config->setWatermarkExternalShares(true);
		$this->configMapper->method('findGlobal')->willReturn($config);
		$this->markMapper->method('isMarked')->willReturn(false);
		$this->shareAccess->method('isExternalShareAccess')->willReturn(true);
		$this->pdfWatermarker->method('apply')
			->willReturnCallback(static function (string $src, string $dest): void {
				file_put_contents($dest, '%PDF-overlaid');
			});
		$this->pdfFlattener->method('isAvailable')->willReturn(true);
		$this->pdfFlattener->expects($this->once())
			->method('flatten')
			->willReturnCallback(static function (string $src, string $dest): void {
				file_put_contents($dest, '%PDF-flattened');
			});

		$tmpPath = $this->service->watermarkForDownload($this->file('application/pdf'));

		$this->assertNotNull($tmpPath, 'the public-link switch should have produced a copy');
		$this->assertSame('%PDF-flattened', (string)file_get_contents($tmpPath));
		$this->cleanup($tmpPath);
	}

	/**
	 * The flattener is handed a source and a destination and nothing else.
	 *
	 * The render resolution used to travel with them, from column to entity to argument.
	 * It is now {@see \OCA\FilesWatermark\Service\PdfFlattener::RENDER_DPI}, and this
	 * pins the call shape so a resolution argument cannot quietly reappear on the path
	 * without the signature being reconsidered.
	 */
	public function testTheFlattenerIsCalledWithSourceAndDestinationOnly(): void {
		$this->configMapper->method('findGlobal')->willReturn($this->flattenConfig(true));
		$this->pdfWatermarker->method('apply')
			->willReturnCallback(static function (string $src, string $dest): void {
				file_put_contents($dest, '%PDF-overlaid');
			});
		$this->pdfFlattener->method('isAvailable')->willReturn(true);
		$this->pdfFlattener->expects($this->once())
			->method('flatten')
			->with($this->isType('string'), $this->isType('string'))
			->willReturnCallback(static function (string $src, string $dest): void {
				file_put_contents($dest, '%PDF-flattened');
			});

		$this->cleanup($this->service->watermarkForDownload($this->markedFile('application/pdf')));
	}

	/** A PDF policy, optionally asking for flattening. */
	private function flattenConfig(bool $flatten): WatermarkConfig {
		$config = $this->config();
		$config->setFlattenPdf($flatten);
		return $config;
	}

	public function testAMarkedImageIsRenderedThroughTheImageWatermarker(): void {
		$this->configMapper->method('findGlobal')->willReturn($this->config());
		$file = $this->markedFile('image/png');

		$this->imageWatermarker->expects($this->once())->method('apply');
		$this->pdfWatermarker->expects($this->never())->method('apply');

		$tmpPath = $this->service->watermarkForDownload($file);

		$this->assertNotNull($tmpPath);
		$this->cleanup($tmpPath);
	}

	/**
	 * **The one behaviour this whole rework exists for.**
	 *
	 * The watermark names whoever is fetching the file, so two people downloading the same
	 * marked file get two different documents. A burned-in watermark could only ever name
	 * the person who triggered it - for a shared file, the person who uploaded it rather
	 * than the person who walked out with it.
	 */
	public function testTheWatermarkNamesWhoeverIsFetchingTheFile(): void {
		$this->configMapper->method('findGlobal')->willReturn($this->config());
		$file = $this->markedFile('application/pdf');

		$names = [];
		$this->pdfWatermarker->method('apply')->willReturnCallback(
			static function ($src, $dst, $config, array $placeholders) use (&$names): void {
				$names[] = $placeholders['displayname'];
				file_put_contents($dst, 'rendered');
			},
		);

		// Twice per fetch, and the pairing is the point: the identity that goes into the
		// watermark is the identity that goes into the audit row. Delivery logging is on by
		// default, so the second call of each pair is the row being written.
		$this->userSession->method('getUser')->willReturnOnConsecutiveCalls(
			$this->user('alice', 'Alice Smith'),
			$this->user('alice', 'Alice Smith'),
			$this->user('bob', 'Bob Jones'),
			$this->user('bob', 'Bob Jones'),
		);

		$this->cleanup($this->service->watermarkForDownload($file));
		$this->cleanup($this->service->watermarkForDownload($file));

		$this->assertSame(['Alice Smith', 'Bob Jones'], $names);
	}

	/**
	 * `{date}` and `{datetime}` are rendered in the instance's timezone, not PHP's.
	 *
	 * Nextcloud pins PHP's default to UTC while it boots, so `date()` stamped UTC on every
	 * instance in the world - an hour that reads as wrong rather than as elsewhere, because
	 * a watermark has no room to write the offset.
	 *
	 * Two zones 26 hours apart, which is more than a day: whatever instant this runs at,
	 * they are on different calendar dates, so the assertion needs no fixed clock and cannot
	 * flake at a boundary.
	 */
	public function testTheDateIsRenderedInTheInstanceTimeZone(): void {
		$dates = [];
		$this->pdfWatermarker->method('apply')->willReturnCallback(
			static function ($src, $dst, $config, array $placeholders) use (&$dates): void {
				$dates[] = $placeholders['date'];
				file_put_contents($dst, 'rendered');
			},
		);

		foreach (['Pacific/Kiritimati', 'Etc/GMT+12'] as $zone) {
			$this->cleanup(
				$this->serviceInTimeZone($zone)->watermarkForDownload($this->markedFile('application/pdf')),
			);
		}

		$this->assertNotSame(
			$dates[0],
			$dates[1],
			'both zones produced the same date, so the configured timezone is not reaching the watermark',
		);
	}

	/**
	 * A template using both tokens must not be able to show a date from one day beside a
	 * time from the next, which is what two separate clock reads either side of midnight
	 * would eventually produce.
	 */
	public function testDateAndDatetimeReadTheSameInstant(): void {
		$seen = [];
		$this->pdfWatermarker->method('apply')->willReturnCallback(
			static function ($src, $dst, $config, array $placeholders) use (&$seen): void {
				$seen = $placeholders;
				file_put_contents($dst, 'rendered');
			},
		);

		$this->cleanup($this->service->watermarkForDownload($this->markedFile('application/pdf')));

		$this->assertStringStartsWith($seen['date'], $seen['datetime']);
	}

	private function serviceInTimeZone(string $zone): WatermarkService {
		$this->configMapper->method('findGlobal')->willReturn($this->config());

		$timeZone = $this->createMock(InstanceTimeZone::class);
		$timeZone->method('get')->willReturn(new \DateTimeZone($zone));

		return new WatermarkService(
			$this->configMapper,
			$this->logMapper,
			$this->markMapper,
			$this->pdfWatermarker,
			$this->pdfFlattener,
			$this->imageWatermarker,
			$this->userSession,
			$this->tagObjectMapper,
			$this->logger,
			$this->imageStore,
			$this->imageLimits,
			$this->applyLimits,
			$this->shareAccess,
			$this->shareRecipient,
			$this->l10n(),
			$timeZone,
		);
	}

	/**
	 * An anonymous fetch is a public link, and a public link has exactly one person
	 * accountable for it: whoever published the file.
	 *
	 * Naming the mechanism instead - the watermark used to read "Public link" - is no use
	 * to anybody holding a leaked document.
	 */
	public function testAnAnonymousFetchIsWatermarkedWithTheFileOwner(): void {
		$this->configMapper->method('findGlobal')->willReturn($this->config());
		$this->userSession->method('getUser')->willReturn(null);

		$this->markMapper->method('isMarked')->willReturn(true);
		$file = $this->file('application/pdf', 42, 'ORIGINAL', 1024, $this->user('alice', 'Alice Smith'));

		$captured = [];
		$this->pdfWatermarker->method('apply')->willReturnCallback(
			static function ($src, $dst, $config, array $placeholders) use (&$captured): void {
				$captured = $placeholders;
				file_put_contents($dst, 'rendered');
			},
		);

		$this->cleanup($this->service->watermarkForDownload($file));

		$this->assertSame('Alice Smith', $captured['displayname']);
		$this->assertSame('alice', $captured['username']);
	}

	/** With no session *and* no resolvable owner there is no honest name to draw. */
	public function testAFetchWithNoIdentityAtAllFallsBackToUnknown(): void {
		$this->configMapper->method('findGlobal')->willReturn($this->config());
		$this->userSession->method('getUser')->willReturn(null);
		$file = $this->markedFile('application/pdf');

		$captured = [];
		$this->pdfWatermarker->method('apply')->willReturnCallback(
			static function ($src, $dst, $config, array $placeholders) use (&$captured): void {
				$captured = $placeholders;
				file_put_contents($dst, 'rendered');
			},
		);

		$this->cleanup($this->service->watermarkForDownload($file));

		$this->assertSame('Unknown', $captured['displayname']);
		$this->assertSame('Unknown', $captured['username']);
	}

	public function testEveryPlaceholderReachesTheRenderer(): void {
		$this->configMapper->method('findGlobal')->willReturn($this->config());
		$this->userSession->method('getUser')->willReturn(
			$this->user('asmith3', 'Alice Smith', 'alice@example.org'),
		);
		$file = $this->markedFile('application/pdf');

		$captured = [];
		$this->pdfWatermarker->method('apply')->willReturnCallback(
			static function ($src, $dst, $config, array $placeholders) use (&$captured): void {
				$captured = $placeholders;
				file_put_contents($dst, 'rendered');
			},
		);

		$this->cleanup($this->service->watermarkForDownload($file));

		// The account name and the display name are different identities and the difference
		// matters in a watermark: one is what an admin greps for, the other is what a human
		// recognises.
		$this->assertSame('asmith3', $captured['username']);
		$this->assertSame('Alice Smith', $captured['displayname']);
		$this->assertSame('alice@example.org', $captured['email']);
		$this->assertSame('report.pdf', $captured['filename']);
		$this->assertSame(date('Y-m-d'), $captured['date']);
	}

	/**
	 * A copy going out through a **share by email** carries the address it was sent to.
	 *
	 * This is the one anonymous reader the app can name. Without it `{email}` falls back to
	 * the identity the fetch resolved to - for a link visitor that is the file's owner - so
	 * the copy that could have named its recipient instead named the person who sent it.
	 */
	public function testAMailShareStampsTheAddressItWasSentTo(): void {
		$this->configMapper->method('findGlobal')->willReturn($this->config());
		$this->userSession->method('getUser')->willReturn(
			$this->user('owner', 'The Owner', 'owner@example.org'),
		);
		$this->shareRecipient->method('email')->willReturn('reader@example.org');

		$captured = [];
		$this->pdfWatermarker->method('apply')->willReturnCallback(
			static function ($src, $dst, $config, array $placeholders) use (&$captured): void {
				$captured = $placeholders;
				file_put_contents($dst, 'rendered');
			},
		);

		$this->cleanup($this->service->watermarkForDownload($this->markedFile('application/pdf')));

		$this->assertSame('reader@example.org', $captured['email']);

		// Only the address. A mail-share recipient has no account, so the two name tokens
		// keep naming whoever published the file rather than inventing an identity.
		$this->assertSame('owner', $captured['username']);
		$this->assertSame('The Owner', $captured['displayname']);
	}

	/** Every other kind of fetch keeps the identity's own address. */
	public function testANonMailShareKeepsTheReadersOwnAddress(): void {
		$this->configMapper->method('findGlobal')->willReturn($this->config());
		$this->userSession->method('getUser')->willReturn(
			$this->user('asmith3', 'Alice Smith', 'alice@example.org'),
		);
		$this->shareRecipient->method('email')->willReturn(null);

		$captured = [];
		$this->pdfWatermarker->method('apply')->willReturnCallback(
			static function ($src, $dst, $config, array $placeholders) use (&$captured): void {
				$captured = $placeholders;
				file_put_contents($dst, 'rendered');
			},
		);

		$this->cleanup($this->service->watermarkForDownload($this->markedFile('application/pdf')));

		$this->assertSame('alice@example.org', $captured['email']);
	}

	/**
	 * One bad byte in a display name used to cost the whole watermark its Arabic shaping,
	 * silently, in a perfectly valid output file. It is dropped, and the *field* is named
	 * in the log - by the time the renderer sees the value it is one substring of a
	 * resolved template and can no longer say which field to fix.
	 */
	public function testInvalidUtf8InAPlaceholderIsRepairedAndTheFieldNamed(): void {
		$this->configMapper->method('findGlobal')->willReturn($this->config());
		$this->userSession->method('getUser')->willReturn($this->user('alice', "Ahmed\xC3"));
		$file = $this->markedFile('application/pdf');

		$captured = [];
		$this->pdfWatermarker->method('apply')->willReturnCallback(
			static function ($src, $dst, $config, array $placeholders) use (&$captured): void {
				$captured = $placeholders;
				file_put_contents($dst, 'rendered');
			},
		);

		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$warnings): void {
				$warnings[] = $context['fields'] ?? '';
			},
		);

		$this->cleanup($this->service->watermarkForDownload($file));

		$this->assertSame('Ahmed', $captured['displayname']);
		$this->assertContains('displayname', $warnings);
	}

	/**
	 * A failed render **denies the fetch**. It does not fall back to the stored file.
	 *
	 * `on_download` used to degrade to the original on failure, which is the one outcome a
	 * mark cannot allow: it hands the clean bytes to precisely the reader the mark exists
	 * to name, and does it without saying anything.
	 */
	public function testAFailedRenderRefusesTheFetchRatherThanServingTheOriginal(): void {
		$this->configMapper->method('findGlobal')->willReturn($this->config());
		$file = $this->markedFile('application/pdf');
		$this->pdfWatermarker->method('apply')->willThrowException(new \RuntimeException('unparseable'));

		$this->expectException(WatermarkRequiredException::class);
		$this->service->watermarkForDownload($file);
	}

	/**
	 * A failed render must not leave a plaintext copy of the user's file in the temp dir.
	 *
	 * The caller only ever receives an exception, never a path it could clean up itself, so
	 * this is the only place the working copies can be swept - and unparseable PDFs are
	 * routine, not exotic.
	 */
	public function testAFailedRenderLeavesNoPlaintextCopyBehind(): void {
		$this->configMapper->method('findGlobal')->willReturn($this->config());
		$file = $this->markedFile('application/pdf');

		$seen = null;
		$this->pdfWatermarker->method('apply')->willReturnCallback(
			static function (string $src) use (&$seen): void {
				$seen = $src;
				throw new \RuntimeException('unparseable');
			},
		);

		try {
			$this->service->watermarkForDownload($file);
		} catch (WatermarkRequiredException) {
			// expected
		}

		$this->assertNotNull($seen);
		$this->assertFileDoesNotExist($seen);
		$this->assertDirectoryDoesNotExist(dirname($seen));
	}

	/**
	 * The pixel ceiling is checked again at render time, and that is not redundant: an
	 * overwrite keeps the mark, so the bytes being rendered are not necessarily the bytes
	 * that were measured when the mark was placed.
	 */
	public function testThePixelCeilingIsCheckedAgainstTheBytesThatActuallyArrive(): void {
		$this->configMapper->method('findGlobal')->willReturn($this->config());
		$png = (string)base64_decode(
			'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAEklEQVR4nGP8//8/AzJgYkAD'
			. 'RAsAAJUsBQXbHu4ZAAAAAElFTkSuQmCC',
		);
		$this->markMapper->method('isMarked')->willReturn(true);
		$service = $this->serviceWithPixelCeiling(1);
		$this->imageWatermarker->expects($this->never())->method('apply');

		$this->expectException(WatermarkRequiredException::class);
		$service->watermarkForDownload($this->file('image/png', 42, $png));
	}

	public function testTheLogoIsResolvedToARealPathForTheRenderer(): void {
		$config = $this->config();
		$config->setType('combined');
		$config->setImagePath('stored-reference');
		$this->configMapper->method('findGlobal')->willReturn($config);
		$this->imageStore->method('localPath')->with('stored-reference')->willReturn('/tmp/logo.png');
		$file = $this->markedFile('application/pdf');

		$seen = 'unset';
		$this->pdfWatermarker->method('apply')->willReturnCallback(
			static function ($src, $dst, WatermarkConfig $config) use (&$seen): void {
				$seen = $config->getImagePath();
				file_put_contents($dst, 'rendered');
			},
		);

		$this->cleanup($this->service->watermarkForDownload($file));

		$this->assertSame('/tmp/logo.png', $seen);
	}

	/**
	 * Anything the store does not recognise - a legacy hand-typed absolute path, most of
	 * all - resolves to null and renders as text only, rather than reading whatever the web
	 * server happens to be able to open.
	 */
	public function testAnUnresolvableLogoIsNeverPassedToTheRenderer(): void {
		$config = $this->config();
		$config->setType('combined');
		$config->setImagePath('/etc/shadow');
		$this->configMapper->method('findGlobal')->willReturn($config);
		$this->imageStore->method('localPath')->willReturn(null);
		$file = $this->markedFile('application/pdf');

		$seen = 'unset';
		$this->pdfWatermarker->method('apply')->willReturnCallback(
			static function ($src, $dst, WatermarkConfig $config) use (&$seen): void {
				$seen = $config->getImagePath();
				file_put_contents($dst, 'rendered');
			},
		);

		$this->cleanup($this->service->watermarkForDownload($file));

		$this->assertNull($seen);
	}

	// -----------------------------------------------------------------------
	// Watermarking what leaves through a share
	// -----------------------------------------------------------------------
	//
	// The one place in this app where *who is asking* decides whether there is a watermark
	// rather than only what it says. Nothing here places a mark, and every case below runs
	// against an unmarked file - if any of them started marking, the switch would stop being
	// reversible and the owner would start getting watermarked too.

	/** An unmarked file, so every watermark in this section comes from the share alone. */
	private function unmarkedFile(string $mime = 'application/pdf'): File&MockObject {
		$this->markMapper->method('isMarked')->willReturn(false);
		$this->markMapper->method('markedFileIds')->willReturn([]);

		return $this->file($mime);
	}

	private function sharePolicy(bool $internal = false, bool $external = false): WatermarkConfig {
		$config = $this->config();
		$config->setWatermarkInternalShares($internal);
		$config->setWatermarkExternalShares($external);
		$this->configMapper->method('findGlobal')->willReturn($config);

		return $config;
	}

	public function testAnInternalShareIsWatermarkedWhenThePolicySaysSo(): void {
		$this->sharePolicy(internal: true);
		$this->shareAccess->method('isInternalShareAccess')->willReturn(true);
		$file = $this->unmarkedFile();

		$this->assertTrue($this->service->isDeliveryCandidate($file));

		$this->pdfWatermarker->expects($this->once())->method('apply');
		$this->cleanup($this->service->watermarkForDownload($file));
	}

	/** The owner's own copy of the same file, under the same policy, is untouched. */
	public function testTheOwnersOwnFetchOfAnUnmarkedFileStaysClean(): void {
		$this->sharePolicy(internal: true);
		$this->shareAccess->method('isInternalShareAccess')->willReturn(false);
		$file = $this->unmarkedFile();

		$this->assertFalse($this->service->isDeliveryCandidate($file));
		$this->assertNull($this->service->watermarkForDownload($file));
	}

	public function testAPublicLinkIsWatermarkedWhenThePolicySaysSo(): void {
		$this->sharePolicy(external: true);
		$this->shareAccess->method('isExternalShareAccess')->willReturn(true);
		$file = $this->unmarkedFile();

		$this->assertTrue($this->service->isDeliveryCandidate($file));
	}

	/**
	 * The two switches are independent, and this is the pair that proves it: an instance
	 * that watermarks internal shares only must hand a public-link visitor the clean file,
	 * and vice versa.
	 */
	public function testEachSwitchAnswersOnlyItsOwnKindOfShare(): void {
		$this->sharePolicy(internal: true);
		$this->shareAccess->method('isExternalShareAccess')->willReturn(true);
		$this->shareAccess->method('isInternalShareAccess')->willReturn(false);

		$this->assertFalse($this->service->isDeliveryCandidate($this->unmarkedFile()));
	}

	public function testAShareIsNotWatermarkedWhileBothSwitchesAreOff(): void {
		$this->sharePolicy();
		$this->shareAccess->method('isInternalShareAccess')->willReturn(true);
		$this->shareAccess->method('isExternalShareAccess')->willReturn(true);

		$this->assertFalse($this->service->isDeliveryCandidate($this->unmarkedFile()));
	}

	/**
	 * A mark still outranks everything: the file is watermarked for its owner, on an
	 * instance with both switches off, because that is what a mark means.
	 */
	public function testAMarkedFileIsStillWatermarkedForItsOwnerWithBothSwitchesOff(): void {
		$this->sharePolicy();

		$this->assertTrue($this->service->isDeliveryCandidate($this->markedFile()));
	}

	/**
	 * The policy's scope is the admin saying which files this policy is about at all, so it
	 * binds this route exactly as it binds marking. Without it, ticking a share switch would
	 * quietly watermark the file types the same page says to leave alone.
	 */
	public function testAShareOutsideTheMimeWhitelistIsNotWatermarked(): void {
		$config = $this->sharePolicy(internal: true);
		$config->setMimeTypes('application/pdf');
		$this->shareAccess->method('isInternalShareAccess')->willReturn(true);

		$this->assertFalse($this->service->isDeliveryCandidate($this->unmarkedFile('image/png')));
	}

	/**
	 * @testWith [5, false]
	 *           [7, true]
	 *
	 * Both directions, because "false" is the answer a scope check that never ran would
	 * also give - the tagged case is what proves the tag is being read at all.
	 */
	public function testASharedFileIsWatermarkedOnlyInsideTheTaggedFolder(
		int $taggedFolderId,
		bool $expected,
	): void {
		$config = $this->sharePolicy(internal: true);
		$config->setFolderTag('7');
		$this->shareAccess->method('isInternalShareAccess')->willReturn(true);
		$this->tagObjectMapper->method('getObjectIdsForTags')->willReturn([(string)$taggedFolderId]);

		$file = $this->unmarkedFile();
		$parent = $this->createMock(Folder::class);
		$parent->method('getId')->willReturn(7);
		$file->method('getParent')->willReturn($parent);

		$this->assertSame($expected, $this->service->isDeliveryCandidate($file));
	}

	/**
	 * A file too large to render is **refused**, not served clean.
	 *
	 * A marked file cleared the byte ceiling when it was marked. One watermarked only because
	 * it is leaving through a share never had such a moment, so the ceiling is applied at
	 * delivery - and the app's rule that a watermark it owes is a watermark it delivers or
	 * denies applies here too. Serving the original would hand the clean file to precisely
	 * the recipient the policy exists to name.
	 */
	public function testAnOversizedSharedFileIsDeniedRatherThanServedClean(): void {
		$this->sharePolicy(internal: true);
		$this->shareAccess->method('isInternalShareAccess')->willReturn(true);
		$this->markMapper->method('isMarked')->willReturn(false);

		$applyLimits = $this->createMock(ApplyLimits::class);
		$applyLimits->method('maxBytes')->willReturn(1024);
		$service = new WatermarkService(
			$this->configMapper,
			$this->logMapper,
			$this->markMapper,
			$this->pdfWatermarker,
			$this->pdfFlattener,
			$this->imageWatermarker,
			$this->userSession,
			$this->tagObjectMapper,
			$this->logger,
			$this->imageStore,
			$this->imageLimits,
			$applyLimits,
			$this->shareAccess,
			$this->shareRecipient,
			$this->l10n(),
			$this->timeZone,
		);

		$this->pdfWatermarker->expects($this->never())->method('apply');

		$this->expectException(WatermarkRequiredException::class);
		$service->watermarkForDownload($this->file('application/pdf', 42, 'ORIGINAL', 2048));
	}

	// -----------------------------------------------------------------------
	// Auditing
	// -----------------------------------------------------------------------

	public function testDeliveryIsNotAuditedUnlessThePolicyAsksForIt(): void {
		$config = $this->config();
		$config->setLogDelivery(false);
		$this->configMapper->method('findGlobal')->willReturn($config);
		$file = $this->markedFile('application/pdf');

		// One row per fetch, forever, is what this switch exists to prevent: an archive of
		// 200 members downloaded twice a day is 400 rows a day.
		$this->logMapper->expects($this->never())->method('insertLog');

		$this->cleanup($this->service->watermarkForDownload($file));
	}

	public function testDeliveryIsAuditedWhenThePolicyAsksForIt(): void {
		$config = $this->config();
		$config->setLogDelivery(true);
		$this->configMapper->method('findGlobal')->willReturn($config);
		$this->userSession->method('getUser')->willReturn($this->user('bob'));
		$file = $this->markedFile('application/pdf');

		$this->logMapper->expects($this->once())
			->method('insertLog')
			->with('bob', 42, '/alice/files/report.pdf', WatermarkService::TRIGGER_DELIVERED, null);

		$this->cleanup($this->service->watermarkForDownload($file));
	}

	/** Marking is one row per policy decision, not one per read, so it is never optional. */
	public function testMarkingIsAuditedEvenWithDeliveryAuditOff(): void {
		$config = $this->config();
		$config->setLogDelivery(false);
		$this->configMapper->method('findGlobal')->willReturn($config);
		$this->markMapper->method('mark')->willReturn(true);

		$this->logMapper->expects($this->once())->method('insertLog');

		$this->service->mark($this->file(), WatermarkService::TRIGGER_ON_DEMAND, $this->user('alice'));
	}

	// -----------------------------------------------------------------------
	// Policy resolution
	// -----------------------------------------------------------------------

	public function testResolveConfigReturnsTheGlobalPolicy(): void {
		$config = $this->config(WatermarkService::TRIGGER_ON_UPLOAD);
		$this->configMapper->method('findGlobal')->willReturn($config);

		$this->assertSame($config, $this->service->resolveConfig());
	}

	public function testResolveConfigFallsBackToTheBuiltInDefault(): void {
		$this->configMapper->method('findGlobal')->willThrowException(new DoesNotExistException('none'));

		$config = $this->service->resolveConfig();

		$this->assertSame(WatermarkService::TRIGGER_ON_DEMAND, $config->getTrigger());
		$this->assertSame('{displayname} - {date}', $config->getTextTemplate());
	}

	public function testResolveConfigIsMemoisedPerRequest(): void {
		$this->configMapper->expects($this->once())->method('findGlobal')->willReturn($this->config());

		$this->service->resolveConfig();
		$this->service->resolveConfig();
	}

	public function testTheDefaultConfigIsMemoisedToo(): void {
		$this->configMapper->expects($this->once())
			->method('findGlobal')
			->willThrowException(new DoesNotExistException('none'));

		$this->service->resolveConfig();
		$this->service->resolveConfig();
	}

	/**
	 * A trigger this version does not have resolves to nothing at all.
	 *
	 * An instance upgraded from a version with four triggers keeps whatever it had saved,
	 * and the two that are gone decided *when* a watermark was produced rather than which
	 * files carried one. Mapping such a row onto a live trigger would either mark every
	 * upload on the instance or mark none, and picking either silently is worse than
	 * marking nothing and saying so.
	 */
	public function testAnUnrecognisedStoredTriggerResolvesToNothing(): void {
		$this->configMapper->method('findGlobal')->willReturn($this->config('on_share'));
		$this->logger->expects($this->once())->method('warning');

		$this->assertNull($this->service->effectiveTrigger());
	}

	/** @dataProvider liveTriggerProvider */
	public function testALiveTriggerResolvesToItself(string $trigger): void {
		$this->configMapper->method('findGlobal')->willReturn($this->config($trigger));

		$this->assertSame($trigger, $this->service->effectiveTrigger());
	}

	/** @return array<string, array{string}> */
	public static function liveTriggerProvider(): array {
		return [
			'on demand' => [WatermarkService::TRIGGER_ON_DEMAND],
			'on upload' => [WatermarkService::TRIGGER_ON_UPLOAD],
		];
	}

	// -----------------------------------------------------------------------
	// Previews
	// -----------------------------------------------------------------------

	/**
	 * The watermark is scaled to the preview it is drawn on.
	 *
	 * A 24pt mark configured for a full-size page covers a 64px thumbnail with two letters.
	 * Scaling against a 1000px reference keeps it occupying the same fraction of the image
	 * at every size.
	 */
	public function testThePreviewFontIsScaledToThePreviewSize(): void {
		$config = $this->config();
		$config->setFontSize(40);
		$this->configMapper->method('findGlobal')->willReturn($config);

		$sizes = [];
		$this->imageWatermarker->method('apply')->willReturnCallback(
			static function ($src, $dst, WatermarkConfig $config) use (&$sizes): void {
				$sizes[] = $config->getFontSize();
			},
		);

		$this->service->watermarkPreviewImage($this->file('image/png'), '/tmp/a', '/tmp/b', 1000);
		$this->service->watermarkPreviewImage($this->file('image/png'), '/tmp/a', '/tmp/b', 500);

		$this->assertSame([40, 20], $sizes);
	}

	/** Below the floor GD draws a blob rather than text, so the floor is where it stops. */
	public function testThePreviewFontHasAFloor(): void {
		$config = $this->config();
		$config->setFontSize(24);
		$this->configMapper->method('findGlobal')->willReturn($config);

		$size = null;
		$this->imageWatermarker->method('apply')->willReturnCallback(
			static function ($src, $dst, WatermarkConfig $config) use (&$size): void {
				$size = $config->getFontSize();
			},
		);

		$this->service->watermarkPreviewImage($this->file('image/png'), '/tmp/a', '/tmp/b', 32);

		$this->assertGreaterThanOrEqual(6, $size);
	}

	/**
	 * The scaling must not survive the call. The config is the entity the mapper handed us
	 * and it is memoised for the request, so mutating it in place would shrink the
	 * watermark on every later render in the same request - including the download.
	 */
	public function testScalingAPreviewDoesNotShrinkTheRequestsOtherRenders(): void {
		$config = $this->config();
		$config->setFontSize(40);
		$this->configMapper->method('findGlobal')->willReturn($config);
		$this->imageWatermarker->method('apply');

		$this->service->watermarkPreviewImage($this->file('image/png'), '/tmp/a', '/tmp/b', 100);

		$this->assertSame(40, $this->service->resolveConfig()->getFontSize());
	}

	// -----------------------------------------------------------------------
	// Marks that travel with a copy
	// -----------------------------------------------------------------------

	/**
	 * @param ?string $originOwner null for a mark placed directly on a file
	 */
	private function mark(string $trigger, ?string $originOwner = null): WatermarkMark {
		$mark = new WatermarkMark();
		$mark->setTrigger($trigger);
		$mark->setOriginOwner($originOwner);
		return $mark;
	}

	/**
	 * The copy is marked, and the mark records whose file it descends from.
	 *
	 * The origin is the whole point: the copier owns the copy, so without it the remove gate
	 * would read ownership as permission and hand them the off switch.
	 */
	public function testACopyOfAMarkedFileInheritsTheMarkAndNamesItsOrigin(): void {
		$this->configMapper->method('findGlobal')->willReturn($this->config());
		$this->userSession->method('getUser')->willReturn($this->user('bob'));
		$source = $this->file(id: 42, owner: $this->user('alice'));
		$target = $this->file(id: 99);

		$this->markMapper->expects($this->once())
			->method('mark')
			->with(99, 'bob', WatermarkService::TRIGGER_INHERITED, null, 'alice', 42)
			->willReturn(true);

		$this->assertTrue($this->service->inheritMark($target, $source));
	}

	/**
	 * **Inheriting consults neither ceiling nor scope.**
	 *
	 * Both would refuse this file - it is far over the byte ceiling and its type is outside
	 * the policy's whitelist - and `mark()` would throw for either reason. Refusing here
	 * would mean an unmarked clean copy of a protected document, which is the escape being
	 * closed rebuilt out of the checks. The bytes are the source's own, so the ceiling the
	 * source passed still holds.
	 */
	public function testInheritingIgnoresTheCeilingsAndTheScope(): void {
		$config = $this->config();
		$config->setMimeTypes('image/png');
		$this->configMapper->method('findGlobal')->willReturn($config);
		$this->userSession->method('getUser')->willReturn($this->user('bob'));

		$source = $this->file(mime: 'application/pdf', id: 42, size: PHP_INT_MAX, owner: $this->user('alice'));
		$target = $this->file(mime: 'application/pdf', id: 99, size: PHP_INT_MAX);

		$this->markMapper->expects($this->once())->method('mark')->willReturn(true);

		$this->assertTrue($this->service->inheritMark($target, $source));
	}

	/**
	 * A copy of a copy still names the person the protection started with.
	 *
	 * Bob owns the file being copied, and Bob is not who may release it: he holds it only
	 * because he copied it from Alice. Reading the owner rather than the existing mark's
	 * origin would launder the protection away in two copies.
	 */
	public function testACopyOfACopyStillNamesTheFirstOwner(): void {
		$this->configMapper->method('findGlobal')->willReturn($this->config());
		$this->userSession->method('getUser')->willReturn($this->user('carol'));
		$source = $this->file(id: 42, owner: $this->user('bob'));

		$this->markMapper->method('findByFileId')->with(42)->willReturn(
			$this->mark(WatermarkService::TRIGGER_INHERITED, 'alice'),
		);
		$this->markMapper->expects($this->once())
			->method('mark')
			->with(99, 'carol', WatermarkService::TRIGGER_INHERITED, null, 'alice', 42)
			->willReturn(true);

		$this->service->inheritMark($this->file(id: 99), $source);
	}

	public function testInheritingAnAlreadyMarkedCopyIsANoOp(): void {
		$this->configMapper->method('findGlobal')->willReturn($this->config());
		$this->markMapper->method('mark')->willReturn(false);
		$this->logMapper->expects($this->never())->method('insertLog');

		$this->assertFalse(
			$this->service->inheritMark($this->file(id: 99), $this->file(id: 42)),
		);
	}

	// -----------------------------------------------------------------------
	// Who may take a mark off
	// -----------------------------------------------------------------------

	/**
	 * The batched form of the same rule, for the status endpoint.
	 *
	 * Three files, one of each kind: an ordinary mark (ownership governs it, so it locks
	 * nobody), one inherited from somebody else, and one inherited from the asker
	 * themselves. Only the middle one comes back.
	 */
	public function testLockedFileIdsReturnsOnlyMarksForeignToTheAsker(): void {
		$this->markMapper->method('findByFileIds')->with([1, 2, 3])->willReturn([
			1 => $this->mark(WatermarkService::TRIGGER_ON_DEMAND),
			2 => $this->mark(WatermarkService::TRIGGER_INHERITED, 'alice'),
			3 => $this->mark(WatermarkService::TRIGGER_INHERITED, 'bob'),
		]);

		$this->assertSame([2], $this->service->lockedFileIds([1, 2, 3], 'bob'));
	}

	public function testLockedFileIdsIsEmptyWhenNothingIsMarked(): void {
		$this->markMapper->method('findByFileIds')->willReturn([]);

		$this->assertSame([], $this->service->lockedFileIds([1, 2], 'bob'));
	}

	public function testTheOwnerOfADirectlyMarkedFileMayUnmarkIt(): void {
		$this->markMapper->method('findByFileId')->willReturn(
			$this->mark(WatermarkService::TRIGGER_ON_DEMAND),
		);

		$this->assertSame(
			WatermarkService::UNMARK_OK,
			$this->service->unmarkVerdict($this->file(owner: $this->user('alice')), 'alice'),
		);
	}

	public function testANonOwnerMayNotUnmark(): void {
		$this->markMapper->method('findByFileId')->willReturn(
			$this->mark(WatermarkService::TRIGGER_ON_DEMAND),
		);

		$this->assertSame(
			WatermarkService::UNMARK_NOT_OWNER,
			$this->service->unmarkVerdict($this->file(owner: $this->user('alice')), 'bob'),
		);
	}

	/**
	 * **The case ownership cannot answer.** Bob owns this file outright - he owns it because
	 * he copied it - and he is still refused.
	 */
	public function testTheOwnerOfAnInheritedMarkMayNotUnmarkIt(): void {
		$this->markMapper->method('findByFileId')->willReturn(
			$this->mark(WatermarkService::TRIGGER_INHERITED, 'alice'),
		);

		$this->assertSame(
			WatermarkService::UNMARK_INHERITED,
			$this->service->unmarkVerdict($this->file(owner: $this->user('bob')), 'bob'),
		);
	}

	/**
	 * The person it descends from may release it, even though the file is not hers.
	 *
	 * The mirror of the case above, and what keeps an inherited mark from being a one-way
	 * door: the protection belongs to whoever it protects.
	 */
	public function testTheOriginOfAnInheritedMarkMayUnmarkIt(): void {
		$this->markMapper->method('findByFileId')->willReturn(
			$this->mark(WatermarkService::TRIGGER_INHERITED, 'alice'),
		);

		$this->assertSame(
			WatermarkService::UNMARK_OK,
			$this->service->unmarkVerdict($this->file(owner: $this->user('bob')), 'alice'),
		);
	}

	/**
	 * An inherited mark that names nobody is removable by nobody.
	 *
	 * The fail-closed direction, and the same call the ownership check makes for a node
	 * whose owner will not resolve. An empty uid must not match an unset origin.
	 */
	public function testAnInheritedMarkWithNoOriginIsRemovableByNobody(): void {
		$this->markMapper->method('findByFileId')->willReturn(
			$this->mark(WatermarkService::TRIGGER_INHERITED, null),
		);

		$this->assertSame(
			WatermarkService::UNMARK_INHERITED,
			$this->service->unmarkVerdict($this->file(owner: $this->user('bob')), ''),
		);
	}

	private function cleanup(?string $tmpPath): void {
		if ($tmpPath === null) {
			return;
		}
		if (file_exists($tmpPath)) {
			@unlink($tmpPath);
		}
		@rmdir(dirname($tmpPath));
	}
}
