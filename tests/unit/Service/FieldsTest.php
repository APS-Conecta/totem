<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Service;

use OCA\Totem\Exception\Refused;
use OCA\Totem\Service\Fields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FieldsTest extends TestCase {
	public function testTextIsTrimmed(): void {
		$this->assertSame('Farmacia', Fields::text('  Farmacia ', 'El nombre', 28));
	}

	public function testBlankTextRefusesWithTheFieldsName(): void {
		$this->expectException(Refused::class);
		$this->expectExceptionMessage('El nombre no puede quedar vacío.');
		Fields::text('   ', 'El nombre', 28);
	}

	public function testTextOverTheLimitRefusesWithTheLimit(): void {
		$this->expectException(Refused::class);
		$this->expectExceptionMessage('El nombre admite hasta 5 caracteres.');
		Fields::text('Farmacia', 'El nombre', 5);
	}

	public function testTheLimitCountsLettersNotBytes(): void {
		$this->assertSame('Atención', Fields::text('Atención', 'El nombre', 8));
	}

	public function testAPrefixIsUppercased(): void {
		$this->assertSame('FAR', Fields::prefix(' far '));
	}

	#[DataProvider('badPrefixes')]
	public function testABadPrefixRefuses(string $prefix): void {
		$this->expectException(Refused::class);
		$this->expectExceptionMessage('El prefijo debe tener de 1 a 4 letras o números.');
		Fields::prefix($prefix);
	}

	/** @return array<string, array{string}> */
	public static function badPrefixes(): array {
		return ['empty' => [''], 'too long' => ['FARMA'], 'a dash' => ['F-R'], 'an accent' => ['ÁÑO']];
	}

	public function testATimeMustBeHoursAndMinutes(): void {
		$this->assertSame('07:30', Fields::time('07:30', 'La hora de apertura'));
		$this->assertSame('23:59', Fields::time('23:59', 'La hora de cierre'));
	}

	#[DataProvider('badTimes')]
	public function testABadTimeRefuses(string $time): void {
		$this->expectException(Refused::class);
		$this->expectExceptionMessage('La hora de cierre debe ser una hora como 07:30.');
		Fields::time($time, 'La hora de cierre');
	}

	/** @return array<string, array{string}> */
	public static function badTimes(): array {
		return ['hour 24' => ['24:00'], 'minute 60' => ['10:60'], 'no leading zero' => ['7:30'], 'text' => ['mañana']];
	}

	public function testAColorIsALowercaseHexTriplet(): void {
		$this->assertSame('#0b6e69', Fields::color('#0B6E69'));
	}

	public function testABadColorRefuses(): void {
		$this->expectException(Refused::class);
		$this->expectExceptionMessage('El color debe ser como #0b6e69.');
		Fields::color('verde');
	}

	public function testABlankQuotaMeansNoLimit(): void {
		$this->assertNull(Fields::quota(null));
		$this->assertNull(Fields::quota(''));
		$this->assertSame(80, Fields::quota('80'));
		$this->assertSame(80, Fields::quota(80));
	}

	#[DataProvider('badQuotas')]
	public function testABadQuotaRefuses(mixed $quota): void {
		$this->expectException(Refused::class);
		$this->expectExceptionMessage('El cupo diario debe ser un número mayor que cero, o quedar vacío.');
		Fields::quota($quota);
	}

	/** @return array<string, array{mixed}> */
	public static function badQuotas(): array {
		return ['zero' => [0], 'negative' => [-3], 'text' => ['muchos'], 'decimal' => ['2.5']];
	}

	public function testVolumeIsAWholeNumberFromZeroToOneHundred(): void {
		$this->assertSame(0, Fields::volume(0));
		$this->assertSame(100, Fields::volume('100'));
	}

	public function testVolumeOutOfRangeRefuses(): void {
		$this->expectException(Refused::class);
		$this->expectExceptionMessage('El volumen debe estar entre 0 y 100.');
		Fields::volume(101);
	}

	public function testTheVoiceIsOneOfThree(): void {
		foreach (['mujer', 'hombre', 'off'] as $voice) {
			$this->assertSame($voice, Fields::voice($voice));
		}
		$this->expectException(Refused::class);
		$this->expectExceptionMessage('La voz debe ser Mujer, Hombre o Sin voz.');
		Fields::voice('robot');
	}

	public function testIdsAreDistinctIntegers(): void {
		$this->assertSame([3, 4], Fields::ids([3, '4', 3]));
		$this->assertSame([], Fields::ids(null));
	}
}
