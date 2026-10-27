<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Service;

use OCA\Totem\Service\PrinterTransport;
use OCA\Totem\Service\TcpPrinterTransport;
use PHPUnit\Framework\TestCase;

/** Against a real listener in this process: a ticket printer is a TCP server that reads bytes and prints them. */
class TcpPrinterTransportTest extends TestCase {
	/** @var resource|null */
	private $server;

	protected function tearDown(): void {
		if (is_resource($this->server)) {
			fclose($this->server);
		}
	}

	/** @return int the port of a listener that has not been asked anything yet */
	private function listen(): int {
		$this->server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
		$this->assertNotFalse($this->server, "could not listen: $error");

		return (int)substr((string)strrchr((string)stream_socket_get_name($this->server, false), ':'), 1);
	}

	private function received(): string {
		$client = stream_socket_accept($this->server, 2);
		$this->assertNotFalse($client, 'nothing connected to the printer');
		$bytes = (string)stream_get_contents($client);
		fclose($client);

		return $bytes;
	}

	public function testItIsAPrinterTransport(): void {
		$this->assertInstanceOf(PrinterTransport::class, new TcpPrinterTransport());
	}

	public function testItDeliversEveryByteToThePrinter(): void {
		$port = $this->listen();
		$ticket = "\x1B\x40FAR-P-014\n" . str_repeat("\xA2", 20_000) . "\x1D\x56\x42\x03";

		$sent = (new TcpPrinterTransport())->send('127.0.0.1', $port, $ticket);

		$this->assertTrue($sent);
		$this->assertSame($ticket, $this->received());
	}

	public function testAPrinterThatRefusesTheConnectionIsAFailureNotAnException(): void {
		$port = $this->listen();
		fclose($this->server);
		$this->server = null;

		$this->assertFalse((new TcpPrinterTransport())->send('127.0.0.1', $port, 'x'));
	}

	public function testAnAddressNobodyAnswersFailsQuicklyInsteadOfHangingTheTicket(): void {
		$started = microtime(true);

		$sent = (new TcpPrinterTransport(connectTimeout: 0.3, writeTimeout: 0.3))->send('10.255.255.1', 9100, 'x');

		$this->assertFalse($sent);
		$this->assertLessThan(2.0, microtime(true) - $started);
	}

	public function testSomethingThatIsNotAHostIsAFailure(): void {
		$this->assertFalse((new TcpPrinterTransport(connectTimeout: 0.3))->send('not a host', 9100, 'x'));
	}

	public function testAHostThatIsNotAnIPv4AddressIsNeverConnectedTo(): void {
		$port = $this->listen();

		$this->assertFalse((new TcpPrinterTransport(connectTimeout: 0.3))->send('localhost', $port, 'x'));
		$this->assertFalse(@stream_socket_accept($this->server, 0.2));
	}
}
