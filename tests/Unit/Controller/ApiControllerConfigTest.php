<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Controller;

use OCA\FilesWatermark\Controller\ApiController;
use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Db\WatermarkConfigMapper;
use OCA\FilesWatermark\Db\WatermarkLogMapper;
use OCA\FilesWatermark\Service\ApplyLimits;
use OCA\FilesWatermark\Service\WatermarkImageStore;
use OCA\FilesWatermark\Service\WatermarkService;
use OCA\FilesWatermark\Tests\Unit\L10nMock;
use OCP\AppFramework\Db\DoesNotExistException;
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
 * Reading, replacing and discarding the global policy.
 *
 * The scope fields have their own file ({@see ApiControllerScopeTest}), and so do the image
 * ({@see ApiControllerImageTest}) and the template tokens ({@see ApiControllerTokenTest}).
 * What is left is the part every save goes through whatever it is saving: the admin gate,
 * the three enum-ish fields, the numeric clamps, and the choice between inserting a config
 * and updating the one that already exists.
 *
 * The clamps matter more than they look. `opacity`, `fontSize` and `rotation` arrive as
 * plain ints from a JSON body, and nothing between the browser and the renderer refuses a
 * negative font size - the setters store what they are handed. `max()`/`min()` here is the
 * only bound there is.
 */
class ApiControllerConfigTest extends TestCase {

	use L10nMock;

	private WatermarkConfigMapper&MockObject $configMapper;
	private ApiController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->configMapper = $this->createMock(WatermarkConfigMapper::class);

		$this->configMapper->method('insert')->willReturnCallback(
			static fn (WatermarkConfig $config): WatermarkConfig => $config,
		);
		$this->configMapper->method('update')->willReturnCallback(
			static fn (WatermarkConfig $config): WatermarkConfig => $config,
		);

