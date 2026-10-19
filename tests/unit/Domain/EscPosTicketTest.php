<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Domain;

use OCA\Totem\Domain\EscPosTicket;
use OCA\Totem\Domain\PrintableTicket;
use PHPUnit\Framework\TestCase;

class EscPosTicketTest extends TestCase {
	private const INITIALIZE = "\x1B\x40";
	private const PC850 = "\x1B\x74\x02";
	private const CENTER = "\x1B\x61\x01";
	private const HUGE = "\x1D\x21\x33";
	private const DOUBLE = "\x1D\x21\x11";
	private const NORMAL = "\x1D\x21\x00";
	private const CUT = "\x1D\x56\x42\x03";

	private function ticket(array $over = []): PrintableTicket {
		return new PrintableTicket(...($over + [
			'establishment' => 'CESFAM Ejemplo',
			'label' => 'FAR-P-014',
			'servicio' => 'Farmacia',
			'preferente' => true,
			'issuedOn' => '19-10-2026 14:30',
		]));
	}

	public function testItStartsFromAResetPrinterInTheWesternEuropeanCodePage(): void {
		$bytes = EscPosTicket::render($this->ticket());

		$this->assertStringStartsWith(self::INITIALIZE . self::PC850 . self::CENTER, $bytes);
	}

	public function testTheLabelIsTheBiggestThingOnThePaper(): void {
		$bytes = EscPosTicket::render($this->ticket());

		$this->assertStringContainsString(self::HUGE . "FAR-P-014\n" . self::NORMAL, $bytes);
	}

	public function testItNamesTheEstablishmentTheUnidadAndTheMoment(): void {
		$bytes = EscPosTicket::render($this->ticket());

		$this->assertStringContainsString("CESFAM Ejemplo\n", $bytes);
		$this->assertStringContainsString("Farmacia\n", $bytes);
		$this->assertStringContainsString("19-10-2026 14:30\n", $bytes);
	}

	public function testItSaysAtencionPreferenteOnlyForAPreferenteTicket(): void {
		$preferente = EscPosTicket::render($this->ticket());
		$general = EscPosTicket::render($this->ticket(['label' => 'FAR-G-032', 'preferente' => false]));

		$this->assertStringContainsString(self::DOUBLE . "ATENCI\xE0N PREFERENTE\n", $preferente);
		$this->assertStringNotContainsString('PREFERENTE', $general);
	}

	public function testItTellsThePatientWhatToDoNext(): void {
		$bytes = EscPosTicket::render($this->ticket());

		$this->assertStringContainsString('Espere su llamado en la sala de espera.', $bytes);
		$this->assertStringContainsString('Mire la pantalla y escuche el aviso.', $bytes);
	}

	public function testItFeedsAndCutsThePaperAtTheEnd(): void {
		$this->assertStringEndsWith(self::CUT, EscPosTicket::render($this->ticket()));
	}

	public function testSpanishLettersAreSentInThePrintersCodePageNotAsUtf8(): void {
		$bytes = EscPosTicket::render($this->ticket(['establishment' => 'CESFAM Peñalolén', 'servicio' => 'Odontología']));

		$this->assertStringContainsString("CESFAM Pe\xA4alol\x82n\n", $bytes);
		$this->assertStringContainsString("Odontolog\xA1a\n", $bytes);
		$this->assertStringNotContainsString("\xC3", $bytes);
	}

	public function testALetterThePrinterCannotPrintBecomesAQuestionMarkInsteadOfBreakingTheTicket(): void {
		$bytes = EscPosTicket::render($this->ticket(['servicio' => 'Łódź']));

		$this->assertStringContainsString("?\xA2d?\n", $bytes);
	}

	public function testAnAdminTypedNameCannotInjectPrinterCommands(): void {
		$bytes = EscPosTicket::render($this->ticket(['servicio' => "Farmacia\x1B\x40\x1D\x56\x00\x07"]));

		$this->assertStringContainsString("Farmacia@V\n", $bytes);
		$this->assertSame(1, substr_count($bytes, self::INITIALIZE));
		$this->assertSame(1, substr_count($bytes, "\x1D\x56"));
		$this->assertStringNotContainsString("\x07", $bytes);
	}

	public function testALongNameWrapsAtTheWidthOfThePaperInsteadOfRunningOff(): void {
		$long = 'Centro de Salud Familiar Doctor Alberto Hurtado Sector Norte Ampliado';
		$bytes = EscPosTicket::render($this->ticket(['establishment' => $long]));

		foreach (explode("\n", $bytes) as $line) {
			$this->assertLessThanOrEqual(48, strlen(str_replace(["\x1B\x40", self::PC850, self::CENTER, self::HUGE, self::DOUBLE, self::NORMAL, self::CUT], '', $line)));
		}
		$this->assertStringContainsString("Centro de Salud Familiar Doctor Alberto Hurtado\nSector Norte Ampliado\n", $bytes);
	}
}
