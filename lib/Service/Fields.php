<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Service;

use OCA\Totem\Exception\Refused;

/**
 * The rules for what an admin may type into a servicio, box or pantalla, one
 * place so every form says the same thing the same way. Each method returns the
 * clean value or refuses with the sentence the form shows. Pure: bare PHP.
 *
 * $label is the field's name with its article ("El nombre"), so the sentence
 * reads right in Spanish.
 */
final class Fields {
	public static function text(mixed $value, string $label, int $max): string {
		$text = trim((string)$value);
		if ($text === '') {
			throw new Refused("$label no puede quedar vacío.");
		}
		if (mb_strlen($text) > $max) {
			throw new Refused("$label admite hasta $max caracteres.");
		}

		return $text;
	}

	/** Up to four ASCII letters or digits, uppercased: what fits a ticket and a display row. */
	public static function prefix(mixed $value): string {
		$prefix = strtoupper(trim((string)$value));
		if (preg_match('/^[A-Z0-9]{1,4}$/', $prefix) !== 1) {
			throw new Refused('El prefijo debe tener de 1 a 4 letras o números.');
		}

		return $prefix;
	}

	/** HH:MM, 24 hours. */
	public static function time(mixed $value, string $label): string {
		$time = trim((string)$value);
		if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
			throw new Refused("$label debe ser una hora como 07:30.");
		}

		return $time;
	}

	public static function color(mixed $value): string {
		$color = strtolower(trim((string)$value));
		if (preg_match('/^#[0-9a-f]{6}$/', $color) !== 1) {
			throw new Refused('El color debe ser como #0b6e69.');
		}

		return $color;
	}

	/** Null (or blank) means no daily limit. */
	public static function quota(mixed $value): ?int {
		if ($value === null || $value === '') {
			return null;
		}
		if (!is_numeric($value) || (string)(int)$value !== (string)$value || (int)$value < 1) {
			throw new Refused('El cupo diario debe ser un número mayor que cero, o quedar vacío.');
		}

		return (int)$value;
	}

	public static function volume(mixed $value): int {
		if (!is_numeric($value) || (string)(int)$value !== (string)$value || (int)$value < 0 || (int)$value > 100) {
			throw new Refused('El volumen debe estar entre 0 y 100.');
		}

		return (int)$value;
	}

	public static function voice(mixed $value): string {
		if (!in_array($value, ['mujer', 'hombre', 'off'], true)) {
			throw new Refused('La voz debe ser Mujer, Hombre o Sin voz.');
		}

		return $value;
	}

	/** @return list<int> distinct, in the order given */
	public static function ids(mixed $value): array {
		return array_values(array_unique(array_map('intval', is_array($value) ? $value : [])));
	}
}
