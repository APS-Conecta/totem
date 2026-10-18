<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Service;

use DateTimeZone;
use OCA\Totem\Service\Today;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use PHPUnit\Framework\TestCase;

class TodayTest extends TestCase {
	private function today(int $timestamp, string $zone): Today {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($timestamp);
		$tz = $this->createMock(IDateTimeZone::class);
		$tz->method('getDefaultTimeZone')->willReturn(new DateTimeZone($zone));

		return new Today($time, $tz);
	}

	public function testItReadsTheClockAsUnixSeconds(): void {
		$this->assertSame(1_792_290_600, $this->today(1_792_290_600, 'UTC')->now());
	}

	public function testTheDayAndTimeAreThoseOfTheInstanceZoneNotUtc(): void {
		// 2026-10-18 02:30 UTC is still the 17th, 23:30, in Chile's summer time (UTC-3).
		$ts = (new \DateTimeImmutable('2026-10-18 02:30:00', new DateTimeZone('UTC')))->getTimestamp();
		$today = $this->today($ts, 'America/Santiago');

		$this->assertSame('2026-10-17', $today->day());
		$this->assertSame('23:30', $today->time());
	}
}
