<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Domain;

/**
 * Where the server may send a ticket. The address is typed by whoever configures a totem, and an anonymous
 * visitor makes the server connect to it, so it is held to what a clinic's ticket printer is: a device on the
 * private network, on a JetDirect port. That keeps the feature from being a way to probe loopback services,
 * cloud metadata endpoints, other hosts on the Internet or arbitrary ports (docs/threat-model.md).
 *
 * Pure: the name resolver is passed in, so a test gives it a fake.
 */
final class PrinterAddress {
	private const NETWORK = 'La impresora debe estar en la red de la clínica (direcciones 10.x, 172.16 a 172.31 o 192.168.x).';
	private const NOT_FOUND = 'No se encontró la dirección de la impresora.';
	private const PORT = 'El puerto de la impresora debe estar entre 9100 y 9109.';
	private const FIRST_PORT = 9100;
	private const LAST_PORT = 9109;

	/** The private IPv4 ranges (RFC 1918) as [first, last] of the address read as an unsigned integer. */
	private const PRIVATE_RANGES = [
		['10.0.0.0', '10.255.255.255'],
		['172.16.0.0', '172.31.255.255'],
		['192.168.0.0', '192.168.255.255'],
	];

	/**
	 * The Spanish sentence saying what is wrong with the address, or null when the server may print there.
	 *
	 * @param ?callable(string): (list<string>|false) $resolve host name to IPv4 addresses; gethostbynamel by default
	 */
	public static function problem(string $host, int $port, ?callable $resolve = null): ?string {
		return self::check($host, $port, $resolve)[1];
	}

	/**
	 * From one lookup, either the IPv4 address the server may connect to or the Spanish sentence saying why not.
	 *
	 * @param ?callable(string): (list<string>|false) $resolve host name to IPv4 addresses; gethostbynamel by default
	 * @return array{0: string, 1: null}|array{0: null, 1: string}
	 */
	public static function check(string $host, int $port, ?callable $resolve = null): array {
		$host = trim($host);
		[$address, $problem] = self::hostAddress($host, $resolve ?? gethostbynamel(...));
		if ($problem !== null) {
			return [null, $problem];
		}

		return $port >= self::FIRST_PORT && $port <= self::LAST_PORT ? [$address, null] : [null, self::PORT];
	}

	/** @return array{0: string, 1: null}|array{0: null, 1: string} */
	private static function hostAddress(string $host, callable $resolve): array {
		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			return self::isPrivate($host) ? [$host, null] : [null, self::NETWORK];
		}
		if (strlen($host) > 253 || preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/', $host) !== 1) {
			return [null, self::NOT_FOUND];
		}
		$addresses = $resolve($host);
		if ($addresses === false || $addresses === []) {
			return [null, self::NOT_FOUND];
		}
		foreach ($addresses as $address) {
			if (!self::isPrivate($address)) {
				return [null, self::NETWORK];
			}
		}

		return [$addresses[0], null];
	}

	private static function isPrivate(string $address): bool {
		if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
			return false;
		}
		$ip = ip2long($address);
		foreach (self::PRIVATE_RANGES as [$first, $last]) {
			if ($ip >= ip2long($first) && $ip <= ip2long($last)) {
				return true;
			}
		}

		return false;
	}
}
