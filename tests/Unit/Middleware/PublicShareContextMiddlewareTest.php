<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Middleware;

use OCA\FilesWatermark\Middleware\PublicShareContextMiddleware;
use OCA\FilesWatermark\Service\ShareAccess;
use OCP\AppFramework\Controller;
use OCP\AppFramework\PublicShareController;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The signal that tells a public-link *preview* apart from an ordinary one.
 *
 * The bug this closes is worth restating, because the test reads as trivia without it: a
 * logged-in user opening somebody else's public link had a session (so the "nobody is signed
 * in" signal said nothing) and fetched thumbnails over an app-framework route rather than
 * the public DAV server (so the flag that server raises never fired). Their download of a
 * file was watermarked and their preview of the same file, on the same page, was not.
 *
 * The real `ShareAccess` is used rather than a mock: what matters is the state the
 * middleware leaves behind, which is the thing `WatermarkService` reads later.
 */
class PublicShareContextMiddlewareTest extends TestCase {

	private IUserSession&MockObject $userSession;
	private ShareAccess $shareAccess;
	private PublicShareContextMiddleware $middleware;

	protected function setUp(): void {
		parent::setUp();

		$this->userSession = $this->createMock(IUserSession::class);
		$this->shareAccess = new ShareAccess($this->userSession);
		$this->middleware = new PublicShareContextMiddleware($this->shareAccess);
	}

	/** Somebody is signed in, so the "no session user" signal cannot answer anything. */
	private function signedIn(): void {
		$this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));
	}

	public function testAPublicShareControllerMarksTheRequestAsExternal(): void {
		$this->signedIn();

		$this->middleware->beforeController(
			$this->createMock(PublicShareController::class),
			'getPreview',
		);

		$this->assertTrue($this->shareAccess->isExternalShareAccess());
	}

	/**
	 * The regression, stated as the two halves of one page: a logged-in visitor's preview
	 * must reach the same verdict their download already reached.
	 */
	public function testWithoutTheMiddlewareALoggedInVisitorLooksLikeAnOrdinaryReader(): void {
		$this->signedIn();

		$this->assertFalse(
			$this->shareAccess->isExternalShareAccess(),
			'a signed-in visitor is indistinguishable until something says "public share"',
		);
	}

	public function testAnOrdinaryControllerChangesNothing(): void {
		$this->signedIn();

		$this->middleware->beforeController($this->createMock(Controller::class), 'index');

		$this->assertFalse($this->shareAccess->isExternalShareAccess());
	}

	/**
	 * An anonymous visitor was already covered by `ShareAccess`'s own session test. The
	 * middleware must not be what makes that work, or the coverage would quietly depend on
	 * a route ever reaching a controller of the right type.
	 */
	public function testAnAnonymousVisitorIsExternalWithoutAnyControllerAtAll(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertTrue($this->shareAccess->isExternalShareAccess());
	}

	/**
	 * The flag is one-way for the life of the request. Public share pages fetch several
	 * things, and a later controller that is not a share controller must not take back what
	 * an earlier one established.
	 */
	public function testTheFlagIsNotTakenBackByALaterController(): void {
		$this->signedIn();

		$this->middleware->beforeController($this->createMock(PublicShareController::class), 'getPreview');
		$this->middleware->beforeController($this->createMock(Controller::class), 'index');

		$this->assertTrue($this->shareAccess->isExternalShareAccess());
	}
}
