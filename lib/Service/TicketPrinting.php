<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Service;

use OCA\Totem\Db\Totem;
use OCA\Totem\Domain\PrintableTicket;
use OCP\Defaults;

/**
 * Puts the words on a ticket and hands it to the totem's printer: the establishment (the instance's name, one
 * establishment per instance), the label, the unidad and the moment in the clinic's time zone. Used when a
 * turno is issued and by the "Probar impresión" button, so both print the same paper.
 */
class TicketPrinting {
	public function __construct(
		private TicketPrinter $printer,
		private Defaults $defaults,
		private Today $today,
	) {
	}

	/** True when the printer accepted the ticket of the turno just issued. */
	public function printTurno(Totem $totem, string $label, string $servicio, bool $preferente): bool {
		return $this->printer->print($totem, new PrintableTicket($this->defaults->getName(), $label, $servicio, $preferente, $this->today->stamp()));
	}

	/** A sample ticket: it takes no turno, and says it is a test. */
	public function printTest(Totem $totem): bool {
		return $this->printTurno($totem, 'PRUEBA', 'Ticket de prueba', false);
	}
}
