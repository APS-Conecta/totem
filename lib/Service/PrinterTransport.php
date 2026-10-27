<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Service;

/** The one thing a ticket printer connection does: take bytes to an address. A seam, so printing is testable without a printer. */
interface PrinterTransport {
	/**
	 * True when every byte was handed to the printer. Never throws: a printer that is off is an answer, not an error.
	 * The host is an IPv4 address; anything else returns false without connecting.
	 */
	public function send(string $host, int $port, string $bytes): bool;
}
