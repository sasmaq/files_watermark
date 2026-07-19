<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Service\WatermarkImageStore;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WatermarkImageStoreTest extends TestCase {

    private IAppDataFactory&MockObject $appDataFactory;
    private ISimpleFolder&MockObject   $folder;
    private WatermarkImageStore        $store;

    /** @var string[] temp files created by a test */
    private array $tmpFiles = [];

    protected function setUp(): void {
        parent::setUp();

        $this->folder = $this->createMock(ISimpleFolder::class);
        $appData      = $this->createMock(IAppData::class);
        $appData->method('getFolder')->willReturn($this->folder);
        $appData->method('newFolder')->willReturn($this->folder);

        $this->appDataFactory = $this->createMock(IAppDataFactory::class);
        $this->appDataFactory->method('get')->with('files_watermark')->willReturn($appData);

        $this->store = new WatermarkImageStore(
            $this->appDataFactory,
            $this->createMock(LoggerInterface::class),
        );
    }

    protected function tearDown(): void {
        foreach ($this->tmpFiles as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    private function tmp(string $content): string {
        $path = sys_get_temp_dir() . '/wm_store_test_' . bin2hex(random_bytes(6));
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;
        return $path;
    }

    private function pngFile(int $w = 4, int $h = 4): string {
        $image = imagecreatetruecolor($w, $h);
        $path  = $this->tmp('');
        imagepng($image, $path);
        imagedestroy($image);
        return $path;
    }

    public function testIsReferenceAcceptsOnlyGeneratedNames(): void {
        $this->assertTrue(WatermarkImageStore::isReference(str_repeat('a', 32) . '.png'));
        $this->assertTrue(WatermarkImageStore::isReference(str_repeat('0', 32) . '.jpg'));

        // Anything a user could type — in particular the absolute server paths the old
        // free-text field accepted — must not look like a reference.
        $this->assertFalse(WatermarkImageStore::isReference('/etc/passwd'));
        $this->assertFalse(WatermarkImageStore::isReference('/var/www/html/core/img/logo.png'));
        $this->assertFalse(WatermarkImageStore::isReference('../../' . str_repeat('a', 32) . '.png'));
        $this->assertFalse(WatermarkImageStore::isReference(str_repeat('a', 32) . '.svg'));
        $this->assertFalse(WatermarkImageStore::isReference(str_repeat('z', 32) . '.png'));
        $this->assertFalse(WatermarkImageStore::isReference(''));
        $this->assertFalse(WatermarkImageStore::isReference(null));
    }

    public function testStoreWritesPngAndReturnsOpaqueReference(): void {
        $written = null;
        $this->folder->expects($this->once())
            ->method('newFile')
            ->willReturnCallback(function (string $name, $content) use (&$written): ISimpleFile {
                $written = ['name' => $name, 'content' => $content];
                return $this->createMock(ISimpleFile::class);
            });

        $reference = $this->store->store($this->pngFile());

        $this->assertTrue(WatermarkImageStore::isReference($reference));
        $this->assertSame($reference, $written['name']);
        $this->assertStringStartsWith("\x89PNG", $written['content']);
    }

    public function testStoreRejectsNonImageContentRegardlessOfName(): void {
        // The type comes from the bytes, not the upload's claimed name or MIME header.
        $this->folder->expects($this->never())->method('newFile');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PNG or JPEG');

        $this->store->store($this->tmp('<?php echo "not an image";'));
    }

    public function testStoreRejectsOversizedImage(): void {
        $this->folder->expects($this->never())->method('newFile');

        $oversized = $this->tmp(str_repeat('x', WatermarkImageStore::MAX_BYTES + 1));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('too large');

        $this->store->store($oversized);
    }

    public function testStoreRejectsEmptyUpload(): void {
        $this->expectException(\RuntimeException::class);
        $this->store->store($this->tmp(''));
    }

    public function testLocalPathRefusesToReadANonReference(): void {
        // The whole point of the change: a config still holding a legacy absolute path
        // must resolve to nothing rather than reading that file off the server.
        $this->folder->expects($this->never())->method('getFile');

        $this->assertNull($this->store->localPath('/var/www/html/core/img/logo/logo.png'));
        $this->assertNull($this->store->localPath('/etc/passwd'));
        $this->assertNull($this->store->localPath(null));
        $this->assertNull($this->store->localPath(''));
    }

    public function testLocalPathMaterialisesStoredContent(): void {
        $reference = str_repeat('a', 32) . '.png';

        $file = $this->createMock(ISimpleFile::class);
        $file->method('getContent')->willReturn('stored-bytes');
        $this->folder->method('getFile')->with($reference)->willReturn($file);

        $path = $this->store->localPath($reference);
        $this->tmpFiles[] = $path;

        $this->assertNotNull($path);
        $this->assertSame('stored-bytes', file_get_contents($path));
    }

    public function testLocalPathReturnsNullWhenTheImageIsGone(): void {
        $reference = str_repeat('b', 32) . '.png';
        $this->folder->method('getFile')->willThrowException(new NotFoundException());

        $this->assertNull($this->store->localPath($reference));
    }
}
