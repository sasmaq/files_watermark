<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Middleware;

use OC\Core\Controller\AvatarController;
use OC\Core\Controller\TaskProcessingApiController;
use OCA\FilesWatermark\Middleware\TaskProcessingMiddleware;
use OCA\FilesWatermark\Service\ShareAccess;
use OCA\FilesWatermark\Service\WatermarkRequiredException;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager as ITaskProcessingManager;
use OCP\TaskProcessing\Task;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Core's task processing API streams any file a task names, to the task's user and to the
 * ExApp running it, straight from storage - so naming a marked file in a task was a way to
 * fetch it clean. These pin the replacement of that stream.
 *
 * @covers \OCA\FilesWatermark\Middleware\TaskProcessingMiddleware
 */
class TaskProcessingMiddlewareTest extends TestCase {

	private const TASK_ID = 31;
	private const FILE_ID = 77;

	private WatermarkService&MockObject $watermarkService;
	private ITaskProcessingManager&MockObject $taskManager;
	private IRootFolder&MockObject $rootFolder;
	private ShareAccess $shareAccess;

	/** The user signed in to the request, if any. */
	private ?string $signedIn = 'bob';

	/** @var array<string, Folder&MockObject> user folders by uid */
	private array $userFolders = [];

	/** @var string[] */
	private array $tmpFiles = [];

	protected function setUp(): void {
		parent::setUp();
		$this->watermarkService = $this->createMock(WatermarkService::class);
		$this->taskManager = $this->createMock(ITaskProcessingManager::class);
		$this->taskManager->method('getTask')->with(self::TASK_ID)->willReturn($this->task('bob'));
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->rootFolder->method('getUserFolder')
			->willReturnCallback(fn (string $uid): Folder => $this->userFolders[$uid] ?? $this->userFolder([]));
	}

	protected function tearDown(): void {
		foreach ($this->tmpFiles as $path) {
			@unlink($path);
			@rmdir(dirname($path));
		}
		parent::tearDown();
	}

