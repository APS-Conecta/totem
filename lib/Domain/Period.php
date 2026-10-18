<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Domain;

/**
 * A day, a Monday-to-Sunday week or a calendar month, as the inclusive range of
 * `Y-m-d` days the statistics board counts. Days are the instance's local days
 * (the same strings `totem_turno.day` stores), so this does no time-zone work.
 */
final class Period {
	private const KINDS = ['day', 'week', 'month'];

	private function __construct(
		private string $kind,
		private \DateTimeImmutable $from,
		private \DateTimeImmutable $to,
	) {
	}

	/** @throws \InvalidArgumentException on an unknown kind or a date that is not a real Y-m-d */
	public static function of(string $kind, string $anchor): self {
		if (!in_array($kind, self::KINDS, true)) {
			throw new \InvalidArgumentException("Unknown period kind: $kind");
		}
		$day = \DateTimeImmutable::createFromFormat('!Y-m-d', $anchor);
		if ($day === false || $day->format('Y-m-d') !== $anchor) {
			throw new \InvalidArgumentException("Not a Y-m-d date: $anchor");
		}

		return match ($kind) {
			'day' => new self($kind, $day, $day),
			'week' => new self($kind, $day->modify('monday this week'), $day->modify('sunday this week')),
			'month' => new self($kind, $day->modify('first day of this month'), $day->modify('last day of this month')),
		};
	}

	public function kind(): string {
		return $this->kind;
	}

	public function from(): string {
		return $this->from->format('Y-m-d');
	}

	public function to(): string {
		return $this->to->format('Y-m-d');
	}

	/** @return list<string> every day of the period, oldest first */
	public function days(): array {
		$days = [];
		for ($day = $this->from; $day <= $this->to; $day = $day->modify('+1 day')) {
			$days[] = $day->format('Y-m-d');
		}

		return $days;
	}

	/** The period `$steps` periods away (negative = earlier). A month step starts from the 1st, so it never skips a short month. */
	public function shift(int $steps): self {
		$unit = ['day' => 'day', 'week' => 'week', 'month' => 'month'][$this->kind];
		$moved = $this->from->modify(sprintf('%+d %s', $steps, $unit));

		return self::of($this->kind, $moved->format('Y-m-d'));
	}

	/** True when it begins after `$today` (a `Y-m-d`): there is nothing to count yet. */
	public function startsAfter(string $today): bool {
		return $this->from() > $today;
	}
}
