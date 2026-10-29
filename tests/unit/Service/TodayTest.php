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

	public function testTheStampIsTheMomentTheWayTheClinicReadsItOnATicket(): void {
		$ts = (new \DateTimeImmutable('2026-10-18 02:30:00', new DateTimeZone('UTC')))->getTimestamp();

		$this->assertSame('17-10-2026 23:30', $this->today($ts, 'America/Santiago')->stamp());
		$this->assertSame('18-10-2026 02:30', $this->today($ts, 'UTC')->stamp());
	}

	public function testTheDayAndTimeAreThoseOfTheInstanceZoneNotUtc(): void {
		// 2026-10-18 02:30 UTC is still the 17th, 23:30, in Chile's summer time (UTC-3).
		$ts = (new \DateTimeImmutable('2026-10-18 02:30:00', new DateTimeZone('UTC')))->getTimestamp();
		$today = $this->today($ts, 'America/Santiago');

		$this->assertSame('2026-10-17', $today->day());
		$this->assertSame('23:30', $today->time());
	}

	public function testADiasWindowIsItsOwnUnixSecondsInTheInstanceZone(): void {
		// 2026-10-18 00:00 UTC, and the same wall clock three hours later in Chile's summer time.
		$this->assertSame([1792281600, 1792368000], $this->today(0, 'UTC')->dayWindow('2026-10-18'));
		$this->assertSame([1792292400, 1792378800], $this->today(0, 'America/Santiago')->dayWindow('2026-10-18'));
	}

	public function testADiasWindowIsTheCalendarDayNot86400Seconds(): void {
		// The night of 2026-04-04 the clocks in Chile go back one hour: that Día lasts 25 hours.
		$window = $this->today(0, 'America/Santiago')->dayWindow('2026-04-04');

		$this->assertSame(90000, $window[1] - $window[0]);
	}
}
