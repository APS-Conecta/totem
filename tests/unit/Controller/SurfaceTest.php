<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Controller;

use OCA\Totem\AppInfo\Application;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use PHPUnit\Framework\TestCase;

/**
 * Who can reach what, read from the code the way Nextcloud reads it. A mistake
 * here is not a bug a screen shows: it is a staff endpoint answering anonymous
 * callers. So the whole route table is checked against one allow-list.
 */
class SurfaceTest extends TestCase {
	/** The only endpoints an anonymous person may reach: the kiosk and the waiting-room display. */
	private const PUBLIC = ['page#totem', 'page#display', 'turno#create', 'turno#servicios', 'display#feed'];

	/** Logged-in staff, not admins: the box console. */
	private const STAFF = ['page#box', 'call#boxes', 'call#state', 'call#call', 'call#recall', 'call#start', 'call#finish', 'call#absent'];

	/** @return list<array{name: string, url: string, verb: string}> */
	private static function routes(): array {
		return (require __DIR__ . '/../../../appinfo/routes.php')['routes'];
	}

	private static function method(string $name): \ReflectionMethod {
		[$controller, $action] = explode('#', $name);
		$class = 'OCA\\Totem\\Controller\\' . ucfirst($controller) . 'Controller';
		// `call#call` is written call#call in routes; the action is the camel-cased part after the hash.
		return new \ReflectionMethod($class, lcfirst(str_replace('_', '', ucwords($action, '_'))));
	}

	private static function has(\ReflectionMethod $m, string $attribute): bool {
		return $m->getAttributes($attribute) !== [];
	}

	public function testEveryRouteNamesAControllerActionThatExists(): void {
		foreach (self::routes() as $route) {
			$this->assertTrue(self::method($route['name'])->isPublic(), $route['name']);
		}
	}

	public function testOnlyTheAllowListIsPublic(): void {
		foreach (self::routes() as $route) {
			$m = self::method($route['name']);
			$this->assertSame(
				in_array($route['name'], self::PUBLIC, true),
				self::has($m, PublicPage::class),
				$route['name'] . ' public-page attribute does not match the allow-list',
			);
		}
	}

	public function testEveryPublicEndpointIsRateLimited(): void {
		foreach (self::PUBLIC as $name) {
			$this->assertTrue(self::has(self::method($name), AnonRateLimit::class), "$name needs #[AnonRateLimit]");
		}
	}

	public function testTheBoxConsoleIsForAnyLoggedInUserAndIsRateLimited(): void {
		foreach (self::STAFF as $name) {
			$m = self::method($name);
			$this->assertTrue(self::has($m, NoAdminRequired::class), "$name should not need an admin");
			$this->assertFalse(self::has($m, PublicPage::class), "$name must not be public");
			if ($name !== 'page#box') {
				$this->assertTrue(self::has($m, UserRateLimit::class), "$name needs #[UserRateLimit]");
			}
		}
	}

	public function testEverythingElseNeedsANextcloudAdmin(): void {
		foreach (self::routes() as $route) {
			if (in_array($route['name'], [...self::PUBLIC, ...self::STAFF], true)) {
				continue;
			}
			$this->assertFalse(self::has(self::method($route['name']), NoAdminRequired::class), $route['name'] . ' is admin-only');
		}
	}

	public function testOnlyThePagesSkipTheCsrfToken(): void {
		// A top-level navigation carries no request token. The anonymous kiosk POST has none either:
		// it only issues a number, and the threat model says why that is acceptable.
		$allowed = ['page#totem', 'page#display', 'page#box', 'page#admin', 'turno#create', 'turno#servicios', 'display#feed'];
		foreach (self::routes() as $route) {
			$this->assertSame(
				in_array($route['name'], $allowed, true),
				self::has(self::method($route['name']), NoCSRFRequired::class),
				$route['name'] . ' CSRF exemption does not match the allow-list',
			);
		}
	}

	public function testTheAppIdIsTheRoutePrefix(): void {
		$this->assertSame('totem', Application::APP_ID);
	}
}
