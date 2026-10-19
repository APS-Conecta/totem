<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Service;

use OCA\Totem\Db\Totem;
use OCA\Totem\Db\TotemMapper;
use OCA\Totem\Domain\EscPosTicket;
use OCA\Totem\Domain\PrintableTicket;
use OCA\Totem\Service\PrinterTransport;
use OCA\Totem\Service\TicketPrinter;
use OCA\Totem\Service\Today;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TicketPrinterTest extends TestCase {
	private PrinterTransport&MockObject $transport;
	private TotemMapper&MockObject $totems;
	private LoggerInterface&MockObject $logger;
	private TicketPrinter $printer;

	protected function setUp(): void {
		$this->transport = $this->createMock(PrinterTransport::class);
		$this->totems = $this->createMock(TotemMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$today = $this->createMock(Today::class);
		$today->method('now')->willReturn(1_792_400_000);
		$this->printer = new TicketPrinter($this->transport, $this->totems, $this->logger, $today);
	}

	private function totem(string $host = '192.168.1.50', int $port = 9100): Totem {
		$totem = new Totem();
		$totem->setId(4);
		$totem->setSlug('sala');
		$totem->setName('Sala');
		$totem->setPrinterHost($host);
		$totem->setPrinterPort($port);

		return $totem;
	}

	private function ticket(): PrintableTicket {
		return new PrintableTicket('CESFAM Ejemplo', 'FAR-P-014', 'Farmacia', true, '19-10-2026 14:30');
	}

	public function testItSendsTheTicketBytesToTheTotemsPrinter(): void {
		$this->transport->expects($this->once())->method('send')->with('192.168.1.50', 9101, EscPosTicket::render($this->ticket()))->willReturn(true);

		$this->assertTrue($this->printer->print($this->totem(port: 9101), $this->ticket()));
	}

	public function testItRemembersASuccessfulPrintWithItsTime(): void {
		$this->transport->method('send')->willReturn(true);
		$this->totems->expects($this->once())->method('update')->with($this->callback(
			static fn (Totem $t) => $t->getPrinterOk() === true && $t->getPrinterCheckedAt() === 1_792_400_000,
		));

		$this->printer->print($this->totem(), $this->ticket());
	}

	public function testAPrinterThatDoesNotAnswerIsAFailureThatIsRememberedAndLogged(): void {
		$this->transport->method('send')->willReturn(false);
		$this->totems->expects($this->once())->method('update')->with($this->callback(
			static fn (Totem $t) => $t->getPrinterOk() === false && $t->getPrinterCheckedAt() === 1_792_400_000,
		));
		$this->logger->expects($this->once())->method('warning');

		$this->assertFalse($this->printer->print($this->totem(), $this->ticket()));
	}

	public function testItNeverThrowsWhateverGoesWrong(): void {
		$this->transport->method('send')->willThrowException(new \RuntimeException('socket exploded'));
		$this->totems->method('update')->willThrowException(new \RuntimeException('database exploded'));

		$this->assertFalse($this->printer->print($this->totem(), $this->ticket()));
	}

	public function testItDoesNotLoseASuccessfulPrintBecauseTheStatusCouldNotBeSaved(): void {
		$this->transport->method('send')->willReturn(true);
		$this->totems->method('update')->willThrowException(new \RuntimeException('database exploded'));
		$this->logger->expects($this->once())->method('error');

		$this->assertTrue($this->printer->print($this->totem(), $this->ticket()));
	}

	public function testItChecksTheAddressAgainWhenPrintingNotOnlyWhenItWasSaved(): void {
		// A name can be re-pointed after it was configured; an address outside the clinic is never connected to.
		$this->transport->expects($this->never())->method('send');
		$this->logger->expects($this->once())->method('warning');

		$this->assertFalse($this->printer->print($this->totem(host: '8.8.8.8'), $this->ticket()));
	}

	public function testItDoesNotPrintAtAPortThatIsNotAPrintersEither(): void {
		$this->transport->expects($this->never())->method('send');

		$this->assertFalse($this->printer->print($this->totem(port: 22), $this->ticket()));
	}
}
