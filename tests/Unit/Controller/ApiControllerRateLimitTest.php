<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Controller;

use OCA\FilesWatermark\Controller\ApiController;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use PHPUnit\Framework\TestCase;

/**
 * The rate limits on the two expensive endpoints, read off the methods themselves.
 *
 * These are **declarative**: core's rate-limiting middleware reads the attribute and
 * answers 429 before the controller runs, so there is no code path inside this app that a
 * behavioural test could exercise. That is exactly why they are asserted here - an
 * attribute that is deleted, or quietly moved to a method nobody calls, leaves no trace.
 * Every other test in the suite passes just as happily with the throttle gone.
 *
 * The numbers are asserted too, not merely their presence. `limit: 20, period: 60` is
 * chosen to sit far above what the file action can produce by hand - each apply needs its
 * own modal confirmation - and far below what a script can, and a later edit that makes it
 * 2000 has removed the bound while leaving the attribute in place to suggest otherwise.
 */
class ApiControllerRateLimitTest extends TestCase {

	/**
	 * @dataProvider throttledMethodProvider
	 */
	public function testTheExpensiveEndpointsAreRateLimited(string $method): void {
		$attributes = (new \ReflectionMethod(ApiController::class, $method))
			->getAttributes(UserRateLimit::class);

		$this->assertCount(1, $attributes, "$method() must carry a UserRateLimit attribute.");

		$limit = $attributes[0]->newInstance();
		$this->assertSame(20, $limit->getLimit());
		$this->assertSame(60, $limit->getPeriod());
	}

	/** @return array<string, array{string}> */
	public static function throttledMethodProvider(): array {
		return [
			// Renders a whole file synchronously, inside the request.
			'apply' => ['applyWatermark'],
			// Rewrites a whole file from the preserved original - the same cost class.
			'remove' => ['removeWatermark'],
		];
	}

	/**
	 * The read-only endpoints are deliberately *not* throttled, and that is a decision
	 * rather than an oversight: the Files app calls `getWatermarkedStatus()` once per
	 * directory listing to paint the badges, so a limit low enough to matter would break
	 * ordinary browsing of a large folder.
	 */
	public function testTheCheapEndpointsAreNotThrottled(): void {
		foreach (['getWatermarkedStatus', 'getConfig', 'getLog'] as $method) {
			$this->assertSame(
				[],
				(new \ReflectionMethod(ApiController::class, $method))->getAttributes(UserRateLimit::class),
				"$method() is a read and must not be rate limited.",
			);
		}
	}
}
