<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Service;

use OCA\Totem\Db\Totem;
use OCA\Totem\Domain\PrintableTicket;
use OCA\Totem\Service\TicketPrinter;
use OCA\Totem\Service\TicketPrinting;
use OCA\Totem\Service\Today;
use OCP\Defaults;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TicketPrintingTest extends TestCase {
	private TicketPrinter&MockObject $printer;
	private TicketPrinting $printing;

	protected function setUp(): void {
		$this->printer = $this->createMock(TicketPrinter::class);
		$defaults = $this->createMock(Defaults::class);
		$defaults->method('getName')->willReturn('CESFAM Ejemplo');
		$today = $this->createMock(Today::class);
		$today->method('stamp')->willReturn('19-10-2026 14:30');
		$this->printing = new TicketPrinting($this->printer, $defaults, $today);
	}

	private function totem(): Totem {
		$totem = new Totem();
		$totem->setSlug('sala');
		$totem->setPrinterHost('192.168.1.50');

		return $totem;
	}

	public function testATurnoIsPrintedWithTheEstablishmentTheLabelTheUnidadAndTheMoment(): void {
		$totem = $this->totem();
		$this->printer->expects($this->once())->method('print')->with($totem, $this->callback(
			static fn (PrintableTicket $t) => $t->establishment === 'CESFAM Ejemplo' && $t->label === 'FAR-P-014' && $t->servicio === 'Farmacia'
				&& $t->preferente === true && $t->issuedOn === '19-10-2026 14:30',
		))->willReturn(true);

		$this->assertTrue($this->printing->printTurno($totem, 'FAR-P-014', 'Farmacia', true));
	}

	public function testItAnswersWhatThePrinterAnswered(): void {
		$this->printer->method('print')->willReturn(false);

		$this->assertFalse($this->printing->printTurno($this->totem(), 'FAR-G-001', 'Farmacia', false));
	}

	public function testATestTicketSaysItIsATestAndTakesNoTurno(): void {
		$totem = $this->totem();
		$this->printer->expects($this->once())->method('print')->with($totem, $this->callback(
			static fn (PrintableTicket $t) => $t->label === 'PRUEBA' && $t->servicio === 'Ticket de prueba' && $t->preferente === false,
		))->willReturn(true);

		$this->assertTrue($this->printing->printTest($totem));
	}
}
