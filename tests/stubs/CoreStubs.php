<?php

declare(strict_types=1);

/**
 * Test-only stubs for Nextcloud *server* classes that lib/Dav/ depends on.
 *
 * Sabre itself is NOT stubbed here - sabre/dav is a real require-dev dependency, so
 * `Sabre\DAV\{Server, ServerPlugin, Tree, PropFind, INode}`, `Sabre\HTTP\{Request,
 * Response}` and the exception hierarchy are the genuine articles under test. Only
 * classes that live in the Nextcloud server tree (and are therefore not installable
 * from packagist) are reproduced below.
 *
 * Like the OCP stubs in bootstrap.php these are deliberately kept OUT of composer's
 * autoload(-dev) so they can never shadow the real classes at Nextcloud runtime.
 *
 * ---------------------------------------------------------------------------
 * FIDELITY - re-check on every Nextcloud upgrade.
 *
 * Hand-written stubs can drift from the real classes and turn a green test into a
 * false negative. The signatures below were transcribed verbatim from Nextcloud
 * 32.0.14. To re-verify them against a newer image:
 *
 *   CID=$(docker create nextcloud:32-apache)
 *   docker cp "$CID:/usr/src/nextcloud/apps/dav/lib/Connector/Sabre" ./ncsabre
 *   docker cp "$CID:/usr/src/nextcloud/lib/private/Streamer.php" ./Streamer.php
 *   docker cp "$CID:/usr/src/nextcloud/apps/dav/lib/Events/SabrePluginAddEvent.php" .
 *   docker cp "$CID:/usr/src/nextcloud/apps/files/lib/Event/LoadAdditionalScriptsEvent.php" .
 *   docker rm "$CID"
 *
 * then diff the declarations below against Node.php / File.php / Directory.php /
 * Streamer.php / the two events. Verified against: Nextcloud 32.0.14.
 *
 * Psalm reads this file too (see the <stubs> section of psalm.xml), which is what
 * type-checks lib/ against the signatures below instead of against nothing.
 * ---------------------------------------------------------------------------
 */

namespace OCA\DAV\Connector\Sabre {

	use OCP\IL10N;
	use OCP\IRequest;

	if (!class_exists(Node::class)) {
		/** Mirrors `abstract class Node implements \Sabre\DAV\INode` (Node.php:26). */
		abstract class Node implements \Sabre\DAV\INode {
			/** Node.php:219 - untyped in core. */
			abstract public function getId();

			/** Node.php:391 */
			abstract public function getNode(): \OCP\Files\Node;
		}
	}

	if (!class_exists(File::class)) {
		/** Mirrors `class File extends Node implements IFile` (File.php:48). */
		abstract class File extends Node implements \Sabre\DAV\IFile {
			/** File.php:627 - narrows Node::getNode(). */
			abstract public function getNode(): \OCP\Files\File;
		}
	}

	if (!class_exists(Directory::class)) {
		/**
		 * Mirrors `class Directory extends Node implements ICollection, IQuota,
		 * IMoveTarget, ICopyTarget` (Directory.php:39).
		 *
		 * Only ICollection is reproduced: the quota / move / copy interfaces are
		 * never touched by lib/Dav/, and omitting them cannot mask a failure - the
		 * plugins only ever `instanceof` this class and call the two methods below.
		 */
		abstract class Directory extends Node implements \Sabre\DAV\ICollection {
			/** Directory.php:173 - note the three optional args core adds to ICollection's. */
			abstract public function getChild($name, $info = null, ?IRequest $request = null, ?IL10N $l10n = null);

			/** Directory.php:469 - narrows Node::getNode(). */
			abstract public function getNode(): \OCP\Files\Folder;
		}
	}
}

namespace OCA\Files_Trashbin\Sabre {

	if (!interface_exists(ITrash::class)) {
		/**
		 * Mirrors `interface ITrash` (files_trashbin/lib/Sabre/ITrash.php:14).
		 *
		 * Only the two members `DownloadInterceptorPlugin` touches are reproduced - the node
		 * is never constructed here, only recognised and asked for its file id. The rest of
		 * the interface (restore, deletion time, deleted-by) belongs to the trash UI.
		 *
		 * Stubbed rather than depended on because `files_trashbin` is an app, not a library:
		 * it can be disabled, in which case the `instanceof` in that plugin simply never
		 * matches and the trash branch never runs.
		 */
		interface ITrash {
			public function getFileId(): int;

			public function getFilename(): string;
		}
	}
}

