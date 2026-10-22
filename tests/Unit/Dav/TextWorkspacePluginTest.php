<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Dav;

use OCA\DAV\Connector\Sabre\Directory as DavDirectory;
use OCA\DAV\Connector\Sabre\File as DavFile;
use OCA\FilesWatermark\Dav\TextWorkspacePlugin;
use OCA\FilesWatermark\Service\WatermarkService;
use OCA\Text\Service\WorkspaceService;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Sabre\DAV\INode;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;

/**
 * Text answers a folder's `rich-workspace` property with its `Readme.md`'s stored content,
 * chosen by name - so a marked file renamed to `Readme.md` came back clean in a PROPFIND.
 * These pin the blanking of that answer.
 *
 * @covers \OCA\FilesWatermark\Dav\TextWorkspacePlugin
 */
class TextWorkspacePluginTest extends TestCase {

	private const PROP = TextWorkspacePlugin::WORKSPACE_PROPERTY;

	private WatermarkService&MockObject $watermarkService;
	private WorkspaceService&MockObject $workspaces;
	private Folder&MockObject $folder;
	private File&MockObject $readme;

	protected function setUp(): void {
		parent::setUp();
		$this->watermarkService = $this->createMock(WatermarkService::class);
		$this->folder = $this->createMock(Folder::class);
		$this->readme = $this->createMock(File::class);
		$this->workspaces = $this->createMock(WorkspaceService::class);
		$this->workspaces->method('getFile')->with($this->folder)->willReturn($this->readme);
	}

	private function plugin(): TextWorkspacePlugin {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(WorkspaceService::class)->willReturn($this->workspaces);

		return new TextWorkspacePlugin($this->watermarkService, $container, new NullLogger());
	}

	private function directory(): DavDirectory&MockObject {
		$node = $this->createMock(DavDirectory::class);
		$node->method('getNode')->willReturn($this->folder);
		return $node;
	}

	/**
	 * A PROPFIND for the property, answered first by a listener that does what Text's does -
	 * `handle()` at the default priority - and then by whatever the plugin registered.
	 */
	private function propFind(INode $node, string $textsAnswer = 'CLEAN-ORIGINAL'): PropFind {
		$server = new Server();
		$server->on('propFind', static function (PropFind $propFind) use ($textsAnswer): void {
			$propFind->handle(self::PROP, $textsAnswer);
		});
		$this->plugin()->initialize($server);

		$propFind = new PropFind('files/alice/Docs', [self::PROP]);
		$server->emit('propFind', [$propFind, $node]);

		return $propFind;
	}

	public function testAProtectedWorkspaceIsBlanked(): void {
		$this->watermarkService->method('isDeliveryCandidate')->with($this->readme)->willReturn(true);

		$propFind = $this->propFind($this->directory());

		$this->assertSame('', $propFind->get(self::PROP));
		$this->assertSame(200, $propFind->getStatus(self::PROP));
	}

	public function testAnUnprotectedWorkspaceKeepsTextsAnswer(): void {
		$this->watermarkService->method('isDeliveryCandidate')->willReturn(false);

		$this->assertSame('CLEAN-ORIGINAL', $this->propFind($this->directory())->get(self::PROP));
	}

	/** A folder with no workspace, or a PROPFIND that does not ask: nothing to look into. */
	public function testAnEmptyOrMissingAnswerIsLeftAlone(): void {
		$this->watermarkService->expects($this->never())->method('isDeliveryCandidate');

		$this->assertSame('', $this->propFind($this->directory(), '')->get(self::PROP));

		$server = new Server();
		$this->plugin()->initialize($server);
		$propFind = new PropFind('files/alice/Docs', ['{DAV:}getetag']);
		$server->emit('propFind', [$propFind, $this->directory()]);
		$this->assertNull($propFind->getStatus(self::PROP));
	}

	/** Text only answers it on a folder. */
	public function testAFileIsLeftAlone(): void {
		$this->watermarkService->expects($this->never())->method('isDeliveryCandidate');

		$this->assertSame('CLEAN-ORIGINAL', $this->propFind($this->createMock(DavFile::class))->get(self::PROP));
	}

	/** Content went out for a workspace this cannot find: whether it was protected is unknown. */
	public function testAWorkspaceThisCannotPlaceIsBlanked(): void {
		$this->workspaces = $this->createMock(WorkspaceService::class);
		$this->workspaces->method('getFile')->willReturn(null);
		$this->watermarkService->expects($this->never())->method('isDeliveryCandidate');

		$this->assertSame('', $this->propFind($this->directory())->get(self::PROP));
	}

	public function testACheckThatFailsBlanks(): void {
		$this->watermarkService->method('isDeliveryCandidate')->willThrowException(new \RuntimeException('database'));

		$this->assertSame('', $this->propFind($this->directory())->get(self::PROP));
	}
}
