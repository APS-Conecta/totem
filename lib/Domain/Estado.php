<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Domain;

/**
 * Where a turno is in its day. The stored value is the column's value.
 *
 * The one path is waiting -> called -> in_service -> completed; a called turno
 * that never shows becomes no_show. Nothing goes back and nothing is skipped: a
 * wrong servicio or preferente is not edited, the patient takes a new turno
 * (CONTEXT.md, "No se presentó").
 */
enum Estado: string {
	case Waiting = 'waiting';
	case Called = 'called';
	case InService = 'in_service';
	case Completed = 'completed';
	case NoShow = 'no_show';

	public function canBecome(self $next): bool {
		return match ($this) {
			self::Waiting => $next === self::Called,
			self::Called => $next === self::InService || $next === self::NoShow,
			self::InService => $next === self::Completed,
			self::Completed, self::NoShow => false,
		};
	}
}
