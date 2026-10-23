<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\AppInfo;

use Composer\Autoload\ClassLoader;
use OCA\DAV\Events\BeforeFileDirectDownloadedEvent;
use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\FilesWatermark\Capabilities;
use OCA\FilesWatermark\EventListener\BeforePreviewFetchedListener;
use OCA\FilesWatermark\EventListener\DirectDownloadListener;
use OCA\FilesWatermark\EventListener\LoadAdditionalScriptsListener;
use OCA\FilesWatermark\EventListener\NodeCopiedListener;
use OCA\FilesWatermark\EventListener\NodeDeletedListener;
use OCA\FilesWatermark\EventListener\NodeMovedListener;
use OCA\FilesWatermark\EventListener\NodeWrittenListener;
use OCA\FilesWatermark\EventListener\PublicShareScriptsListener;
use OCA\FilesWatermark\EventListener\SabrePluginAddListener;
use OCA\FilesWatermark\EventListener\SabrePublicPluginAddListener;
use OCA\FilesWatermark\EventListener\TagAssignedListener;
use OCA\FilesWatermark\EventListener\TextAttachmentCopyListener;
use OCA\FilesWatermark\EventListener\UserDeletedListener;
use OCA\FilesWatermark\Middleware\AvatarMiddleware;
use OCA\FilesWatermark\Middleware\PublicShareContextMiddleware;
use OCA\FilesWatermark\Middleware\TaskProcessingMiddleware;
use OCA\FilesWatermark\Middleware\TextAttachmentMiddleware;
use OCA\FilesWatermark\Middleware\TextSessionMiddleware;
use OCA\FilesWatermark\Middleware\ThemingBackgroundMiddleware;
use OCA\FilesWatermark\Middleware\WatermarkPreviewMiddleware;
use OCA\FilesWatermark\Preview\PreviewRequestContext;
use OCA\FilesWatermark\Service\PdfFontPath;
use OCA\FilesWatermark\Service\ShareAccess;
use OCA\FilesWatermark\Service\ShareRecipient;
use OCA\FilesWatermark\Service\WatermarkService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BeforeSabrePubliclyLoadedEvent;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\IRootFolder;
use OCP\Files\Template\FileCreatedFromTemplateEvent;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Preview\BeforePreviewFetchedEvent;
use OCP\Share\IManager as IShareManager;
use OCP\SystemTag\TagAssignedEvent;
use OCP\User\Events\UserDeletedEvent;
use OCP\Util;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class Application extends App implements IBootstrap {

	public const APP_ID = 'files_watermark';

	/**
	 * The only vendor/ packages the app needs while running inside Nextcloud.
	 * Everything else there is dev-only (phpunit, php-cs-fixer, sabre/dav, ...)
	 * and must stay invisible to the runtime - see registerVendorAutoloader().
	 *
	 * Every transitive dependency has to be listed, not just the two packages named
	 * in `composer.json`, because this allowlist is what the runtime loader is built
	 * from - a missing entry is a "Class not found" fatal that only appears inside
	 * Nextcloud, never in the test suite, which uses Composer's own autoloader.
	 * `RuntimeVendorPackagesTest` guards that drift in both directions, and it is
	 * what caught these entries outliving FPDI and TCPDF.
	 */
	private const RUNTIME_VENDOR_PACKAGES = [
		'tecnickcom/tc-lib-barcode',
		'tecnickcom/tc-lib-color',
		'tecnickcom/tc-lib-file',
		'tecnickcom/tc-lib-pdf',
		'tecnickcom/tc-lib-pdf-encrypt',
		'tecnickcom/tc-lib-pdf-filter',
		'tecnickcom/tc-lib-pdf-font',
		'tecnickcom/tc-lib-pdf-graph',
		'tecnickcom/tc-lib-pdf-image',
		'tecnickcom/tc-lib-pdf-page',
		'tecnickcom/tc-lib-pdf-parser',
		'tecnickcom/tc-lib-pdf-sign',
		'tecnickcom/tc-lib-unicode',
		'tecnickcom/tc-lib-unicode-data',
	];

	private static bool $vendorAutoloaderRegistered = false;

	public function __construct() {
		parent::__construct(self::APP_ID);

		self::registerVendorAutoloader();

		// Claimed during app bootstrap, before anything can touch the renderer.
		// K_PATH_FONTS is a global constant and cannot be redefined, so whoever
		// defines it first wins - see PdfFontPath.
		PdfFontPath::register();
	}

	/**
	 * Make the bundled third-party libraries (the tc-lib-pdf stack) loadable at
	 * runtime. Nextcloud autoloads OCA\FilesWatermark\ classes from lib/, but not
	 * the vendor/ dependencies - without this, using the PDF watermarker throws
	 * "Class Com\Tecnick\Pdf\Tcpdf not found" (a fatal Error → HTTP 500).
	 *
	 * Deliberately *not* `require vendor/autoload.php`: Composer registers that
	 * loader with prepend = true and for *every* installed package, dev ones
	 * included. That put our vendor/sabre/dav ahead of the copy Nextcloud ships
	 * in 3rdparty/, so core resolved e.g. Sabre\DAV\ICopyTarget from our tree.
	 * The two versions' signatures differ (4.7.1 added `int $depth` to
	 * copyInto()), which made core's own OCA\DAV\Connector\Sabre\Directory
	 * violate the interface it implements and logged an error on every DAV
	 * request. Instead: build a loader from Composer's generated maps, keep only
	 * the runtime packages, and *append* it so core's autoloader always wins.
	 */
	private static function registerVendorAutoloader(): void {
		if (self::$vendorAutoloaderRegistered) {
			return;
		}

		$vendorDir = __DIR__ . '/../../vendor';
		$psr4File = $vendorDir . '/composer/autoload_psr4.php';
		$classMapFile = $vendorDir . '/composer/autoload_classmap.php';
		if (!file_exists($psr4File) || !file_exists($classMapFile)) {
			return;
		}

		// Nextcloud's own bootstrap has already declared ClassLoader from
		// 3rdparty/. require_once keys on the file path, not the class name, so
		// including our copy as well would fatal with "Cannot declare class
		// Composer\Autoload\ClassLoader, because the name is already in use".
		if (!class_exists(ClassLoader::class, false)) {
			$classLoaderFile = $vendorDir . '/composer/ClassLoader.php';
			if (!file_exists($classLoaderFile)) {
				return;
			}
			require_once $classLoaderFile;
		}
		self::$vendorAutoloaderRegistered = true;

		// Match on the package sub-path rather than an absolute prefix so this
		// holds however vendor/ is mounted or symlinked.
		$needles = array_map(static fn (string $package): string => '/' . $package . '/', self::RUNTIME_VENDOR_PACKAGES);
		$isRuntimePath = static function (string $path) use ($needles): bool {
			foreach ($needles as $needle) {
				if (str_contains($path, $needle)) {
					return true;
				}
			}
			return false;
		};

		$loader = new ClassLoader();

		// The tc-lib-* packages are all PSR-4. The classmap pass below is kept because
		// Composer still emits one and a future dependency may rely on it.
		foreach (require $psr4File as $prefix => $paths) {
			$paths = array_values(array_filter($paths, $isRuntimePath));
			if ($paths !== []) {
				$loader->setPsr4($prefix, $paths);
			}
		}
		$loader->addClassMap(array_filter(require $classMapFile, $isRuntimePath));

		// Composer's autoload_files.php is skipped on purpose: no runtime package has a
		// `files` entry, while the dev ones do (phpunit's global assertion functions,
		// sabre's helpers) and those must not be pulled in.

		// register() appends. Never prepend - that is what caused the shadowing.
		$loader->register();
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(NodeWrittenEvent::class, NodeWrittenListener::class);
		// A copy gets a new file id, so it lands outside every mark already placed - which
		// made copying a shared file the way to obtain it clean. See NodeCopiedListener.
		// Ahead of Text's own copy listener (priority 0), which renames the attachment folders
		// of a copied folder's notes: the walk pairs the tree by path, as core copied it.
		$context->registerEventListener(NodeCopiedEvent::class, NodeCopiedListener::class, 100);
		// And after it, for a single note: Text writes the attachments' copies itself, under
		// new ids, so they can only be paired once it has. See TextAttachmentCopyListener.
		$context->registerEventListener(NodeCopiedEvent::class, TextAttachmentCopyListener::class, -100);
		// "New from template" copies the template's bytes with a write, not a copy, and this is
		// the only event that names the template - so the same listener takes it.
		$context->registerEventListener(FileCreatedFromTemplateEvent::class, NodeCopiedListener::class);
		// A move keeps the file id, so the mark moves with the file on its own - but a move
		// out of a share into somebody else's storage escapes the share switch, and the rare
		// move that cannot keep the id leaves the mark behind. Both halves of the move are
		// needed (see NodeMovedListener), so one shared instance takes both events.
		$context->registerService(NodeMovedListener::class, static fn (
			ContainerInterface $c,
		): NodeMovedListener => new NodeMovedListener(
			$c->get(WatermarkService::class),
			$c->get(LoggerInterface::class),
		), true);
		$context->registerEventListener(BeforeNodeRenamedEvent::class, NodeMovedListener::class);
		$context->registerEventListener(NodeRenamedEvent::class, NodeMovedListener::class);
		// A move into the policy's scope writes nothing, so the upload trigger hears the rename
		// too. Behind NodeMovedListener: a file owed an inherited mark naming the sharer must
		// get that one, not an ordinary mark naming whoever moved it. See NodeWrittenListener.
		$context->registerEventListener(NodeRenamedEvent::class, NodeWrittenListener::class, -100);
		// Tagging a folder brings the files already in it into scope and writes nothing either.
		// See TagAssignedListener.
		$context->registerEventListener(TagAssignedEvent::class, TagAssignedListener::class);
		// A recipient's delete inside a share puts a copy in their own trash, under a new id and
		// with no copy event. The listener decides before the delete and marks after it, so both
		// events need the same instance; it connects that instance to the trash's own legacy
		// hook in between - see NodeDeletedListener.
		$context->registerService(NodeDeletedListener::class, static fn (
			ContainerInterface $c,
		): NodeDeletedListener => new NodeDeletedListener(
			$c->get(IRootFolder::class),
			$c->get(WatermarkService::class),
			$c->get(LoggerInterface::class),
		), true);
		$context->registerEventListener(BeforeNodeDeletedEvent::class, NodeDeletedListener::class);
		$context->registerEventListener(NodeDeletedEvent::class, NodeDeletedListener::class);
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadAdditionalScriptsListener::class);
		$context->registerEventListener(SabrePluginAddEvent::class, SabrePluginAddListener::class);
		// Public links are served by a *separate* Sabre server that never fires
		// SabrePluginAddEvent - it needs its own registration to be watermarked.
		$context->registerEventListener(BeforeSabrePubliclyLoadedEvent::class, SabrePublicPluginAddListener::class);
		// The badge on the public share page. Registered by class-string rather than by
		// `::class`: the event belongs to `files_sharing`, and naming it as a constant here
		// would make this file's own loading depend on that app being enabled - on a server
		// with public links switched off, the event simply never fires and this is inert.
		$context->registerEventListener(
			'OCA\Files_Sharing\Event\BeforeTemplateRenderedEvent',
			PublicShareScriptsListener::class,
		);
		$context->registerEventListener(BeforePreviewFetchedEvent::class, BeforePreviewFetchedListener::class);
		// `/remote.php/direct/{token}` is a third DAV server, and the only one that announces
		// itself to no app. Its one hook is this event, fired just before it opens the stored
		// file - see DirectDownloadListener for why refusing is all that can be done there.
		$context->registerEventListener(BeforeFileDirectDownloadedEvent::class, DirectDownloadListener::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);

		// For the native clients: the trigger, the types and the limits they gate the file
		// actions on, none of which an account that is not an admin could read before.
		$context->registerCapability(Capabilities::class);

		// The preview pair. The listener notes *which* file a preview request is for - it
		// is the only hook every preview endpoint passes through - and the middleware
		// replaces the response with one carrying the viewer's own name.
		//
		// **Global, which is unusual and load-bearing:** the controllers serving previews
		// belong to core and to files_sharing, and an app's middleware reaches them only
		// with this flag. Registered app-local it would run on this app's own six routes,
		// none of which serves a preview, and every thumbnail on the server would go out
		// clean with nothing to show for it.
		$context->registerMiddleware(WatermarkPreviewMiddleware::class, true);
		// Also global, and for the same reason: it has to see `files_sharing`'s controllers.
		// It marks the request as a public-link fetch on the routes the public DAV server
		// never serves - which is how a logged-in visitor's *preview* of somebody else's
		// link used to come out clean while the download of the same file did not.
		$context->registerMiddleware(PublicShareContextMiddleware::class, true);
		// Global as well: Text serves a document's attachments from its own controller with
		// `getContent()`, never through DAV, so this is the only place they can be caught.
		$context->registerMiddleware(TextAttachmentMiddleware::class, true);
		// And its session API, whose answers carry the opened document's stored content.
		$context->registerMiddleware(TextSessionMiddleware::class, true);
		// Global for Theming too: a custom background is a copy of a file from Files,
		// served back as stored, so the copy is refused before it is made.
		$context->registerMiddleware(ThemingBackgroundMiddleware::class, true);
		// And for core's profile picture from Files, which hands the file back re-encoded
		// or makes it the avatar - refused before it is read.
		$context->registerMiddleware(AvatarMiddleware::class, true);
		// And for core's task processing API, which streams a task's files - to the user and
		// to the ExApp running the task - straight from storage.
		$context->registerMiddleware(TaskProcessingMiddleware::class, true);
		// One instance per request, shared by both halves above: the middleware reads what
		// the listener recorded, and two instances would leave it reading an empty one.
		$context->registerService(PreviewRequestContext::class, static fn (
			ContainerInterface $c,
		): PreviewRequestContext => new PreviewRequestContext(), true);

		// Shared for the same reason: the public-link DAV listener raises a flag on it that
		// WatermarkService reads later in the same request. Autowiring would hand each of
		// them its own instance, and the flag would never arrive.
		$context->registerService(ShareAccess::class, static fn (
			ContainerInterface $c,
		): ShareAccess => new ShareAccess($c->get(IUserSession::class)), true);

		// Shared for the same reason again: PublicShareContextMiddleware notes the share
		// token on it and WatermarkService reads it back when it builds the placeholders,
		// later in the same request.
		$context->registerService(ShareRecipient::class, static fn (
			ContainerInterface $c,
		): ShareRecipient => new ShareRecipient($c->get(IShareManager::class)), true);
	}

	/**
	 * Ask for the Files integration bundle early on the pages that render a file list.
	 *
	 * `LoadAdditionalScriptsEvent` - where this bundle was requested from, and still is -
	 * fires *after* the Files app has added its own script, and the Files app issues its
	 * first PROPFIND while that script runs. So the `is-watermarked` property was never
	 * registered in time for the first listing of a page load, and every row rendered
	 * from a listing that did not carry it: Apply offered on watermarked files, Remove
	 * missing from them, and no badge, until the user navigated somewhere else.
	 *
	 * boot() runs before any controller, and Nextcloud emits scripts grouped by app in
	 * the order each app first asks for one, so asking here is what puts this app's
	 * bundle ahead of `files/js/main`. Duplicate requests for the same script are
	 * dropped by core, so the listener's own call is left exactly as it was - it is what
	 * covers a Files page reached by some route this test does not recognise.
	 *
	 * {@see FilesPageScript} holds the rule and the full reasoning.
	 */
	public function boot(IBootContext $context): void {
		try {
			$pathInfo = $context->getServerContainer()->get(IRequest::class)->getPathInfo();
		} catch (\Throwable) {
			// No request to classify - CLI, cron, or a URI core itself could not parse.
			// Nothing renders a file list in any of those, so there is nothing to load.
			return;
		}

		$path = $pathInfo === false ? null : $pathInfo;

		if (FilesPageScript::wantedFor($path)) {
			Util::addScript(self::APP_ID, FilesPageScript::SCRIPT);
		}

		// The public share page has the same ordering problem and no second listing to
		// recover on - see FilesPageScript::wantedForPublicShare(). The listener still asks
		// for this script as well; core drops the duplicate, and it is what covers a public
		// page reached by a route this rule does not recognise.
		if (FilesPageScript::wantedForPublicShare($path)) {
			Util::addScript(self::APP_ID, FilesPageScript::PUBLIC_SCRIPT);
		}
	}
}
