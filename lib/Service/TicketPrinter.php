<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Service;

use OCA\Totem\Db\Totem;
use OCA\Totem\Db\TotemMapper;
use OCA\Totem\Domain\EscPosTicket;
use OCA\Totem\Domain\PrintableTicket;
use OCA\Totem\Domain\PrinterAddress;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Prints a ticket at a totem's network printer and remembers whether it worked, so staff can see a printer
 * that stopped answering. Printing is a side effect of issuing a turno, so this class never throws: a printer
 * that is off, a socket error or a database error all end as `false`, logged, and the turno stands.
 *
 * A printer name is looked up at every print, and the system resolver has no time limit, so a failed lookup is
 * remembered per host for {@see self::FAILED_LOOKUP_SECONDS} seconds and the prints in that window fail without
 * asking again: a DNS server that does not answer makes one print per host wait for it, not every patient.
 * Only failures are remembered; a successful lookup is never reused (ADR-0009).
 */
class TicketPrinter {
	private const FAILED_LOOKUP_SECONDS = 30;

	/** @var \Closure(string): (list<string>|false) */
	private \Closure $resolve;
	private ICache $failedLookups;

	/** @param ?callable(string): (list<string>|false) $resolve host name to IPv4 addresses; the system resolver by default */
	public function __construct(
		private PrinterTransport $transport,
		private TotemMapper $totems,
		private LoggerInterface $logger,
		private Today $today,
		ICacheFactory $caches,
		?callable $resolve = null,
	) {
		$this->resolve = ($resolve ?? gethostbynamel(...))(...);
		$this->failedLookups = $caches->createDistributed('totem-printer-lookup');
	}

	/** True when the printer accepted the ticket. */
	public function print(Totem $totem, PrintableTicket $ticket): bool {
		$host = (string)$totem->getPrinterHost();
		$port = $totem->getPrinterPort();
		$sent = false;
		try {
			// Checked at every print, not only when saved, and the connection goes to the address that check approved.
			[$address, $problem] = PrinterAddress::check($host, $port, $this->lookUp(...));
			if ($address !== null) {
				$sent = $this->transport->send($address, $port, EscPosTicket::render($ticket));
			}
			if (!$sent) {
				$this->logger->warning('Totem ticket not printed', ['totem' => $totem->getSlug(), 'host' => $host, 'address' => $address, 'port' => $port, 'reason' => $problem ?? 'the printer did not answer']);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Totem ticket not printed', ['totem' => $totem->getSlug(), 'exception' => $e]);
			$sent = false;
		}
		$this->remember($totem, $sent);

		return $sent;
	}

	/**
	 * The resolver, unless it failed for this name in the last FAILED_LOOKUP_SECONDS.
	 *
	 * @return list<string>|false
	 */
	private function lookUp(string $name): array|false {
		$now = $this->today->now();
		$failedAt = $this->failedLookups->get($name);
		if (is_int($failedAt) && $now - $failedAt < self::FAILED_LOOKUP_SECONDS) {
			return false;
		}
		$addresses = ($this->resolve)($name);
		if ($addresses === false || $addresses === []) {
			$this->failedLookups->set($name, $now, self::FAILED_LOOKUP_SECONDS);
		} else {
			$this->failedLookups->remove($name);
		}

		return $addresses;
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
