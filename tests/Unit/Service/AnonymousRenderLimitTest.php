<?php

declare(strict_types=1);

namespace OCA\FilesWatermark\Tests\Unit\Service;

use OCA\FilesWatermark\Service\AnonymousRenderLimit;
use OCA\FilesWatermark\Service\RenderRateLimitedException;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\RateLimiting\ILimiter;
use OCP\Security\RateLimiting\IRateLimitExceededException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\FilesWatermark\Service\AnonymousRenderLimit
 */
class AnonymousRenderLimitTest extends TestCase {

	private IAppConfig&MockObject $appConfig;
	private IUserSession&MockObject $userSession;
	private ILimiter&MockObject $limiter;

	/** @var array<string, int> */
	private array $stored = [];

	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueInt')
			->willReturnCallback(fn (string $app, string $key, int $default): int => $this->stored[$key] ?? $default);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->limiter = $this->createMock(ILimiter::class);
	}

	private function limit(): AnonymousRenderLimit {
		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn('203.0.113.7');

		return new AnonymousRenderLimit($this->appConfig, new NullLogger(), $this->userSession, $request, $this->limiter);
	}

	public function testAnAnonymousRenderIsCountedAgainstItsAddressWithTheShippedDefaults(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->limiter->expects($this->once())
			->method('registerAnonRequest')
			->with(
				'files_watermark::anonymous-render',
				AnonymousRenderLimit::DEFAULT_LIMIT,
				AnonymousRenderLimit::DEFAULT_PERIOD,
				'203.0.113.7',
			);

		$this->limit()->charge();
	}

	public function testTheConfiguredLimitAndPeriodAreUsed(): void {
		$this->stored = [AnonymousRenderLimit::KEY_LIMIT => 200, AnonymousRenderLimit::KEY_PERIOD => 60];
		$this->userSession->method('getUser')->willReturn(null);
		$this->limiter->expects($this->once())
			->method('registerAnonRequest')
			->with($this->anything(), 200, 60, $this->anything());

		$this->limit()->charge();
	}

	/** Like every ceiling here, nothing below 1 - "0" reads like "off" and is not one. */
	public function testAValueBelowOneFallsBackToTheDefault(): void {
		$this->stored = [AnonymousRenderLimit::KEY_LIMIT => 0];

		$this->assertSame(AnonymousRenderLimit::DEFAULT_LIMIT, $this->limit()->maxRenders());
	}

	/** A signed-in reader is accountable and named; only the anonymous are counted. */
	public function testASignedInReaderIsNotCounted(): void {
		$this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));
		$this->limiter->expects($this->never())->method('registerAnonRequest');

		$this->limit()->charge();
	}

	public function testAnAddressPastItsLimitIsRefusedWithTheWindowToWaitOut(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->limiter->method('registerAnonRequest')
			->willThrowException(new class() extends \Exception implements IRateLimitExceededException {
			});

		try {
			$this->limit()->charge();
			$this->fail('a spent allowance must refuse the render');
		} catch (RenderRateLimitedException $e) {
			$this->assertSame(AnonymousRenderLimit::DEFAULT_PERIOD, $e->getRetryAfter());
		}
	}
}
