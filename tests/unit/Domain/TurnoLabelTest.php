<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Domain;

use OCA\Totem\Domain\TurnoLabel;
use PHPUnit\Framework\TestCase;

class TurnoLabelTest extends TestCase {
	public function testAPreferenteTurnoIsPrefixPAndAThreeDigitSequence(): void {
		$this->assertSame('FAR-P-014', TurnoLabel::of('FAR', true, 14));
	}

	public function testAGeneralTurnoIsPrefixGAndPadsTheSequence(): void {
		$this->assertSame('MUE-G-007', TurnoLabel::of('MUE', false, 7));
		$this->assertSame('FAR-G-001', TurnoLabel::of('FAR', false, 1));
	}

	public function testASequenceOverAThousandIsNotTruncated(): void {
		$this->assertSame('FAR-G-1204', TurnoLabel::of('FAR', false, 1204));
	}

	public function testThePreferenteCallIsSpokenWithWordsNotLetters(): void {
		// "P" and "G" sound alike over a speaker; the words do not.
		$this->assertSame(
			'Farmacia, preferente 12. Diríjase a ventanilla 2.',
			TurnoLabel::spoken('Farmacia', true, 12, 'ventanilla 2'),
		);
	}

	public function testTheGeneralCallIsSpokenWithTheWordGeneral(): void {
		$this->assertSame(
			'Toma de Muestras, general 7. Diríjase a ventanilla 5.',
			TurnoLabel::spoken('Toma de Muestras', false, 7, 'ventanilla 5'),
		);
	}
}
