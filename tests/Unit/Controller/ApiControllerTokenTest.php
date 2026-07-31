<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Controller;

use OCA\FilesWatermark\Controller\ApiController;
use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Db\WatermarkConfigMapper;
use OCA\FilesWatermark\Db\WatermarkLogMapper;
use OCA\FilesWatermark\Service\WatermarkImageStore;
use OCA\FilesWatermark\Service\WatermarkService;
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
 * Template-token validation in `saveConfig`.
 *
 * The immediate reason this exists: the settings form offers a chip per token, and the
 * server holds its own allowlist. If the two drift, the UI hands the admin a token that
 * comes straight back as a 400 — which is what would have happened when `{displayname}`
 * was added to the form, had `VALID_TOKENS` not been updated with it.
 */
class ApiControllerTokenTest extends TestCase {

	private WatermarkConfigMapper&MockObject $configMapper;
	private ApiController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->configMapper = $this->createMock(WatermarkConfigMapper::class);
		$userSession = $this->createMock(IUserSession::class);
		$groupManager = $this->createMock(IGroupManager::class);

		$this->controller = new ApiController(
			'files_watermark',
			$this->createMock(IRequest::class),
			$this->configMapper,
			$this->createMock(WatermarkLogMapper::class),
			$this->createMock(WatermarkService::class),
			$this->createMock(IRootFolder::class),
			$userSession,
			$groupManager,
			$this->createMock(WatermarkImageStore::class),
			$this->createMock(ISystemTagManager::class),
		);

		$this->configMapper->method('insert')->willReturnCallback(
			static fn (WatermarkConfig $config): WatermarkConfig => $config,
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession->method('getUser')->willReturn($user);
		$groupManager->method('isAdmin')->willReturn(true);
	}

	/**
	 * Every token the form offers, in one template. This is the drift guard: adding a chip
	 * to `WatermarkForm.vue` without adding the token here fails at this assertion rather
	 * than in an admin's browser.
	 *
	 * @dataProvider tokenProvider
	 */
	public function testEveryOfferedTokenIsAccepted(string $token): void {
		$response = $this->save("Confidential $token");

		$this->assertSame(Http::STATUS_OK, $response->getStatus(), "$token was rejected");
	}

	/** @return array<string, array{string}> */
	public static function tokenProvider(): array {
		return [
			'{displayname}' => ['{displayname}'],
			'{username}' => ['{username}'],
			'{email}' => ['{email}'],
			'{date}' => ['{date}'],
			'{datetime}' => ['{datetime}'],
			'{filename}' => ['{filename}'],
		];
	}

	/**
	 * The two identity tokens side by side — the combination an admin reaches for when the
	 * watermark has to be both readable and unambiguous.
	 */
	public function testBothIdentityTokensCanBeUsedTogether(): void {
		$response = $this->save('{displayname} ({username}) — {date}');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('{displayname} ({username}) — {date}', $response->getData()['textTemplate']);
	}

	public function testUnknownTokenIsRejectedAndNamed(): void {
		$this->configMapper->expects($this->never())->method('insert');

		$response = $this->save('{displayName} — {date}');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		// Naming the offender matters here more than usual: {displayName} differs from the
		// real token by one capital letter, and an error that only listed the allowed
		// tokens would leave the admin comparing two near-identical strings by eye.
		$this->assertStringContainsString('{displayName}', $response->getData()['error']);
		$this->assertStringContainsString('{displayname}', $response->getData()['error']);
	}

	private function save(string $textTemplate) {
		return $this->controller->saveConfig(
			type: 'text',
			textTemplate: $textTemplate,
			imagePath: null,
			mimeTypes: null,
			folderTag: null,
		);
	}
}
