<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Dav;

use OCA\DAV\Connector\Sabre\Directory as DavDirectory;
use OCA\DAV\Connector\Sabre\File as DavFile;
use OCA\FilesWatermark\BackgroundJob\WatermarkOnUploadJob;
use OCA\FilesWatermark\Dav\UploadWatermarkPlugin;
use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Server;
use Sabre\DAV\Tree;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;

/**
 * @covers \OCA\FilesWatermark\Dav\UploadWatermarkPlugin
 */
class UploadWatermarkPluginTest extends TestCase {

	private const FILE_ID = 42;
	private const UID = 'alice';

	private WatermarkService&MockObject $watermarkService;
	private IUserSession&MockObject $userSession;
	private IJobList&MockObject $jobList;
	private Tree&MockObject $tree;
	private Server $server;

	protected function setUp(): void {
		parent::setUp();
		$this->watermarkService = $this->createMock(WatermarkService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->tree = $this->createMock(Tree::class);
		$this->server = new Server();
		$this->server->tree = $this->tree;

		$this->watermarkService->method('isSupported')->willReturn(true);
		$this->signedInAs(self::UID);
	}

	private function plugin(): UploadWatermarkPlugin {
		$plugin = new UploadWatermarkPlugin(
			$this->watermarkService,
			$this->userSession,
			$this->jobList,
			$this->createMock(LoggerInterface::class),
		);
		$plugin->initialize($this->server);
		return $plugin;
	}

	private function signedInAs(?string $uid): void {
		if ($uid === null) {
			$this->userSession->method('getUser')->willReturn(null);
			return;
		}
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	private function davFile(string $mime = 'application/pdf'): DavFile {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(self::FILE_ID);
		$file->method('getMimeType')->willReturn($mime);
		$file->method('getPath')->willReturn('/alice/files/report.pdf');

		$davFile = $this->createMock(DavFile::class);
		$davFile->method('getNode')->willReturn($file);
		return $davFile;
	}

	private function config(string $trigger): WatermarkConfig {
		$config = new WatermarkConfig();
		$config->setTrigger($trigger);
		return $config;
	}

	private function put(string $path = 'files/alice/report.pdf'): Request {
		return new Request('PUT', '/' . $path);
	}

	private function move(string $destination): Request {
		$request = new Request('MOVE', '/uploads/alice/chunked-id/.file');
		$request->setHeader('Destination', $destination);
		return $request;
	}

	// ---------------------------------------------------------------------
	// Hook registration — the chunked-upload regression.
	// ---------------------------------------------------------------------

	public function testHooksBothPutAndMove(): void {
		$this->plugin();

		$this->assertNotEmpty(
			$this->server->listeners('afterMethod:PUT'),
			'plain uploads land via PUT',
		);
		// Regression: chunked uploads assemble with a MOVE and never PUT their final
		// path, so a PUT-only hook silently skips every large file.
		$this->assertNotEmpty(
			$this->server->listeners('afterMethod:MOVE'),
			'chunked uploads land via MOVE',
		);
	}

	public function testChunkedUploadWatermarksTheMoveDestination(): void {
		$davFile = $this->davFile();
		$this->tree->expects($this->once())
			->method('getNodeForPath')
			// calculateUri() strips the origin, leaving the DAV-relative path.
			->with('files/alice/big.pdf')
			->willReturn($davFile);

		$this->watermarkService->method('resolveConfig')->willReturn($this->config('on_upload'));
		$this->watermarkService->expects($this->once())
			->method('watermarkInPlace')
			->willReturn(true);

		$this->plugin()->afterWrite(
			$this->move('http://localhost/files/alice/big.pdf'),
			new Response(),
		);
	}

	public function testMoveWithoutDestinationIsIgnored(): void {
		$this->tree->expects($this->never())->method('getNodeForPath');

		$request = new Request('MOVE', '/uploads/alice/chunked-id/.file');
		$this->plugin()->afterWrite($request, new Response());
	}

	// ---------------------------------------------------------------------
	// The happy path and the job hand-off.
	// ---------------------------------------------------------------------

	public function testAppliesInlineAndRemovesTheNowRedundantJob(): void {
		$this->tree->method('getNodeForPath')->willReturn($this->davFile());
		$this->watermarkService->method('resolveConfig')->willReturn($this->config('on_upload'));
		$this->watermarkService->expects($this->once())
			->method('watermarkInPlace')
			->willReturn(true);

		// Burned in-request, so the job NodeWrittenListener queued has nothing left to do.
		$this->jobList->expects($this->once())
			->method('remove')
			->with(WatermarkOnUploadJob::class, ['fileId' => self::FILE_ID, 'uid' => self::UID]);

		$this->plugin()->afterWrite($this->put(), new Response());
	}

	public function testJobIsLeftQueuedWhenNothingWasApplied(): void {
		$this->tree->method('getNodeForPath')->willReturn($this->davFile());
		$this->watermarkService->method('resolveConfig')->willReturn($this->config('on_upload'));
		$this->watermarkService->method('watermarkInPlace')->willReturn(false);

		$this->jobList->expects($this->never())->method('remove');

		$this->plugin()->afterWrite($this->put(), new Response());
	}

	public function testFailedBurnLeavesTheJobQueuedAndDoesNotFailTheUpload(): void {
		$this->tree->method('getNodeForPath')->willReturn($this->davFile());
		$this->watermarkService->method('resolveConfig')->willReturn($this->config('on_upload'));
		$this->watermarkService->method('watermarkInPlace')
			->willThrowException(new \RuntimeException('renderer exploded'));

		// Cron retries it out of band; an upload must not fail for want of a watermark.
		$this->jobList->expects($this->never())->method('remove');

		$this->plugin()->afterWrite($this->put(), new Response());
	}

	// ---------------------------------------------------------------------
	// No-ops.
	// ---------------------------------------------------------------------

	public function testWrongTriggerIsANoOp(): void {
		$this->tree->method('getNodeForPath')->willReturn($this->davFile());
		$this->watermarkService->method('resolveConfig')->willReturn($this->config('on_download'));

		// on_download is delivery-time; burning it into the stored bytes would be wrong.
		$this->watermarkService->expects($this->never())->method('watermarkInPlace');
		$this->jobList->expects($this->never())->method('remove');

		$this->plugin()->afterWrite($this->put(), new Response());
	}

	public function testUnsupportedMimeIsANoOp(): void {
		$service = $this->createMock(WatermarkService::class);
		$service->method('isSupported')->willReturn(false);
		$service->expects($this->never())->method('resolveConfig');
		$service->expects($this->never())->method('watermarkInPlace');

		$this->tree->method('getNodeForPath')->willReturn($this->davFile('application/zip'));

		$plugin = new UploadWatermarkPlugin(
			$service,
			$this->userSession,
			$this->jobList,
			$this->createMock(LoggerInterface::class),
		);
		$plugin->initialize($this->server);
		$plugin->afterWrite($this->put(), new Response());
	}

	public function testNoSessionIsANoOp(): void {
		// Public file-drop uploads have no session to attribute a watermark to.
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$this->tree->method('getNodeForPath')->willReturn($this->davFile());
		$this->watermarkService->expects($this->never())->method('watermarkInPlace');

		$plugin = new UploadWatermarkPlugin(
			$this->watermarkService,
			$userSession,
			$this->jobList,
			$this->createMock(LoggerInterface::class),
		);
		$plugin->initialize($this->server);
		$plugin->afterWrite($this->put(), new Response());
	}

	public function testMissingNodeIsANoOp(): void {
		$this->tree->method('getNodeForPath')->willThrowException(new NotFound());
		$this->watermarkService->expects($this->never())->method('watermarkInPlace');

		$this->plugin()->afterWrite($this->put(), new Response());
	}

	public function testDirectoryTargetIsANoOp(): void {
		// A MOVE of a folder is not an upload.
		$this->tree->method('getNodeForPath')->willReturn($this->createMock(DavDirectory::class));
		$this->watermarkService->expects($this->never())->method('watermarkInPlace');

		$this->plugin()->afterWrite($this->put('files/alice/folder'), new Response());
	}

	public function testUnresolvableConfigIsANoOp(): void {
		$this->tree->method('getNodeForPath')->willReturn($this->davFile());
		$this->watermarkService->method('resolveConfig')
			->willThrowException(new \RuntimeException('db down'));

		$this->watermarkService->expects($this->never())->method('watermarkInPlace');

		$this->plugin()->afterWrite($this->put(), new Response());
	}
}
