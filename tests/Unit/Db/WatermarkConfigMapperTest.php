<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Db;

use OCA\FilesWatermark\Db\WatermarkConfig;
use OCA\FilesWatermark\Db\WatermarkConfigMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class WatermarkConfigMapperTest extends TestCase {

    public function testJsonSerializeIncludesAllFields(): void {
        $config = new WatermarkConfig();
        $config->setType('combined');
        $config->setTextTemplate('{username}');
        $config->setImagePath('/path/to/logo.png');
        $config->setPosition('diagonal');
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
}
