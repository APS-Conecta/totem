<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Domain;

use OCA\Totem\Domain\NextCall;
use OCA\Totem\Domain\QueuedTurno;
use PHPUnit\Framework\TestCase;

class NextCallTest extends TestCase {
	private static function turno(int $id, bool $preferente, int $createdAt, int $servicioId = 1): QueuedTurno {
		return new QueuedTurno($id, $servicioId, $preferente, $createdAt);
	}

	public function testNobodyWaitingMeansNobodyToCall(): void {
		$this->assertNull(NextCall::candidate([], []));
	}

	public function testAPreferenteGoesFirstWhenNothingHasBeenCalled(): void {
		$queue = [self::turno(1, false, 10), self::turno(2, true, 20)];

		$this->assertSame(2, NextCall::candidate($queue, [])->id);
	}

	public function testAfterOnePreferenteAnotherPreferenteStillGoesFirst(): void {
		$queue = [self::turno(1, false, 10), self::turno(2, true, 20)];

		$this->assertSame(2, NextCall::candidate($queue, [true])->id);
	}

	public function testAfterTwoPreferentesAGeneralGoesFirst(): void {
		$queue = [self::turno(1, true, 10), self::turno(2, false, 20)];

		$this->assertSame(2, NextCall::candidate($queue, [true, true])->id);
	}

	public function testAGeneralInTheLastTwoCallsLetsPreferentesGoAgain(): void {
		$queue = [self::turno(1, false, 10), self::turno(2, true, 20)];

		$this->assertSame(2, NextCall::candidate($queue, [false, true])->id);
		$this->assertSame(2, NextCall::candidate($queue, [true, false])->id);
	}

	public function testWhenAGeneralIsDueButNoneWaitTheWaitingPreferenteIsCalled(): void {
		$queue = [self::turno(1, true, 10)];

		$this->assertSame(1, NextCall::candidate($queue, [true, true])->id);
	}

	public function testWhenAPreferenteIsDueButNoneWaitTheWaitingGeneralIsCalled(): void {
		$queue = [self::turno(1, false, 10)];

		$this->assertSame(1, NextCall::candidate($queue, [])->id);
	}

	public function testWithinOneTypeTheEarliestTurnoIsCalledWhateverTheInputOrder(): void {
		$queue = [self::turno(3, true, 300), self::turno(1, true, 100), self::turno(2, true, 200)];

		$this->assertSame(1, NextCall::candidate($queue, [])->id);
	}

	public function testFivePreferentesAndFiveGeneralsAreCalledTwoToOne(): void {
		$queue = [];
		foreach (range(1, 5) as $n) {
			$queue[] = self::turno($n, true, $n * 10);      // P1..P5, ids 1..5
			$queue[] = self::turno(100 + $n, false, $n * 10 + 1); // G1..G5, ids 101..105
		}
		$history = [];
		$called = [];
		while ($next = NextCall::candidate($queue, $history)) {
			$called[] = ($next->preferente ? 'P' : 'G') . ($next->preferente ? $next->id : $next->id - 100);
			array_unshift($history, $next->preferente);
			$history = array_slice($history, 0, 2);
			$queue = array_values(array_filter($queue, static fn (QueuedTurno $t) => $t->id !== $next->id));
		}

		$this->assertSame(['P1', 'P2', 'G1', 'P3', 'P4', 'G2', 'P5', 'G3', 'G4', 'G5'], $called);
	}

	public function testABoxCoveringSeveralServiciosCallsTheCandidateThatHasWaitedLongest(): void {
		$farmacia = self::turno(1, true, 500, 1);
		$muestras = self::turno(2, false, 300, 2);

		$this->assertSame(2, NextCall::oldest([$farmacia, $muestras])->id);
	}

	public function testTwoCandidatesCreatedTogetherTieBreakOnTheLowerId(): void {
		$this->assertSame(4, NextCall::oldest([self::turno(9, true, 100, 1), self::turno(4, false, 100, 2)])->id);
	}

	public function testABoxWithNoCandidatesCallsNobody(): void {
		$this->assertNull(NextCall::oldest([]));
	}

	public function testAGeneralIsDueOnlyAfterTwoPreferentesInARow(): void {
		$this->assertFalse(NextCall::generalIsDue([]));
		$this->assertFalse(NextCall::generalIsDue([true]));
		$this->assertFalse(NextCall::generalIsDue([true, false]));
		$this->assertFalse(NextCall::generalIsDue([false, true]));
		$this->assertTrue(NextCall::generalIsDue([true, true]));
	}

	public function testWhyAPreferenteIsNext(): void {
		$this->assertSame('toca atención preferente', NextCall::why(self::turno(1, true, 10), false));
		$this->assertSame('no hay atención general en espera, sigue la preferente', NextCall::why(self::turno(1, true, 10), true));
	}

	public function testWhyAGeneralIsNext(): void {
		$this->assertSame('las 2 últimas llamadas fueron preferentes, toca atención general', NextCall::why(self::turno(1, false, 10), true));
		$this->assertSame('no hay atención preferente en espera', NextCall::why(self::turno(1, false, 10), false));
	}
}
