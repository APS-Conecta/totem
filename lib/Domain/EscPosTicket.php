<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Domain;

/**
 * A ticket as the bytes an ESC/POS thermal printer (80 mm: Epson, Star in ESC/POS mode and most generic ones)
 * understands: the establishment, the label as big as the paper allows, the unidad, "ATENCIÓN PREFERENTE" when
 * it applies, the moment, what to do next, and a cut. Pure: bytes in, bytes out; sending them is another
 * class's job.
 *
 * Text is sent in code page 850 (so Spanish letters print), anything the printer cannot print becomes a
 * question mark, and control characters are stripped from every field: names are typed by whoever
 * configures the app, and must not be able to talk to the printer.
 */
final class EscPosTicket {
	/** Characters per line of an 80 mm roll at the normal size. */
	private const WIDTH = 48;

	private const INITIALIZE = "\x1B\x40";
	private const CODE_PAGE_850 = "\x1B\x74\x02";
	private const CENTER = "\x1B\x61\x01";
	private const SIZE_HUGE = "\x1D\x21\x33";
	private const SIZE_DOUBLE = "\x1D\x21\x11";
	private const SIZE_NORMAL = "\x1D\x21\x00";
	private const FEED_AND_CUT = "\x1D\x56\x42\x03";

	private const WAIT = ['Espere su llamado en la sala de espera.', 'Mire la pantalla y escuche el aviso.'];

	public static function render(PrintableTicket $ticket): string {
		$out = self::INITIALIZE . self::CODE_PAGE_850 . self::CENTER;
		$out .= self::lines($ticket->establishment) . "\n";
		$out .= self::SIZE_HUGE . self::text($ticket->label) . "\n" . self::SIZE_NORMAL . "\n";
		$out .= self::lines($ticket->servicio);
		if ($ticket->preferente) {
			$out .= self::SIZE_DOUBLE . self::text('ATENCIÓN PREFERENTE') . "\n" . self::SIZE_NORMAL;
		}
		$out .= "\n" . self::text($ticket->issuedOn) . "\n\n";
		foreach (self::WAIT as $sentence) {
			$out .= self::lines($sentence);
		}

		return $out . "\n\n" . self::FEED_AND_CUT;
	}

	/** The text wrapped to the paper, one printed line each, every line ended by a line feed. */
	private static function lines(string $text): string {
		$out = '';
		foreach (self::wrap(self::clean($text)) as $line) {
			$out .= self::convert($line) . "\n";
		}

		return $out;
	}

	private static function text(string $text): string {
		return self::convert(self::clean($text));
	}

	/** Control characters out, so a name can never be a printer command. */
	private static function clean(string $text): string {
		return trim((string)preg_replace('/[\x00-\x1F\x7F]/', '', $text));
	}

	/** UTF-8 to code page 850; a letter the printer lacks becomes "?". */
	private static function convert(string $text): string {
		return mb_convert_encoding($text, 'CP850', 'UTF-8');
	}

	/** @return list<string> greedy word wrap at the paper's width; a word longer than a line is cut */
	private static function wrap(string $text): array {
		$lines = [];
		$line = '';
		foreach (preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
			foreach (mb_str_split($word, self::WIDTH) as $piece) {
				if ($line !== '' && mb_strlen($line) + 1 + mb_strlen($piece) > self::WIDTH) {
					$lines[] = $line;
					$line = $piece;
				} else {
					$line = $line === '' ? $piece : $line . ' ' . $piece;
				}
			}
		}
		if ($line !== '') {
			$lines[] = $line;
		}

		return $lines;
	}
}
