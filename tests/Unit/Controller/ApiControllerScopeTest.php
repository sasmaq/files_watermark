<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Controller;

use OCA\FilesWatermark\Controller\ApiController;
use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Db\WatermarkConfigMapper;
use OCA\FilesWatermark\Db\WatermarkLogMapper;
use OCA\FilesWatermark\Service\PdfFlattener;
use OCA\FilesWatermark\Service\WatermarkImageStore;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\AppFramework\Http;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\TagNotFoundException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The "Where to apply" fields. Both used to be stored verbatim, and both had a
 * plausible wrong value that turned watermarking off with nothing on screen to
 * say so — a mistyped MIME type matched no file, and a tag *name* instead of an
 * id made every watermark request die on `Tag id must be integer`.
 */
class ApiControllerScopeTest extends TestCase {

	private WatermarkConfigMapper&MockObject $configMapper;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;
	private ISystemTagManager&MockObject $tagManager;
	private ApiController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->configMapper = $this->createMock(WatermarkConfigMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->tagManager = $this->createMock(ISystemTagManager::class);

		$this->controller = new ApiController(
			'files_watermark',
			$this->createMock(IRequest::class),
			$this->configMapper,
			$this->createMock(WatermarkLogMapper::class),
			$this->createMock(WatermarkService::class),
			$this->createMock(IRootFolder::class),
			$this->userSession,
			$this->groupManager,
			$this->createMock(WatermarkImageStore::class),
			$this->createMock(PdfFlattener::class),
			$this->tagManager,
		);

		$this->configMapper->method('insert')->willReturnCallback(
			static fn (WatermarkConfig $config): WatermarkConfig => $config,
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);
	}

	private function save(?string $mimeTypes = null, ?string $folderTag = null) {
		return $this->controller->saveConfig(
			type: 'text',
			textTemplate: '{username}',
			imagePath: null,
			mimeTypes: $mimeTypes,
			folderTag: $folderTag,
		);
	}

	public function testUnsupportedMimeTypeIsRejected(): void {
		$this->configMapper->expects($this->never())->method('insert');

		$response = $this->save(mimeTypes: 'aplication/pdf');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('aplication/pdf', $response->getData()['error']);
		// The message has to name what *is* allowed, or the admin is left guessing.
		$this->assertStringContainsString('application/pdf', $response->getData()['error']);
	}

	public function testARenderableButUnsupportedTypeIsRejected(): void {
		// image/gif is a real MIME type and a real image; this app still cannot draw
		// on it, so accepting it would promise something the renderer will refuse.
		$response = $this->save(mimeTypes: 'image/gif');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('image/gif', $response->getData()['error']);
	}

	public function testOneBadTypeAmongGoodOnesIsRejected(): void {
		$response = $this->save(mimeTypes: 'application/pdf, image/tiff');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('image/tiff', $response->getData()['error']);
		$this->assertStringNotContainsString('Unsupported file type(s): application/pdf', $response->getData()['error']);
	}

	public function testSupportedTypesAreStoredNormalised(): void {
		$response = $this->save(mimeTypes: '  application/pdf ,image/png  ');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('application/pdf,image/png', $response->getData()['mimeTypes']);
	}

	/**
	 * @dataProvider blankProvider
	 */
	public function testBlankScopeMeansEverywhere(?string $blank): void {
		$response = $this->save(mimeTypes: $blank, folderTag: $blank);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		// Null, not '': one representation of "no restriction" downstream.
		$this->assertNull($response->getData()['mimeTypes']);
		$this->assertNull($response->getData()['folderTag']);
	}

	/** @return array<string, array{?string}> */
	public static function blankProvider(): array {
		return ['null' => [null], 'empty' => [''], 'whitespace' => ['   ']];
	}

	public function testTagNameIsRejectedRatherThanStored(): void {
		// The regression: 'Confidential' saved fine and then every watermark request
		// returned HTTP 500 from InvalidArgumentException deep in SystemTagManager.
		$this->configMapper->expects($this->never())->method('insert');

		$response = $this->save(folderTag: 'Confidential');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('not a system tag ID', $response->getData()['error']);
	}

	public function testNonExistentTagIdIsRejected(): void {
		$this->tagManager->method('getTagsByIds')
			->willThrowException(new TagNotFoundException('no such tag'));
		$this->configMapper->expects($this->never())->method('insert');

		$response = $this->save(folderTag: '4242');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString("'4242' does not exist", $response->getData()['error']);
	}

	public function testExistingTagIdIsAccepted(): void {
		$this->tagManager->expects($this->once())
			->method('getTagsByIds')
			->with(['7'])
			->willReturn(['7' => $this->createMock(ISystemTag::class)]);

		$response = $this->save(folderTag: '7');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('7', $response->getData()['folderTag']);
	}

	public function testTagIsNotLookedUpWhenNoneIsGiven(): void {
		// No pointless round-trip to the tag manager on every save.
		$this->tagManager->expects($this->never())->method('getTagsByIds');

		$this->assertSame(Http::STATUS_OK, $this->save()->getStatus());
	}
}