namespace OCA\Files_Versions\Versions {

	if (!interface_exists(IVersion::class)) {
		/**
		 * Mirrors `interface IVersion` (files_versions/lib/Versions/IVersion.php).
		 *
		 * `getBackend()` is left out: nothing in lib/ asks a version for its backend, and
		 * reproducing it would drag `IVersionBackend` in with it for no assertion's sake.
		 */
		interface IVersion {
			public function getSourceFile(): \OCP\Files\FileInfo;

			/** @return int|string */
			public function getRevisionId();

			public function getTimestamp(): int;

			public function getSize(): int|float;

			public function getSourceFileName(): string;

			public function getMimeType(): string;

			public function getVersionPath(): string;

			public function getUser(): \OCP\IUser;
		}
	}
}

namespace OCA\Files_Versions\Sabre {

	if (!class_exists(VersionFile::class)) {
		/**
		 * Mirrors `class VersionFile implements IFile, IPropertySource`
		 * (files_versions/lib/Sabre/VersionFile.php).
		 *
		 * Abstract here, like the DAV node stubs above: the plugin only recognises the node
		 * and asks it for its version, its bytes, its type, its size and its etag - all of
		 * which a mock answers. `files_versions` is an app and can be disabled, in which case
		 * the `instanceof` in `DownloadInterceptorPlugin` simply never matches.
		 */
		abstract class VersionFile implements \Sabre\DAV\IFile {
			/** VersionFile.php:83 */
			abstract public function getVersion(): \OCA\Files_Versions\Versions\IVersion;
		}
	}
}

namespace OCA\Files_Versions\Controller {

	if (!class_exists(PreviewController::class)) {
		/**
		 * Stands in for `class PreviewController extends Controller`
		 * (files_versions/lib/Controller/PreviewController.php).
		 *
		 * `WatermarkPreviewMiddleware` recognises it by name, before it runs, to note which
		 * file a version preview belongs to. Nothing else about it is touched, so nothing
		 * else is reproduced.
		 */
		abstract class PreviewController extends \OCP\AppFramework\Controller {
		}
	}
}

namespace OCA\Text\Controller {

	if (!interface_exists(ISessionAwareController::class)) {
		/**
		 * Mirrors `interface ISessionAwareController` (text/lib/Controller/
		 * ISessionAwareController.php). Only the two getters `TextAttachmentMiddleware` asks
		 * are reproduced: Text's own middleware has set both by the time the controller has
		 * answered, and they are where Text itself reads the document and the reader from.
		 */
		interface ISessionAwareController {
			/** ISessionAwareController.php:16 */
			public function getDocumentId(): int;

			/** ISessionAwareController.php:20 */
			public function getUserId(): string;
		}
	}

	if (!class_exists(AttachmentController::class)) {
		/**
		 * Stands in for `class AttachmentController extends ApiController implements
		 * ISessionAwareController` (text/lib/Controller/AttachmentController.php). Recognised
		 * by name after it has answered; `text` is an app and can be disabled, in which case
		 * the `instanceof` simply never matches.
		 */
		abstract class AttachmentController extends \OCP\AppFramework\ApiController implements ISessionAwareController {
		}
	}

	if (!class_exists(SessionController::class)) {
		/**
		 * Stands in for `class SessionController extends ApiController implements
		 * ISessionAwareController` (text/lib/Controller/SessionController.php). Recognised by
		 * name after it has answered, by `TextSessionMiddleware`.
		 */
		abstract class SessionController extends \OCP\AppFramework\ApiController implements ISessionAwareController {
		}
	}

	if (!class_exists(PublicSessionController::class)) {
		/**
		 * Stands in for `class PublicSessionController extends PublicShareController
		 * implements ISessionAwareController` (text/lib/Controller/PublicSessionController.php)
		 * - the same session API, authenticated by a share token.
		 */
		abstract class PublicSessionController extends \OCP\AppFramework\PublicShareController implements ISessionAwareController {
		}
	}
}

