<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Domain;

/**
 * What goes on the paper of a ticket: the same words the totem shows on screen, and nothing about a person
 * (ADR-0004). The moment is already written the way the clinic reads it, in the instance's time zone.
 */
final readonly class PrintableTicket {
	public function __construct(
		public string $establishment,
		public string $label,
		public string $servicio,
		public bool $preferente,
		public string $issuedOn,
	) {
	}
}
