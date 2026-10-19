<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Integration;

use OCA\Totem\Db\Servicio;
use OCA\Totem\Db\ServicioMapper;
use OCA\Totem\Db\Totem;
use OCA\Totem\Db\TotemMapper;
use OCA\Totem\Db\TurnoMapper;
use OCA\Totem\Service\TurnoService;
use OCP\Server;

/**
 * Taking a ticket at a totem whose printer is on the network, end to end inside the Nextcloud container:
 * dependency injection, the database, the address check and a real TCP connection to a listener that plays
 * the printer. The container's own private address is a legitimate printer address, loopback is not.
 */
final class IssueAndPrintTest extends IntegrationTestCase {
	/** @var resource|null */
	private $printer;
	private string $address;

	protected function setUp(): void {
		parent::setUp();
		$this->address = (string)gethostbyname((string)gethostname());
	}

	protected function tearDown(): void {
		if (is_resource($this->printer)) {
			fclose($this->printer);
		}
		parent::tearDown();
	}

	private function listen(int $port): void {
		$this->printer = stream_socket_server("tcp://0.0.0.0:$port", $errorCode, $error);
		$this->assertNotFalse($this->printer, "could not play the printer: $error");
	}

	private function servicio(): Servicio {
		$s = new Servicio();
		$s->setSlug('far');
		$s->setName('Farmacia');
		$s->setPrefix('FAR');
		$s->setColor('#0b6e69');
		$s->setOpens('00:00');
		$s->setCloses('23:59');
		$s->setActive(true);

		return Server::get(ServicioMapper::class)->insert($s);
	}

	private function totem(?string $host, int $port): Totem {
		$totem = new Totem();
		$totem->setSlug('sala');
		$totem->setName('Sala');
		$totem->setPrinterHost($host);
		$totem->setPrinterPort($port);

		return Server::get(TotemMapper::class)->insert($totem);
	}

	public function testTheTicketReachesThePrinterAndTheStatusSaysSo(): void {
		$this->assertStringStartsNotWith('127.', $this->address, 'the container has no private address of its own');
		$this->listen(9105);
		$servicio = $this->servicio();
		$totem = $this->totem($this->address, 9105);

		$issued = Server::get(TurnoService::class)->issue($servicio->getId(), true, 'sala');

		$this->assertTrue($issued['printed']);
		$client = stream_socket_accept($this->printer, 2);
		$this->assertNotFalse($client);
		$bytes = (string)stream_get_contents($client);
		$this->assertStringContainsString('FAR-P-001', $bytes);
		$this->assertStringContainsString('Farmacia', $bytes);
		$this->assertStringContainsString('ATENCI' . "\xE0" . 'N PREFERENTE', $bytes);
		$this->assertStringEndsWith("\x1D\x56\x42\x03", $bytes);
		$saved = Server::get(TotemMapper::class)->find($totem->getId());
		$this->assertTrue($saved->getPrinterOk());
		$this->assertNotNull($saved->getPrinterCheckedAt());
	}

	public function testAPrinterThatIsOffNeverStopsTheTurnoAndTheStatusSaysSo(): void {
		$servicio = $this->servicio();
		$totem = $this->totem($this->address, 9106);

		$issued = Server::get(TurnoService::class)->issue($servicio->getId(), false, 'sala');

		$this->assertFalse($issued['printed']);
		$this->assertSame('FAR-G-001', $issued['label']);
		$this->assertSame('waiting', Server::get(TurnoMapper::class)->find($issued['id'])->getEstado());
		$saved = Server::get(TotemMapper::class)->find($totem->getId());
		$this->assertFalse($saved->getPrinterOk());
	}

	public function testALoopbackPrinterIsNeverConnectedTo(): void {
		$this->listen(9107);
		$servicio = $this->servicio();
		$this->totem('127.0.0.1', 9107);

		$issued = Server::get(TurnoService::class)->issue($servicio->getId(), false, 'sala');

		$this->assertFalse($issued['printed']);
		stream_set_blocking($this->printer, false);
		$this->assertFalse(@stream_socket_accept($this->printer, 0), 'the server connected to loopback');
	}
}