namespace OCA\Text\Service {

	if (!class_exists(WorkspaceService::class)) {
		/**
		 * Mirrors `class WorkspaceService` (text/lib/Service/WorkspaceService.php). Only the
		 * lookup is reproduced: it is how Text's `WorkspacePlugin` picks a folder's workspace
		 * file, and `TextWorkspacePlugin` asks it the same question.
		 */
		abstract class WorkspaceService {
			/** WorkspaceService.php:34 */
			abstract public function getFile(\OCP\Files\Folder $folder): ?\OCP\Files\File;
		}
	}
}

namespace OCA\Photos\Sabre {

	if (!class_exists(CollectionPhoto::class)) {
		/**
		 * Mirrors `class CollectionPhoto` (photos/lib/Sabre/CollectionPhoto.php).
		 *
		 * The base of every photo a Photos collection serves over DAV. Only the file id is
		 * reproduced - it is the one member `DownloadInterceptorPlugin` asks of the base.
		 * `photos` is an app and can be disabled, in which case the `instanceof` tests on
		 * its subclasses simply never match.
		 */
		abstract class CollectionPhoto {
			/** CollectionPhoto.php:55 */
			abstract public function getFileId(): int;
		}
	}
}

namespace OCA\Photos\Sabre\Album {

	if (!class_exists(AlbumPhoto::class)) {
		/**
		 * Mirrors `class AlbumPhoto extends CollectionPhoto implements IFile`
		 * (photos/lib/Sabre/Album/AlbumPhoto.php). Serves `/remote.php/dav/photos/{uid}/
		 * albums/...` and `sharedalbums/...` with `get()` = `fopen('r')` on the owner's node.
		 */
		abstract class AlbumPhoto extends \OCA\Photos\Sabre\CollectionPhoto implements \Sabre\DAV\IFile {
			/** AlbumPhoto.php:61 - the node resolved through the album owner's folder. */
			abstract public function getFileInfo(): \OCP\Files\Node;
		}
	}

	if (!class_exists(PublicAlbumPhoto::class)) {
		/**
		 * Mirrors `class PublicAlbumPhoto extends AlbumPhoto implements IFile`
		 * (photos/lib/Sabre/Album/PublicAlbumPhoto.php) - `/remote.php/dav/photospublic/
		 * {token}/...`, a public album link served from the authenticated DAV server.
		 */
		abstract class PublicAlbumPhoto extends AlbumPhoto {
		}
	}
}

namespace OCA\Photos\Sabre\Place {

	if (!class_exists(PlacePhoto::class)) {
		/**
		 * Mirrors `class PlacePhoto extends CollectionPhoto implements IFile`
		 * (photos/lib/Sabre/Place/PlacePhoto.php).
		 */
		abstract class PlacePhoto extends \OCA\Photos\Sabre\CollectionPhoto implements \Sabre\DAV\IFile {
			/** PlacePhoto.php:60 */
			abstract public function getFileInfo(): \OCP\Files\Node;
		}
	}
}

namespace OC {

	use OCP\IDateTimeZone;
	use OCP\IRequest;