		$this->controller = $this->controllerFor('admin', true);
	}

	/**
	 * A controller whose session holds `$uid` (null for an anonymous request) and whose
	 * group manager answers `$isAdmin`. Built per test rather than reconfigured, because a
	 * PHPUnit mock cannot have the same method stubbed twice.
	 */
	private function controllerFor(?string $uid, bool $isAdmin): ApiController {
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
			$this->configMapper,
			$this->createMock(WatermarkLogMapper::class),
			$this->createMock(WatermarkService::class),
			$this->createMock(IRootFolder::class),
			$session,
			$groupManager,
			$this->createMock(WatermarkImageStore::class),
			$this->createMock(ISystemTagManager::class),
			$this->createMock(ApplyLimits::class),
			$this->l10n(),
		);
	}

	/** A saved policy, as the mapper would hand one back. */
	private function storedConfig(int $id = 7): WatermarkConfig {
		$config = new WatermarkConfig();
		$config->setId($id);
		$config->setType('text');
		$config->setTextTemplate('{displayname}');
		$config->setTrigger('on_download');
		$config->setCreatedAt('2026-01-01 00:00:00');
		return $config;
	}

	// getConfig ---------------------------------------------------------------------------

	public function testGetConfigReturnsTheStoredPolicy(): void {
		$this->configMapper->method('findGlobal')->willReturn($this->storedConfig());

		$response = $this->controller->getConfig();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		// A list, not a bare object: AdminSettings.vue reads configs[0], and the shape
		// predates there being exactly one config.
		$this->assertCount(1, $response->getData()['configs']);
		$this->assertSame(7, $response->getData()['configs'][0]['id']);
		$this->assertSame('on_download', $response->getData()['configs'][0]['trigger']);
	}

	/**
	 * A fresh install has no row. The settings page has to open on it, so this is an empty
	 * list and HTTP 200 - not a 404, which the form would have to special-case.
	 */
	public function testGetConfigReturnsAnEmptyListWhenNoneIsSaved(): void {
		$this->configMapper->method('findGlobal')->willThrowException(new DoesNotExistException(''));

		$response = $this->controller->getConfig();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData()['configs']);
	}

	/** @dataProvider nonAdminProvider */
	public function testGetConfigIsForbiddenWithoutAdmin(?string $uid, bool $isAdmin): void {
		// Not read at all, so a non-admin cannot even learn whether a policy exists.
		$this->configMapper->expects($this->never())->method('findGlobal');

		$response = $this->controllerFor($uid, $isAdmin)->getConfig();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	/** @return array<string, array{?string, bool}> */
	public static function nonAdminProvider(): array {
		return [
			'signed in, not an admin' => ['bob', false],
			'anonymous' => [null, false],
		];
	}

	// saveConfig: the fields every save goes through ---------------------------------------

	public function testUnknownTypeIsRejectedAndNamed(): void {
		$this->configMapper->expects($this->never())->method('insert');

		$response = $this->controller->saveConfig(type: 'metadata', textTemplate: '{username}', imagePath: null);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		// `metadata` is in the SDD but not implemented; the message has to say what is.
		$this->assertStringContainsString('metadata', $response->getData()['error']);
		$this->assertStringContainsString('text, image, combined', $response->getData()['error']);
	}

	public function testUnknownTriggerIsRejectedAndNamed(): void {
		$this->configMapper->expects($this->never())->method('insert');

		$response = $this->controller->saveConfig(
			type: 'text',
			textTemplate: '{username}',
			imagePath: null,
			trigger: 'on_delete',
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('on_delete', $response->getData()['error']);
		$this->assertStringContainsString('on_demand', $response->getData()['error']);
	}

	/** @dataProvider badColorProvider */
	public function testMalformedColorIsRejected(string $color): void {
		$this->configMapper->expects($this->never())->method('insert');

		$response = $this->controller->saveConfig(
			type: 'text',
			textTemplate: '{username}',
			imagePath: null,
			color: $color,
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		if ($color !== '') {
			$this->assertStringContainsString($color, $response->getData()['error']);
		}
	}

	/**
	 * Everything a colour field or a hand-written body can produce that the renderers cannot
	 * draw with. The three-digit CSS form is the one worth naming: it is valid CSS, and
	 * `hexToRgb()` would read `#888` as one channel and two empty ones.
	 *
	 * @return array<string, array{string}>
	 */
	public static function badColorProvider(): array {
		return [
			'no hash' => ['808080'],
			'three-digit CSS shorthand' => ['#888'],
			'not hex' => ['#gggggg'],
			'named colour' => ['grey'],
			'too long' => ['#8080808'],
			'empty' => [''],
		];
	}

	public function testUppercaseHexIsAccepted(): void {
		$response = $this->controller->saveConfig(
			type: 'text',
			textTemplate: '{username}',
			imagePath: null,
			color: '#AABBCC',
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		// Stored as given: hexToRgb() is case-blind, so normalising here would only make the
		// value the admin sees differ from the one they typed.
		$this->assertSame('#AABBCC', $response->getData()['color']);
	}

	/**
	 * @dataProvider clampProvider
	 *
	 * @param array<string, int> $sent
	 * @param array<string, int> $expected
	 */
	public function testNumericFieldsAreClampedToWhatTheRenderersCanDraw(array $sent, array $expected): void {
		$response = $this->controller->saveConfig(
			type: 'text',
			textTemplate: '{username}',
			imagePath: null,
			opacity: $sent['opacity'] ?? 40,
			fontSize: $sent['fontSize'] ?? 24,
			rotation: $sent['rotation'] ?? 45,
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		foreach ($expected as $field => $value) {
			$this->assertSame($value, $response->getData()[$field], "$field was not clamped");
		}
	}

	/**
	 * The bounds are the renderers' own: opacity is a percentage, a font under 6pt is
	 * unreadable and one over 120pt tiles to a single glyph, and rotation past ±180° is the
	 * same angle coming back round.
	 *
	 * @return array<string, array{array<string, int>, array<string, int>}>
	 */
	public static function clampProvider(): array {
		return [
			'negative opacity' => [['opacity' => -20], ['opacity' => 0]],
			'opacity over 100' => [['opacity' => 400], ['opacity' => 100]],
			'opacity in range' => [['opacity' => 55], ['opacity' => 55]],
			'font size zero' => [['fontSize' => 0], ['fontSize' => 6]],
			'font size huge' => [['fontSize' => 5000], ['fontSize' => 120]],
			'rotation past half turn' => [['rotation' => 900], ['rotation' => 180]],
			'rotation past negative half turn' => [['rotation' => -900], ['rotation' => -180]],
			'rotation in range' => [['rotation' => -45], ['rotation' => -45]],
		];
	}

	public function testDeliveryLoggingIsOffUnlessAskedFor(): void {
		$response = $this->controller->saveConfig(type: 'text', textTemplate: '{username}', imagePath: null);

		$this->assertFalse($response->getData()['logDelivery']);
	}

	public function testDeliveryLoggingIsStoredWhenEnabled(): void {
		$response = $this->controller->saveConfig(
			type: 'text',
			textTemplate: '{username}',
			imagePath: null,
			logDelivery: true,
		);

		$this->assertTrue($response->getData()['logDelivery']);
	}

	/** @dataProvider nonAdminProvider */
	public function testSaveIsForbiddenWithoutAdmin(?string $uid, bool $isAdmin): void {
		$this->configMapper->expects($this->never())->method('insert');

		// Refused before validation, so the complaint that comes back cannot be read as a
		// hint about what the policy accepts.
		$response = $this->controllerFor($uid, $isAdmin)->saveConfig(
			type: 'nonsense',
			textTemplate: '{nope}',
			imagePath: null,
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	/**
	 * Saving with an id edits that row rather than adding a second one. There is exactly one
	 * global policy, and an insert here would leave `findGlobal()` choosing between two.
	 */
	public function testSavingWithAnIdUpdatesTheExistingConfig(): void {
		$this->configMapper->method('findById')->with(7)->willReturn($this->storedConfig());
		$this->configMapper->expects($this->once())->method('update');
		$this->configMapper->expects($this->never())->method('insert');

		$response = $this->controller->saveConfig(
			type: 'combined',
			textTemplate: '{username}',
			imagePath: null,
			id: 7,
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(7, $response->getData()['id']);
		$this->assertSame('combined', $response->getData()['type']);
		// The row's own history survives the edit; only updatedAt moves.
		$this->assertSame('2026-01-01 00:00:00', $response->getData()['createdAt']);
		$this->assertNotSame('', $response->getData()['updatedAt']);
	}

	public function testSavingWithAnUnknownIdIsNotFound(): void {
		$this->configMapper->method('findById')->willThrowException(new DoesNotExistException(''));
		$this->configMapper->expects($this->never())->method('update');
		$this->configMapper->expects($this->never())->method('insert');

		$response = $this->controller->saveConfig(
			type: 'text',
			textTemplate: '{username}',
			imagePath: null,
			id: 999,
		);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testSavingWithoutAnIdInsertsAndStampsCreatedAt(): void {
		$this->configMapper->expects($this->once())->method('insert');
		$this->configMapper->expects($this->never())->method('update');

		$response = $this->controller->saveConfig(type: 'text', textTemplate: '{username}', imagePath: null);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertNotSame('', $response->getData()['createdAt']);
	}

	// deleteConfig ------------------------------------------------------------------------

	public function testDeleteDiscardsTheConfig(): void {
		$stored = $this->storedConfig();
		$this->configMapper->method('findById')->with(7)->willReturn($stored);
		$this->configMapper->expects($this->once())->method('delete')->with($stored);

		$response = $this->controller->deleteConfig(7);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('deleted', $response->getData()['status']);
	}

	public function testDeleteIsNotFoundForAnUnknownId(): void {
		$this->configMapper->method('findById')->willThrowException(new DoesNotExistException(''));
		$this->configMapper->expects($this->never())->method('delete');

		$response = $this->controller->deleteConfig(999);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	/** @dataProvider nonAdminProvider */
	public function testDeleteIsForbiddenWithoutAdmin(?string $uid, bool $isAdmin): void {
		// Checked before the lookup, so the endpoint cannot be used to probe which ids exist.
		$this->configMapper->expects($this->never())->method('findById');
		$this->configMapper->expects($this->never())->method('delete');

		$response = $this->controllerFor($uid, $isAdmin)->deleteConfig(7);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}
}
