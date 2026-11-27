<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Integration;

use OCA\Totem\Db\Totem;
use OCA\Totem\Db\TotemMapper;
use OCA\Totem\Domain\PrintableTicket;
use OCA\Totem\Service\PrinterTransport;
use OCA\Totem\Service\TicketPrinter;
use OCP\ICacheFactory;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * A printer named by a host name whose lookup fails: the instance's real cache remembers the failure for that
 * host for 30 seconds, so a DNS server that does not answer makes at most one print per host wait for it.
 * The clock and the resolver are stand-ins; everything else is the container's.
 */
final class PrinterNameLookupTest extends IntegrationTestCase {
	private const START = 1_792_400_000;

	private int $lookups = 0;
	/** @var \Closure(): (list<string>|false) what the resolver answers */
	private \Closure $answer;
	private string $host;

	protected function setUp(): void {
		parent::setUp();
		$this->now = self::START;
		$this->answer = fn (): false => false;
		// The cache outlives a test, so each test names a printer host no other test has used.
		$this->host = 'impresora-' . bin2hex(random_bytes(6)) . '.clinica';
	}

	private function printer(): TicketPrinter {
		$resolve = function (string $host): array|false {
			$this->lookups++;

			return ($this->answer)();
		};

		return new TicketPrinter(
			Server::get(PrinterTransport::class),
			Server::get(TotemMapper::class),
			Server::get(LoggerInterface::class),
			$this->today(),
			Server::get(ICacheFactory::class),
			$resolve,
		);
	}

	private function print(TicketPrinter $printer, Totem $totem): bool {
		return $printer->print($totem, new PrintableTicket('CESFAM Ejemplo', 'FAR-G-001', 'Farmacia', false, '19-10-2026 14:30'));
	}

	/** The container's own address: private, and nothing listens on the default port 9100, so the lookup succeeds and the printer is off. */
	private function ownAddress(): string {
		return (string)gethostbyname((string)gethostname());
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

		$this->answer = fn (): array => [$this->ownAddress()];
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

	public function testALookupThatSucceedsClearsTheFailureRememberedForItsHost(): void {
		$printer = $this->printer();
		$sala = $this->totem('sala', 'Sala', $this->host);
		$otra = $this->totem('otra', 'Otra', $this->host);
		// While the resolver answers Sala's lookup, Otra prints at the same host and its lookup fails at START.
		$this->answer = function () use ($printer, $otra): array {
			$this->answer = fn (): false => false;
			$this->assertFalse($this->print($printer, $otra));

			return [$this->ownAddress()];
		};
		$this->assertFalse($this->print($printer, $sala));
		$this->assertSame(2, $this->lookups);

		$this->now = self::START + 1;
		$this->assertFalse($this->print($printer, $sala));

		$this->assertSame(3, $this->lookups, 'a failure remembered before a successful lookup of the same host still blocked the resolver');
	}
}
