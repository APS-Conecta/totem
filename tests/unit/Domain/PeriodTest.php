<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Domain;

use OCA\Totem\Domain\Period;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PeriodTest extends TestCase {
	public function testADayIsJustThatDay(): void {
		$period = Period::of('day', '2026-10-21');

		$this->assertSame('2026-10-21', $period->from());
		$this->assertSame('2026-10-21', $period->to());
		$this->assertSame(['2026-10-21'], $period->days());
	}

	/** @return array<string, array{string, string, string}> */
	public static function weeks(): array {
		return [
			'a wednesday' => ['2026-10-21', '2026-10-19', '2026-10-25'],
			'the monday itself' => ['2026-10-19', '2026-10-19', '2026-10-25'],
			'the sunday belongs to the week that began on the monday before' => ['2026-10-25', '2026-10-19', '2026-10-25'],
			'a week that crosses a month' => ['2026-10-29', '2026-10-26', '2026-11-01'],
			'a week that crosses a year' => ['2026-12-31', '2026-12-28', '2027-01-03'],
		];
	}

	#[DataProvider('weeks')]
	public function testAWeekRunsFromMondayToSunday(string $anchor, string $from, string $to): void {
		$period = Period::of('week', $anchor);

		$this->assertSame($from, $period->from());
		$this->assertSame($to, $period->to());
		$this->assertCount(7, $period->days());
	}

	/** @return array<string, array{string, string, string, int}> */
	public static function months(): array {
		return [
			'october' => ['2026-10-21', '2026-10-01', '2026-10-31', 31],
			'february in a common year' => ['2026-02-10', '2026-02-01', '2026-02-28', 28],
			'february in a leap year' => ['2028-02-29', '2028-02-01', '2028-02-29', 29],
		];
	}

	#[DataProvider('months')]
	public function testAMonthIsTheCalendarMonth(string $anchor, string $from, string $to, int $count): void {
		$period = Period::of('month', $anchor);

		$this->assertSame($from, $period->from());
		$this->assertSame($to, $period->to());
		$this->assertCount($count, $period->days());
		$this->assertSame($from, $period->days()[0]);
		$this->assertSame($to, $period->days()[$count - 1]);
	}

	public function testItMovesToTheNeighbouringPeriods(): void {
		$this->assertSame('2026-10-20', Period::of('day', '2026-10-21')->shift(-1)->from());
		$this->assertSame('2026-10-12', Period::of('week', '2026-10-21')->shift(-1)->from());
		$this->assertSame('2026-10-26', Period::of('week', '2026-10-21')->shift(1)->from());
		$this->assertSame('2026-09-01', Period::of('month', '2026-10-21')->shift(-1)->from());
	}

	public function testAMonthStepNeverSkipsOverAShortMonth(): void {
		$this->assertSame('2026-02-01', Period::of('month', '2026-01-31')->shift(1)->from());
		$this->assertSame('2027-01-01', Period::of('month', '2026-12-15')->shift(1)->from());
	}

	public function testItIsInTheFutureOnlyWhenItStartsAfterToday(): void {
		$this->assertFalse(Period::of('week', '2026-10-21')->startsAfter('2026-10-21'));
		$this->assertFalse(Period::of('week', '2026-10-19')->startsAfter('2026-10-21'));
		$this->assertTrue(Period::of('week', '2026-10-26')->startsAfter('2026-10-21'));
		$this->assertTrue(Period::of('day', '2026-10-22')->startsAfter('2026-10-21'));
	}

	public function testItExposesItsKind(): void {
		$this->assertSame('month', Period::of('month', '2026-10-21')->kind());
	}

	public function testAnUnknownKindIsRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		Period::of('year', '2026-10-21');
	}

	/** @return array<string, array{string}> */
	public static function badDates(): array {
		return [
			'text' => ['ayer'],
			'empty' => [''],
			'a day that does not exist' => ['2026-02-30'],
			'the wrong order' => ['21-10-2026'],
			'a time attached' => ['2026-10-21 10:00'],
		];
	}

	#[DataProvider('badDates')]
	public function testAnInvalidDateIsRejected(string $date): void {
		$this->expectException(\InvalidArgumentException::class);
		Period::of('day', $date);
	}
}
