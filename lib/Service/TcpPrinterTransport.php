<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Service;

/**
 * Raw printing: a network ticket printer is a TCP server (conventionally on port 9100) that prints what it
 * reads. The timeouts are short on purpose: an anonymous patient is waiting for the totem, and a printer that
 * is off must cost a second, not a minute.
 */
class TcpPrinterTransport implements PrinterTransport {
	public function __construct(
		private float $connectTimeout = 1.0,
		private float $writeTimeout = 2.0,
	) {
	}

	public function send(string $host, int $port, string $bytes): bool {
		$socket = @stream_socket_client(sprintf('tcp://%s:%d', $host, $port), $errorCode, $error, $this->connectTimeout);
		if ($socket === false) {
			return false;
		}
		try {
			stream_set_timeout($socket, (int)$this->writeTimeout, (int)(fmod($this->writeTimeout, 1) * 1_000_000));
			for ($rest = $bytes; $rest !== ''; $rest = (string)substr($rest, $written)) {
				$written = @fwrite($socket, $rest);
				if ($written === false || $written === 0) {
					return false;
				}
			}

			return true;
		} finally {
			fclose($socket);
		}
	}
}
