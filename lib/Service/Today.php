<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;

/**
 * The clock as the clinic reads it: the day, hour and minute in the instance's
 * time zone, never UTC (a turno taken at 23:30 in Santiago belongs to that
 * day). One class so every service scopes "today" the same way and a test can
 * hand it a fixed moment.
 */
class Today {
	public function __construct(
		private ITimeFactory $time,
		private IDateTimeZone $zone,
	) {
	}

	/** Unix seconds. */
	public function now(): int {
		return $this->time->getTime();
	}

	/** Y-m-d in the instance's time zone. */
	public function day(): string {
		return $this->local()->format('Y-m-d');
	}

	/**
	 * The Día's Unix-second window, [start, end), in the instance's time zone. A calendar day, not
	 * 86400 seconds: on the night the clocks change, a Día lasts 23 or 25 hours.
	 *
	 * @return array{int, int}
	 */
	public function dayWindow(string $day): array {
		$start = new \DateTimeImmutable("$day 00:00:00", $this->zone->getDefaultTimeZone());

		return [$start->getTimestamp(), $start->modify('+1 day')->getTimestamp()];
	}

	/** The moment as the clinic writes it on a ticket, DD-MM-YYYY HH:MM, in the instance's time zone. */
	public function stamp(): string {
		return $this->local()->format('d-m-Y H:i');
	}

	/** HH:MM in the instance's time zone. */
	public function time(): string {
		return $this->local()->format('H:i');
	}

	private function local(): \DateTimeImmutable {
		return (new \DateTimeImmutable('@' . $this->now()))->setTimezone($this->zone->getDefaultTimeZone());
	}
}
