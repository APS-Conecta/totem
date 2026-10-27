<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Service;

use OCA\Totem\Db\Totem;
use OCA\Totem\Db\TotemMapper;
use OCA\Totem\Domain\EscPosTicket;
use OCA\Totem\Domain\PrintableTicket;
use OCA\Totem\Domain\PrinterAddress;
use Psr\Log\LoggerInterface;

/**
 * Prints a ticket at a totem's network printer and remembers whether it worked, so staff can see a printer
 * that stopped answering. Printing is a side effect of issuing a turno, so this class never throws: a printer
 * that is off, a socket error or a database error all end as `false`, logged, and the turno stands.
 */
class TicketPrinter {
	/** @var \Closure(string): (list<string>|false)|null */
	private ?\Closure $resolve;

	/** @param ?callable(string): (list<string>|false) $resolve host name to IPv4 addresses; the system resolver by default */
	public function __construct(
		private PrinterTransport $transport,
		private TotemMapper $totems,
		private LoggerInterface $logger,
		private Today $today,
		?callable $resolve = null,
	) {
		$this->resolve = $resolve === null ? null : $resolve(...);
	}

	/** True when the printer accepted the ticket. */
	public function print(Totem $totem, PrintableTicket $ticket): bool {
		$host = (string)$totem->getPrinterHost();
		$port = $totem->getPrinterPort();
		$sent = false;
		try {
			// Checked at every print, not only when saved, and the connection goes to the address that check approved.
			[$address, $problem] = PrinterAddress::check($host, $port, $this->resolve);
			if ($address !== null) {
				$sent = $this->transport->send($address, $port, EscPosTicket::render($ticket));
			}
			if (!$sent) {
				$this->logger->warning('Totem ticket not printed', ['totem' => $totem->getSlug(), 'host' => $host, 'port' => $port, 'reason' => $problem ?? 'the printer did not answer']);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Totem ticket not printed', ['totem' => $totem->getSlug(), 'exception' => $e]);
			$sent = false;
		}
		$this->remember($totem, $sent);

		return $sent;
	}

	private function remember(Totem $totem, bool $ok): void {
		try {
			$totem->setPrinterOk($ok);
			$totem->setPrinterCheckedAt($this->today->now());
			$this->totems->update($totem);
		} catch (\Throwable $e) {
			$this->logger->error('Totem printer status not saved', ['totem' => $totem->getSlug(), 'exception' => $e]);
		}
	}
}
