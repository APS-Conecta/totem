<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Integration;

use OCA\Totem\Db\Totem;
use OCA\Totem\Db\TotemMapper;
use OCA\Totem\Domain\PrintableTicket;
use OCA\Totem\Service\PrinterTransport;
use OCA\Totem\Service\TicketPrinter;
use OCA\Totem\Service\Today;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IDateTimeZone;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * A printer named by a host name whose lookup fails: the instance's real cache remembers the failure for that
 * host for 30 seconds, so a DNS server that does not answer makes at most one print per host wait for it.
 * The clock and the resolver are stand-ins; everything else is the container's.
 */
final class PrinterNameLookupTest extends IntegrationTestCase {
	private const START = 1_792_400_000;

	private int $now = self::START;
	private int $lookups = 0;
	/** @var list<string>|false */
	private array|false $answer = false;
	private string $host;

	protected function setUp(): void {
		parent::setUp();
		// The cache outlives a test, so each test names a printer host no other test has used.
		$this->host = 'impresora-' . bin2hex(random_bytes(6)) . '.clinica';
	}

	private function printer(): TicketPrinter {
		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$resolve = function (string $host): array|false {
			$this->lookups++;

			return $this->answer;
		};

		return new TicketPrinter(
			Server::get(PrinterTransport::class),
			Server::get(TotemMapper::class),
			Server::get(LoggerInterface::class),
			new Today($clock, Server::get(IDateTimeZone::class)),
			Server::get(ICacheFactory::class),
			$resolve,
		);
	}

	private function print(TicketPrinter $printer, Totem $totem): bool {
		return $printer->print($totem, new PrintableTicket('CESFAM Ejemplo', 'FAR-G-001', 'Farmacia', false, '19-10-2026 14:30'));
	}

	private function savedPrinterOk(Totem $totem): ?bool {
		return Server::get(TotemMapper::class)->find($totem->getId())->getPrinterOk();
	}

	public function testRightAfterAFailedLookupThePrintFailsWithoutAskingTheResolverAgain(): void {
		$printer = $this->printer();
		$totem = $this->totem('sala', 'Sala', $this->host);
		$this->assertFalse($this->print($printer, $totem));
		$this->assertSame(1, $this->lookups);

		$this->now = self::START + 29;
		$this->assertFalse($this->print($printer, $totem));

		$this->assertSame(1, $this->lookups, 'the resolver was asked again within 30 seconds of a failed lookup');
		$this->assertFalse($this->savedPrinterOk($totem));
	}

	public function testThirtySecondsAfterAFailedLookupTheNameIsLookedUpAgainAndASuccessIsNotReused(): void {
		$printer = $this->printer();
		$totem = $this->totem('sala', 'Sala', $this->host);
		$this->assertFalse($this->print($printer, $totem));

		// The container's own address on the default port 9100, where nothing listens: the lookup succeeds, the printer is off.
		$this->answer = [(string)gethostbyname((string)gethostname())];
		$this->now = self::START + 30;
		$this->assertFalse($this->print($printer, $totem));
		$this->assertSame(2, $this->lookups);

		$this->now = self::START + 31;
		$this->assertFalse($this->print($printer, $totem));
		$this->assertSame(3, $this->lookups, 'a successful lookup was reused instead of looking the name up again');
	}

	public function testAFailedLookupIsRememberedPerHost(): void {
		$printer = $this->printer();
		$this->assertFalse($this->print($printer, $this->totem('sala', 'Sala', $this->host)));

		$this->assertFalse($this->print($printer, $this->totem('otra', 'Otra', 'otra-' . $this->host)));

		$this->assertSame(2, $this->lookups);
	}
}
