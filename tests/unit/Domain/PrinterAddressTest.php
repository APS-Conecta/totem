<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Domain;

use OCA\Totem\Domain\PrinterAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PrinterAddressTest extends TestCase {
	private const NETWORK = 'La impresora debe estar en la red de la clínica (direcciones 10.x, 172.16 a 172.31 o 192.168.x).';
	private const NOT_FOUND = 'No se encontró la dirección de la impresora.';
	private const PORT = 'El puerto de la impresora debe estar entre 9100 y 9109.';

	/** @param list<string>|false $answer */
	private static function resolver(array|false $answer): \Closure {
		return static fn (string $host): array|false => $answer;
	}

	/** @return array<string, array{string}> */
	public static function privateAddresses(): array {
		return [
			'the start of 10/8' => ['10.0.0.1'],
			'the end of 10/8' => ['10.255.255.254'],
			'the start of 172.16/12' => ['172.16.0.5'],
			'the end of 172.16/12' => ['172.31.255.254'],
			'192.168/16' => ['192.168.1.50'],
		];
	}

	#[DataProvider('privateAddresses')]
	public function testAPrivateAddressIsAccepted(string $host): void {
		$this->assertNull(PrinterAddress::problem($host, 9100, self::resolver(false)));
	}

	/** @return array<string, array{string}> */
	public static function addressesOutsideTheClinic(): array {
		return [
			'loopback' => ['127.0.0.1'],
			'link-local (cloud metadata)' => ['169.254.169.254'],
			'a public address' => ['8.8.8.8'],
			'just below 172.16/12' => ['172.15.255.255'],
			'just above 172.16/12' => ['172.32.0.1'],
			'unspecified' => ['0.0.0.0'],
			'carrier-grade NAT' => ['100.64.0.1'],
			'an IPv6 address' => ['fd00::1'],
			'IPv6 loopback' => ['::1'],
		];
	}

	#[DataProvider('addressesOutsideTheClinic')]
	public function testAnAddressOutsideTheClinicNetworkIsRefused(string $host): void {
		$this->assertSame(self::NETWORK, PrinterAddress::problem($host, 9100, self::resolver(false)));
	}

	public function testANameThatResolvesToPrivateAddressesIsAccepted(): void {
		$this->assertNull(PrinterAddress::problem('impresora-sala', 9100, self::resolver(['192.168.1.20'])));
		$this->assertNull(PrinterAddress::problem('printer', 9100, self::resolver(['172.18.0.4', '10.0.0.4'])));
	}

	public function testANameThatResolvesAnywhereOutsideIsRefusedEvenIfOneAnswerIsPrivate(): void {
		$this->assertSame(self::NETWORK, PrinterAddress::problem('mixed.example', 9100, self::resolver(['192.168.1.20', '8.8.8.8'])));
		$this->assertSame(self::NETWORK, PrinterAddress::problem('localhost', 9100, self::resolver(['127.0.0.1'])));
	}

	public function testANameThatDoesNotResolveIsRefusedWithASentence(): void {
		$this->assertSame(self::NOT_FOUND, PrinterAddress::problem('no-existe', 9100, self::resolver(false)));
		$this->assertSame(self::NOT_FOUND, PrinterAddress::problem('no-existe', 9100, self::resolver([])));
	}

	/** @return array<string, array{string}> */
	public static function notHostnames(): array {
		return [
			'empty' => [''],
			'blank' => ['   '],
			'a URL' => ['http://192.168.1.5'],
			'a path' => ['192.168.1.5/admin'],
			'a port glued on' => ['192.168.1.5:9100'],
			'with a space' => ['mi impresora'],
			'a leading dash' => ['-printer'],
		];
	}

	#[DataProvider('notHostnames')]
	public function testSomethingThatIsNotAHostIsRefused(string $host): void {
		$this->assertSame(self::NOT_FOUND, PrinterAddress::problem($host, 9100, self::resolver(['192.168.1.20'])));
	}

	/** @return array<string, array{int, bool}> */
	public static function ports(): array {
		return [
			'the first JetDirect port' => [9100, true],
			'the last JetDirect port' => [9109, true],
			'just below' => [9099, false],
			'just above' => [9110, false],
			'a web port' => [80, false],
			'ssh' => [22, false],
			'zero' => [0, false],
			'out of range' => [70000, false],
		];
	}

	#[DataProvider('ports')]
	public function testOnlyThePrintersOwnPortsAreAllowed(int $port, bool $allowed): void {
		$this->assertSame($allowed ? null : self::PORT, PrinterAddress::problem('192.168.1.20', $port, self::resolver(false)));
	}

	public function testTheAddressIsCheckedBeforeThePort(): void {
		$this->assertSame(self::NETWORK, PrinterAddress::problem('8.8.8.8', 22, self::resolver(false)));
	}
}
