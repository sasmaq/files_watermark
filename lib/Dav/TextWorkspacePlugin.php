<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Dav;

use OCA\DAV\Connector\Sabre\Directory;
use OCA\FilesWatermark\Service\WatermarkService;
use OCA\Text\Service\WorkspaceService;
use OCP\Files\File;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Sabre\DAV\INode;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

/**
 * Blanks the Text app's rich-workspace property when the workspace file is protected.
 *
 * ---------------------------------------------------------------------------
 * THE WORKSPACE IS PICKED BY NAME, AND ITS PROPERTY IS THE FILE.
 *
 * Text answers `{http://nextcloud.org/ns}rich-workspace` on a folder's PROPFIND with the
 * stored content of the folder's `Readme.md` (or `README.md`, `readme.md`, `.Readme.md`, or
 * the translated name) - `getContent()`, unconverted, cached by id and etag. It chooses the
 * file by name alone, and a mark survives a rename by design: a marked PDF renamed to
 * `Readme.md` came back clean in the listing of its folder, with no GET for
 * {@see DownloadInterceptorPlugin} to see.
 * ---------------------------------------------------------------------------
 *
 * **After Text, and overwriting it** - Sabre runs `propFind` listeners in priority order,
 * Text's at the default 100. `set()` replaces whatever Text answered, and an answer Text did
 * not give, or gave empty, is left as it is.
 *
 * **The file is the one Text picked**, asked of Text's own `WorkspaceService` rather than a
 * copy of its list of names, which is translated. Resolved lazily: the service is only
 * needed once Text has put content in the property.
 *
 * **An answer this cannot place is blanked**, as everywhere else: content went out for a
 * file this cannot find.
 */
class TextWorkspacePlugin extends ServerPlugin {

	/** Text's `WorkspacePlugin::WORKSPACE_PROPERTY`. */
	public const WORKSPACE_PROPERTY = '{http://nextcloud.org/ns}rich-workspace';

	public function __construct(
		private WatermarkService $watermarkService,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}

	public function initialize(Server $server): void {
		$server->on('propFind', [$this, 'propFind'], 200);
	}

	public function propFind(PropFind $propFind, INode $node): void {
		if ($propFind->getStatus(self::WORKSPACE_PROPERTY) !== 200) {
			return;
		}

		$content = $propFind->get(self::WORKSPACE_PROPERTY);
		if (!is_string($content) || $content === '' || !($node instanceof Directory)) {
			return;
		}

		$file = $this->workspaceFile($node);
		if ($file === null) {
			$this->logger->warning('files_watermark: Text served a rich workspace this app could not resolve; blanking it rather than serving it unchecked');
			$propFind->set(self::WORKSPACE_PROPERTY, '');

			return;
		}

		try {
			$protected = $this->watermarkService->isDeliveryCandidate($file);
		} catch (\Throwable $e) {
			$this->logger->error('files_watermark: could not tell whether a rich workspace is protected; blanking it: ' . $e->getMessage(), [
				'exception' => $e,
			]);
			$protected = true;
		}

		if ($protected) {
			$propFind->set(self::WORKSPACE_PROPERTY, '');
		}
	}

	private function workspaceFile(Directory $node): ?File {
		try {
			/** @var WorkspaceService $workspaces */
			$workspaces = $this->container->get(WorkspaceService::class);

			return $workspaces->getFile($node->getNode());
		} catch (\Throwable) {
			return null;
		}
	}
}
