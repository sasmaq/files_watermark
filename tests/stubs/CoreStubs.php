<?php

declare(strict_types=1);

/**
 * Test-only stubs for Nextcloud *server* classes that lib/Dav/ depends on.
 *
 * Sabre itself is NOT stubbed here — sabre/dav is a real require-dev dependency, so
 * `Sabre\DAV\{Server, ServerPlugin, Tree, PropFind, INode}`, `Sabre\HTTP\{Request,
 * Response}` and the exception hierarchy are the genuine articles under test. Only
 * classes that live in the Nextcloud server tree (and are therefore not installable
 * from packagist) are reproduced below.
 *
 * Like the OCP stubs in bootstrap.php these are deliberately kept OUT of composer's
 * autoload(-dev) so they can never shadow the real classes at Nextcloud runtime.
 *
 * ---------------------------------------------------------------------------
 * FIDELITY — re-check on every Nextcloud upgrade.
 *
 * Hand-written stubs can drift from the real classes and turn a green test into a
 * false negative. The signatures below were transcribed verbatim from Nextcloud
 * 31.0.14. To re-verify them against a newer image:
 *
 *   CID=$(docker create nextcloud:31-apache)
 *   docker cp "$CID:/usr/src/nextcloud/apps/dav/lib/Connector/Sabre" ./ncsabre
 *   docker cp "$CID:/usr/src/nextcloud/lib/private/Streamer.php" ./Streamer.php
 *   docker rm "$CID"
 *
 * then diff the declarations below against Node.php / File.php / Directory.php /
 * Streamer.php. Verified against: Nextcloud 31.0.14.
 * ---------------------------------------------------------------------------
 */

namespace OCA\DAV\Connector\Sabre {

	use OCP\IL10N;
	use OCP\IRequest;

	if (!class_exists(Node::class)) {
		/** Mirrors `abstract class Node implements \Sabre\DAV\INode` (Node.php:26). */
		abstract class Node implements \Sabre\DAV\INode {
			/** Node.php:219 — untyped in core. */
			abstract public function getId();

			/** Node.php:391 */
			abstract public function getNode(): \OCP\Files\Node;
		}
	}

	if (!class_exists(File::class)) {
		/** Mirrors `class File extends Node implements IFile` (File.php:48). */
		abstract class File extends Node implements \Sabre\DAV\IFile {
			/** File.php:627 — narrows Node::getNode(). */
			abstract public function getNode(): \OCP\Files\File;
		}
	}

	if (!class_exists(Directory::class)) {
		/**
		 * Mirrors `class Directory extends Node implements ICollection, IQuota,
		 * IMoveTarget, ICopyTarget` (Directory.php:39).
		 *
		 * Only ICollection is reproduced: the quota / move / copy interfaces are
		 * never touched by lib/Dav/, and omitting them cannot mask a failure — the
		 * plugins only ever `instanceof` this class and call the two methods below.
		 */
		abstract class Directory extends Node implements \Sabre\DAV\ICollection {
			/** Directory.php:173 — note the three optional args core adds to ICollection's. */
			abstract public function getChild($name, $info = null, ?IRequest $request = null, ?IL10N $l10n = null);

			/** Directory.php:469 — narrows Node::getNode(). */
			abstract public function getNode(): \OCP\Files\Folder;
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
		 * stub therefore records every call into a static log that tests read back —
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
				// Drain the stream so a test can assert *which* bytes were archived —
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
