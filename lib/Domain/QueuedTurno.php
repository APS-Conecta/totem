<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Domain;

/** A turno still waiting, reduced to what choosing the next call needs. */
final class QueuedTurno {
	public function __construct(
		public readonly int $id,
		public readonly int $servicioId,
		public readonly bool $preferente,
		public readonly int $createdAt,
	) {
	}
}