	if (!class_exists(Streamer::class)) {
		/**
		 * Mirrors `OC\Streamer` (lib/private/Streamer.php).
		 *
		 * ZipInterceptorPlugin constructs this directly (`new Streamer(...)`) rather
		 * than taking it as a dependency, so it cannot be injected as a mock. This
		 * stub therefore records every call into a static log that tests read back -
		 * which is what makes the archive's *shape* (member set, names, sizes)
		 * assertable at all.
		 */
		class Streamer {
			/** @var list<array{0: string, 1: mixed}> ordered log of calls */
			public static array $log = [];

			/** @var list<array{preferTar: bool, size: int|float, numberOfFiles: int}> */
			public static array $constructed = [];

			public static function reset(): void {
				self::$log = [];
				self::$constructed = [];
			}

			/**
			 * Every member written via addFileFromStream, keyed by internal name.
			 *
			 * @return array<string, array{size: int|float, contents: string}>
			 */
			public static function members(): array {
				$members = [];
				foreach (self::$log as [$call, $args]) {
					if ($call === 'addFileFromStream') {
						$members[$args['internalName']] = [
							'size' => $args['size'],
							'contents' => $args['contents'],
						];
					}
				}
				return $members;
			}

			/** @return list<string> every directory entry added, in order */
			public static function dirs(): array {
				$dirs = [];
				foreach (self::$log as [$call, $args]) {
					if ($call === 'addEmptyDir') {
						$dirs[] = $args['dirName'];
					}
				}
				return $dirs;
			}

			public function __construct(
				IRequest|bool $preferTar,
				int|float $size,
				int $numberOfFiles,
				private IDateTimeZone $timezoneFactory,
			) {
				self::$constructed[] = [
					'preferTar' => $preferTar,
					'size' => $size,
					'numberOfFiles' => $numberOfFiles,
				];
			}

			public function sendHeaders($name) {
				self::$log[] = ['sendHeaders', ['name' => $name]];
			}

			public function addFileFromStream($stream, string $internalName, int|float $size, $time): bool {
				// Drain the stream so a test can assert *which* bytes were archived -
				// the whole point of the watermark substitution.
				$contents = is_resource($stream) ? (string)stream_get_contents($stream) : '';
				if (is_resource($stream)) {
					fclose($stream);
				}
				self::$log[] = ['addFileFromStream', [
					'internalName' => $internalName,
					'size' => $size,
					'time' => $time,
					'contents' => $contents,
				]];
				return true;
			}

			public function addEmptyDir(string $dirName, int $timestamp = 0): bool {
				self::$log[] = ['addEmptyDir', ['dirName' => $dirName, 'timestamp' => $timestamp]];
				return true;
			}

			public function finalize() {
				self::$log[] = ['finalize', []];
			}
		}
	}
}

namespace OCA\DAV\Events {

	if (!class_exists(SabrePluginAddEvent::class)) {
		/**
		 * Mirrors `class SabrePluginAddEvent extends Event`
		 * (apps/dav/lib/Events/SabrePluginAddEvent.php:20).
		 *
		 * Announced while the authenticated Files WebDAV server is being built;
		 * {@see \OCA\FilesWatermark\EventListener\SabrePluginAddListener} answers it.
		 * The public-link server announces OCP\BeforeSabrePubliclyLoadedEvent instead,
		 * which is a real OCP class and needs no stub.
		 */
		class SabrePluginAddEvent extends \OCP\EventDispatcher\Event {
			/** SabrePluginAddEvent.php:25 */
			public function __construct(
				private \Sabre\DAV\Server $server,
			) {
				parent::__construct();
			}

			/** SabrePluginAddEvent.php:34 - not nullable here, unlike SabrePluginEvent's. */
			public function getServer(): \Sabre\DAV\Server {
				return $this->server;
			}
		}
	}

	if (!class_exists(BeforeFileDirectDownloadedEvent::class)) {
		/**
		 * Mirrors `class BeforeFileDirectDownloadedEvent extends Event`
		 * (apps/dav/lib/Events/BeforeFileDirectDownloadedEvent.php).
		 *
		 * Dispatched by `OCA\DAV\Direct\DirectFile::get()` - the `/remote.php/direct/{token}`
		 * server - immediately before it opens the stored file. It carries no veto flag; a
		 * listener refuses the download by throwing, which `DirectFile::get()` lets through to
		 * Sabre as the response.
		 */
		class BeforeFileDirectDownloadedEvent extends \OCP\EventDispatcher\Event {
			public function __construct(
				private \OCP\Files\File $file,
			) {
				parent::__construct();
			}

			public function getFile(): \OCP\Files\File {
				return $this->file;
			}
		}
	}
}

namespace OCA\Files\Event {

	if (!class_exists(LoadAdditionalScriptsEvent::class)) {
		/**
		 * Mirrors `class LoadAdditionalScriptsEvent extends Event`
		 * (apps/files/lib/Event/LoadAdditionalScriptsEvent.php:18).
		 *
		 * Empty in core: it carries no payload, and the body below is empty for the
		 * same reason rather than by omission.
		 */
		class LoadAdditionalScriptsEvent extends \OCP\EventDispatcher\Event {
		}
	}
}
