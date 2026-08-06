<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Db\WatermarkConfigMapper;
use OCA\FilesWatermark\Db\WatermarkLogMapper;
use OCA\FilesWatermark\Db\WatermarkMarkMapper;
use OCA\FilesWatermark\Service\ApplyLimits;
use OCA\FilesWatermark\Service\FileTooLargeException;
use OCA\FilesWatermark\Service\ImageLimits;
use OCA\FilesWatermark\Service\ImageTooLargeException;
use OCA\FilesWatermark\Service\ImageWatermarker;
use OCA\FilesWatermark\Service\PdfWatermarker;
use OCA\FilesWatermark\Service\ShareAccess;
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
	private ImageWatermarker&MockObject $imageWatermarker;
	private IUserSession&MockObject $userSession;
	private ISystemTagObjectMapper&MockObject $tagObjectMapper;
	private LoggerInterface&MockObject $logger;
	private WatermarkImageStore&MockObject $imageStore;
	private ImageLimits&MockObject $imageLimits;
	private ApplyLimits&MockObject $applyLimits;
	private ShareAccess&MockObject $shareAccess;
	private WatermarkService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->configMapper = $this->createMock(WatermarkConfigMapper::class);
		$this->logMapper = $this->createMock(WatermarkLogMapper::class);
		$this->markMapper = $this->createMock(WatermarkMarkMapper::class);
		$this->pdfWatermarker = $this->createMock(PdfWatermarker::class);
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

		$this->service = new WatermarkService(
			$this->configMapper,
			$this->logMapper,
			$this->markMapper,
			$this->pdfWatermarker,
			$this->imageWatermarker,
			$this->userSession,
			$this->tagObjectMapper,
			$this->logger,
			$this->imageStore,
			$this->imageLimits,
			$this->applyLimits,
			$this->shareAccess,
			$this->l10n(),
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
			$this->imageWatermarker,
			$this->userSession,
			$this->tagObjectMapper,
			$this->logger,
			$this->imageStore,
			$limits,
			$this->applyLimits,
			$this->shareAccess,
			$this->l10n(),
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

	public function testAnUnsupportedTypeIsNeverADeliveryCandidate(): void {
		// Not even asked: the type check comes first, and a text file has no watermark to
		// carry however it got marked.
		$this->markMapper->expects($this->never())->method('isMarked');

		$this->assertFalse($this->service->isDeliveryCandidate($this->file('text/plain')));
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

		$this->userSession->method('getUser')->willReturnOnConsecutiveCalls(
			$this->user('alice', 'Alice Smith'),
			$this->user('bob', 'Bob Jones'),
		);

		$this->cleanup($this->service->watermarkForDownload($file));
		$this->cleanup($this->service->watermarkForDownload($file));

		$this->assertSame(['Alice Smith', 'Bob Jones'], $names);
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
			$this->imageWatermarker,
			$this->userSession,
			$this->tagObjectMapper,
			$this->logger,
			$this->imageStore,
			$this->imageLimits,
			$applyLimits,
			$this->shareAccess,
			$this->l10n(),
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