	private function middleware(): TaskProcessingMiddleware {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key): mixed => match ($key) {
			'taskId' => (string)self::TASK_ID,
			'fileId' => (string)self::FILE_ID,
			default => null,
		});

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->signedIn === null ? null : $this->user($this->signedIn));
		$this->shareAccess = new ShareAccess($session);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): IUser => $this->user($uid));

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new TaskProcessingMiddleware(
			$this->watermarkService,
			$this->shareAccess,
			$this->taskManager,
			$this->rootFolder,
			$users,
			$request,
			$l,
			new NullLogger(),
		);
	}

	private function controller(): TaskProcessingApiController&MockObject {
		return $this->createMock(TaskProcessingApiController::class);
	}

	private function user(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	private function task(?string $uid): Task {
		return new Task('core:analyze-images', ['images' => [self::FILE_ID]], 'assistant', $uid);
	}

	/** @param array<int, ?\OCP\Files\Node> $byId */
	private function userFolder(array $byId): Folder&MockObject {
		$folder = $this->createMock(Folder::class);
		$folder->method('getFirstNodeById')
			->willReturnCallback(static fn (int $id): ?\OCP\Files\Node => $byId[$id] ?? null);
		return $folder;
	}

	/** The file in Bob's folder, as he reaches it. */
	private function bobsFile(): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(self::FILE_ID);
		$this->userFolders['bob'] = $this->userFolder([self::FILE_ID => $file]);
		return $file;
	}

	/** What `getFileContentsInternal` answers with: the stored bytes, named and typed. */
	private function coresStream(): StreamResponse {
		$stream = fopen('php://memory', 'r+b');
		fwrite($stream, 'CLEAN-ORIGINAL');
		rewind($stream);

		$response = new StreamResponse($stream);
		$response->addHeader('Content-Disposition', 'attachment; filename="report.pdf"');
		$response->addHeader('Content-Type', 'application/pdf');
		return $response;
	}

	private function renderedCopy(string $contents = 'WATERMARKED'): string {
		$dir = sys_get_temp_dir() . '/nc_watermark_test_' . uniqid('', true);
		mkdir($dir);
		$path = $dir . '/render.pdf';
		file_put_contents($path, $contents);
		$this->tmpFiles[] = $path;
		return $path;
	}

	/** The bytes a `StreamResponse` sends, read the way core's output reads them. */
	private function body(StreamResponse $response): string {
		$body = '';
		$output = $this->createMock(IOutput::class);
		$output->method('getHttpResponseCode')->willReturn(200);
		$output->method('setReadfile')->willReturnCallback(static function ($stream) use (&$body): bool {
			$body = is_resource($stream) ? (string)stream_get_contents($stream) : (string)file_get_contents($stream);
			return true;
		});

		$response->callback($output);

		return $body;
	}

	/**
	 * The bypass this exists for: a marked file named in Bob's own task, fetched back through
	 * core's route as stored.
	 */
	public function testAProtectedFileIsReplacedWithTheReadersCopy(): void {
		$file = $this->bobsFile();
		$this->watermarkService->expects($this->once())
			->method('watermarkForDownload')
			->with($file)
			->willReturn($this->renderedCopy());

		$response = $this->middleware()->afterController($this->controller(), 'getFileContents', $this->coresStream());

		$this->assertInstanceOf(StreamResponse::class, $response);
		$this->assertSame('WATERMARKED', $this->body($response));
		$this->assertSame('attachment; filename="report.pdf"', $response->getHeaders()['Content-Disposition']);
		$this->assertSame('application/pdf', $response->getHeaders()['Content-Type']);
		$this->assertSame('private, no-store', $response->getHeaders()['Cache-Control']);
	}

	/**
	 * The ExApp's route hands the same bytes to the provider. Its request is signed in as
	 * nobody, which read by the session is a public link, and the copy would name the owner.
	 * The task is Bob's, so he is the reader.
	 */
	public function testTheExAppsCopyIsBobsAndNoPublicLink(): void {
		$this->signedIn = null;
		$file = $this->bobsFile();
		$this->watermarkService->expects($this->once())
			->method('watermarkForDownload')
			->with($file)
			->willReturn($this->renderedCopy());

		$response = $this->middleware()->afterController($this->controller(), 'getFileContentsExApp', $this->coresStream());

		$this->assertSame('WATERMARKED', $this->body($response));
		$this->assertSame('bob', $this->shareAccess->notedReader()?->getUID());
		$this->assertFalse($this->shareAccess->isExternalShareAccess());
	}

	/** An ExApp can sign its request in as anybody. The task still says whose copy it is. */
	public function testTheTasksUserIsTheReaderWhoeverIsSignedIn(): void {
		$this->signedIn = 'carol';
		$file = $this->bobsFile();
		$this->userFolders['carol'] = $this->userFolder([self::FILE_ID => $this->createMock(File::class)]);
		$this->watermarkService->expects($this->once())
			->method('watermarkForDownload')
			->with($file)
			->willReturn($this->renderedCopy());

		$this->middleware()->afterController($this->controller(), 'getFileContentsExApp', $this->coresStream());

		$this->assertSame('bob', $this->shareAccess->notedReader()?->getUID());
	}

	/**
	 * Both routes are authenticated. An ExApp working through a queue of tasks would run
	 * into the anonymous allowance if its renders were charged against it.
	 */
	public function testTheRenderIsNotChargedAgainstTheAnonymousAllowance(): void {
		$this->signedIn = null;
		$file = $this->bobsFile();
		$this->watermarkService->expects($this->once())
			->method('watermarkForDownload')
			->with($file, false)
			->willReturn(null);

		$this->middleware()->afterController($this->controller(), 'getFileContentsExApp', $this->coresStream());
	}

	public function testAnUnprotectedFileKeepsCoresStream(): void {
		$this->bobsFile();
		$this->watermarkService->method('watermarkForDownload')->willReturn(null);
		$original = $this->coresStream();

		$this->assertSame($original, $this->middleware()->afterController($this->controller(), 'getFileContents', $original));
	}

	public function testAProtectedFileThatCannotBeRenderedIsRefused(): void {
		$this->bobsFile();
		$this->watermarkService->method('watermarkForDownload')
			->willThrowException(new WatermarkRequiredException('/bob/files/report.pdf', 'could not be generated'));

		$response = $this->middleware()->afterController($this->controller(), 'getFileContents', $this->coresStream());

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(403, $response->getStatus());
		// `OCSMiddleware` carries `message` into the envelope it rewraps a 403 in.
		$this->assertSame(['message' => 'could not be generated'], $response->getData());
	}

	/** A provider's output lives in appdata, outside Bob's folder, and nothing marks it. */
	public function testAFileOutsideTheReadersFolderKeepsCoresStreamWhenUnmarked(): void {
		$this->watermarkService->method('isMarked')->with(self::FILE_ID)->willReturn(false);
		$this->watermarkService->expects($this->never())->method('watermarkForDownload');
		$original = $this->coresStream();

		$this->assertSame($original, $this->middleware()->afterController($this->controller(), 'getFileContentsExApp', $original));
	}

	/** A marked file Bob no longer reaches cannot be judged as his fetch, so it is refused. */
	public function testAMarkedFileOutsideTheReadersFolderIsRefused(): void {
		$this->watermarkService->method('isMarked')->with(self::FILE_ID)->willReturn(true);
		$this->watermarkService->expects($this->never())->method('watermarkForDownload');

		$response = $this->middleware()->afterController($this->controller(), 'getFileContentsExApp', $this->coresStream());

		$this->assertSame(403, $response->getStatus());
	}

	public function testAMarkedFileInATaskWithNoUserIsRefused(): void {
		$this->taskManager = $this->createMock(ITaskProcessingManager::class);
		$this->taskManager->method('getTask')->willReturn($this->task(null));
		$this->watermarkService->method('isMarked')->with(self::FILE_ID)->willReturn(true);

		$response = $this->middleware()->afterController($this->controller(), 'getFileContentsExApp', $this->coresStream());

		$this->assertSame(403, $response->getStatus());
	}

	/** Core has just loaded this task, so a task that will not load here is not guessed about. */
	public function testATaskThatWillNotLoadIsRefused(): void {
		$this->taskManager = $this->createMock(ITaskProcessingManager::class);
		$this->taskManager->method('getTask')->willThrowException(new NotFoundException('gone'));
		$this->watermarkService->expects($this->never())->method('watermarkForDownload');

		$response = $this->middleware()->afterController($this->controller(), 'getFileContents', $this->coresStream());

		$this->assertSame(403, $response->getStatus());
	}

	/** Core's own 404 and 500 carry no file, and are left as they are. */
	public function testCoresErrorsAreLeftAlone(): void {
		$this->taskManager->expects($this->never())->method('getTask');
		$notFound = new DataResponse(['message' => 'Not found'], 404);

		$this->assertSame($notFound, $this->middleware()->afterController($this->controller(), 'getFileContents', $notFound));
	}

	/** Every other task processing route answers with JSON about tasks, never a file. */
	public function testTheControllersOtherMethodsAreLeftAlone(): void {
		$this->taskManager->expects($this->never())->method('getTask');
		$original = $this->coresStream();

		$this->assertSame($original, $this->middleware()->afterController($this->controller(), 'getTask', $original));
	}

	public function testOtherControllersAreLeftAlone(): void {
		$this->taskManager->expects($this->never())->method('getTask');
		$original = $this->coresStream();
		$controller = $this->createMock(AvatarController::class);

		$this->assertSame($original, $this->middleware()->afterController($controller, 'getFileContents', $original));
	}
}
