<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Db;

use OCA\FilesWatermark\Db\WatermarkConfig;
use PHPUnit\Framework\TestCase;

/**
 * The entity itself: what it serialises to the settings page, and how it reads the one
 * column that holds a list. The mapper around it is {@see WatermarkConfigMapperTest} - this
 * file used to carry that name while testing none of it.
 */
class WatermarkConfigTest extends TestCase {

	public function testJsonSerializeIncludesAllFields(): void {
		$config = new WatermarkConfig();
		$config->setType('combined');
		$config->setTextTemplate('{username}');
		$config->setImagePath('/path/to/logo.png');
		$config->setOpacity(75);
		$config->setFontSize(18);
		$config->setColor('#ff0000');
		$config->setRotation(30);
		$config->setTrigger('on_download');
		$config->setMimeTypes('application/pdf,image/jpeg');
		$config->setFolderTag('42');
		$config->setCreatedAt('2026-06-25 00:00:00');
		$config->setUpdatedAt('2026-06-25 00:00:00');

		$data = $config->jsonSerialize();

		$this->assertSame('combined', $data['type']);
		$this->assertSame(75, $data['opacity']);
		$this->assertSame('#ff0000', $data['color']);
		$this->assertSame('application/pdf,image/jpeg', $data['mimeTypes']);
		$this->assertSame('42', $data['folderTag']);
	}

	/**
	 * The share switches default to off, and the settings page has to be told so.
	 *
	 * A field the entity omits reads as `undefined` in the form, which the checkbox renders
	 * unticked and then posts back as `false` - the right answer by accident. This pins the
	 * two that would be silently wrong the day the default changes.
	 */
	public function testTheShareSwitchesSerialiseAndDefaultToOff(): void {
		$data = (new WatermarkConfig())->jsonSerialize();

		$this->assertFalse($data['watermarkInternalShares']);
		$this->assertFalse($data['watermarkExternalShares']);
	}

	public function testTheShareSwitchesSerialiseWhatWasSet(): void {
		$config = new WatermarkConfig();
		$config->setWatermarkInternalShares(true);
		$config->setWatermarkExternalShares(false);

		$data = $config->jsonSerialize();

		$this->assertTrue($data['watermarkInternalShares']);
		$this->assertFalse($data['watermarkExternalShares']);
	}

	public function testGetAllowedMimeTypesReturnsArrayFromCsvString(): void {
		$config = new WatermarkConfig();
		$config->setMimeTypes('application/pdf, image/jpeg, image/png');

		$types = $config->getAllowedMimeTypes();

		$this->assertCount(3, $types);
		$this->assertContains('application/pdf', $types);
		$this->assertContains('image/jpeg', $types);
		$this->assertContains('image/png', $types);
	}

	public function testGetAllowedMimeTypesReturnsEmptyArrayWhenNull(): void {
		$config = new WatermarkConfig();
		$config->setMimeTypes(null);

		$this->assertSame([], $config->getAllowedMimeTypes());
	}

	public function testGetAllowedMimeTypesReturnsEmptyArrayForBlankString(): void {
		$config = new WatermarkConfig();
		$config->setMimeTypes('   ');

		$this->assertSame([], $config->getAllowedMimeTypes());
	}

	/**
	 * The flattening pair has to be *in* the payload, defaults included.
	 *
	 * A field the entity omits reads as `undefined` in the form. For the switch that is the
	 * right answer by accident; for the DPI it is not - `undefined` binds to a range input
	 * as an empty value, and the slider renders at its minimum rather than at the 150 the
	 * server would actually use.
	 */
	public function testJsonSerializeCarriesTheFlatteningDefaults(): void {
		$data = (new WatermarkConfig())->jsonSerialize();

		$this->assertArrayHasKey('flattenPdf', $data);
		$this->assertArrayHasKey('flattenDpi', $data);
		$this->assertFalse($data['flattenPdf'], 'flattening must be off until an admin asks');
		$this->assertSame(150, $data['flattenDpi']);
	}

	public function testJsonSerializeCarriesTheFlatteningPairAsSet(): void {
		$config = new WatermarkConfig();
		$config->setFlattenPdf(true);
		$config->setFlattenDpi(300);

		$data = $config->jsonSerialize();

		$this->assertTrue($data['flattenPdf']);
		$this->assertSame(300, $data['flattenDpi']);
	}
}
