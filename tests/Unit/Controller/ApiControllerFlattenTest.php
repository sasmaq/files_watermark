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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The flattening half of the config endpoint: what the form is told about this
 * host, and what the server accepts regardless of what the form did.
 */
class ApiControllerFlattenTest extends TestCase {

	private WatermarkConfigMapper&MockObject $configMapper;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;
	private PdfFlattener&MockObject $pdfFlattener;
	private ApiController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->configMapper = $this->createMock(WatermarkConfigMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->pdfFlattener = $this->createMock(PdfFlattener::class);

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
			$this->pdfFlattener,
		);

		$this->configMapper->method('insert')->willReturnCallback(
			static fn (WatermarkConfig $config): WatermarkConfig => $config,
		);
	}

	private function loginAdmin(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);
	}

	public function testConfigEndpointReportsWhetherThisHostCanFlatten(): void {
		$this->loginAdmin();
		$this->configMapper->method('findByUser')->willReturn([]);
		$this->configMapper->method('findGlobal')->willReturn(new WatermarkConfig());
		$this->pdfFlattener->method('isAvailable')->willReturn(true);

		$data = $this->controller->getConfig()->getData();

		$this->assertTrue($data['flattenAvailable']);
		$this->assertSame(PdfFlattener::MIN_DPI, $data['flattenDpiRange']['min']);
		$this->assertSame(PdfFlattener::MAX_DPI, $data['flattenDpiRange']['max']);
		$this->assertArrayHasKey('configs', $data);
	}

	public function testConfigEndpointReportsUnavailableWithNoRenderer(): void {
		$this->loginAdmin();
		$this->configMapper->method('findByUser')->willReturn([]);
		$this->configMapper->method('findGlobal')->willReturn(new WatermarkConfig());
		$this->pdfFlattener->method('isAvailable')->willReturn(false);

		$this->assertFalse($this->controller->getConfig()->getData()['flattenAvailable']);
	}

	public function testFlattenIsRejectedWhenTheRendererIsMissing(): void {
		// The form hides the control on such a host, but hiding a control is not an
		// access check — a direct API call must still be refused.
		$this->loginAdmin();
		$this->pdfFlattener->method('isAvailable')->willReturn(false);
		$this->configMapper->expects($this->never())->method('insert');

		$response = $this->controller->saveConfig(
			type: 'text',
			textTemplate: '{username}',
			imagePath: null,
			flattenPdf: true,
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('poppler-utils', $response->getData()['error']);
	}

	public function testFlattenIsStoredWhenTheRendererIsPresent(): void {
		$this->loginAdmin();
		$this->pdfFlattener->method('isAvailable')->willReturn(true);

		$response = $this->controller->saveConfig(
			type: 'text',
			textTemplate: '{username}',
			imagePath: null,
			flattenPdf: true,
			flattenDpi: 200,
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['flattenPdf']);
		$this->assertSame(200, $response->getData()['flattenDpi']);
	}

	public function testFlatteningIsOffByDefault(): void {
		// It destroys the text layer, so it is never on unless asked for.
		$this->loginAdmin();

		$data = $this->controller->saveConfig(
			type: 'text',
			textTemplate: '{username}',
			imagePath: null,
		)->getData();

		$this->assertFalse($data['flattenPdf']);
		$this->assertSame(PdfFlattener::DEFAULT_DPI, $data['flattenDpi']);
	}

	/**
	 * @dataProvider dpiProvider
	 */
	public function testDpiIsClampedToTheSupportedRange(int $sent, int $stored): void {
		$this->loginAdmin();
		$this->pdfFlattener->method('isAvailable')->willReturn(true);

		$data = $this->controller->saveConfig(
			type: 'text',
			textTemplate: '{username}',
			imagePath: null,
			flattenPdf: true,
			flattenDpi: $sent,
		)->getData();

		$this->assertSame($stored, $data['flattenDpi']);
	}

	/** @return array<string, array{int, int}> */
	public static function dpiProvider(): array {
		return [
			'absurdly high' => [20000, PdfFlattener::MAX_DPI],
			'zero' => [0, PdfFlattener::MIN_DPI],
			'negative' => [-300, PdfFlattener::MIN_DPI],
			'in range' => [150, 150],
		];
	}
}
