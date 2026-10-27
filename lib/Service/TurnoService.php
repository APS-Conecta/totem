<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Service;

use OCA\Totem\Db\ServicioLinks;
use OCA\Totem\Db\ServicioMapper;
use OCA\Totem\Db\Totem;
use OCA\Totem\Db\TotemMapper;
use OCA\Totem\Db\Turno;
use OCA\Totem\Db\TurnoMapper;
use OCA\Totem\Domain\Estado;
use OCA\Totem\Domain\TurnoLabel;
use OCA\Totem\Exception\Refused;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;

/**
 * Issuing a turno: what the totem does with its two taps. The public totem is
 * anonymous and identifies nobody, so the only guards are the servicio's own:
 * active, inside its hours, under its daily quota (docs/threat-model.md).
 *
 * A turno may be taken at a totem. If that totem has a network printer the ticket is printed there once the
 * turno is stored; whatever happens to the printer, the turno stands (printing can only add `printed: false`).
 */
class TurnoService {
	/**
	 * Issuing is MAX(seq)+1 with the unique index as the net; this many tries before giving up.
	 * The quota is counted again after the insert.
	 */
	private const ATTEMPTS = 3;

	private const QUOTA_REACHED = '%s ya entregó todos los turnos de hoy.';

	public function __construct(
		private ServicioMapper $servicios,
		private TurnoMapper $turnos,
		private Today $today,
		private TotemMapper $totems,
		private ServicioLinks $links,
		private TicketPrinting $printing,
	) {
	}

	/**
	 * @param ?string $totem the slug of the totem the turno is taken at; null = the default kiosk
	 * @return array{id: int, label: string, servicio: string, preferente: bool, printed: ?bool} `printed` is null when
	 *         there is no network printer (the browser prints), true or false when the server tried
	 * @throws DoesNotExistException the servicio is unknown
	 * @throws Refused the servicio cannot issue a turno right now, or the totem does not offer it
	 */
	public function issue(int $servicioId, bool $preferente, ?string $totem = null): array {
		$servicio = $this->servicios->find($servicioId);
		$kiosk = $totem === null ? null : $this->kiosk($totem, $servicioId);
		$name = $servicio->getName();
		$day = $this->today->day();

		if (!$servicio->getActive()) {
			throw new Refused('Esta unidad no está disponible.');
		}
		$time = $this->today->time();
		if ($time < $servicio->getOpens() || $time > $servicio->getCloses()) {
			throw new Refused(sprintf('%s atiende de %s a %s.', $name, $servicio->getOpens(), $servicio->getCloses()));
		}
		$quota = $servicio->getDailyQuota();
		if ($quota !== null && $this->turnos->countForDay($servicioId, $day) >= $quota) {
			throw new Refused(sprintf(self::QUOTA_REACHED, $name));
		}

		for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
			$turno = new Turno();
			$turno->setServicioId($servicioId);
			$turno->setDay($day);
			$turno->setPreferente($preferente);
			$turno->setSeq($this->turnos->maxSeq($servicioId, $day, $preferente) + 1);
			$turno->setEstado(Estado::Waiting->value);
			$turno->setCreatedAt($this->today->now());
			try {
				$turno = $this->turnos->insert($turno);
			} catch (DbException $e) {
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
				continue; // another totem took this sequence a moment ago
			}
			// The insert has committed before this count (autocommit; a caller's transaction under MySQL's REPEATABLE READ would hide a racing issue).
			if ($quota !== null && $this->turnos->countForDay($servicioId, $day) > $quota) {
				$this->turnos->delete($turno);
				throw new Refused(sprintf(self::QUOTA_REACHED, $name));
			}

			$label = TurnoLabel::of($servicio->getPrefix(), $preferente, $turno->getSeq());

			return [
				'id' => (int)$turno->getId(),
				'label' => $label,
				'servicio' => $name,
				'preferente' => $preferente,
				'printed' => $kiosk?->hasPrinter() ? $this->printing->printTurno($kiosk, $label, $name, $preferente) : null,
			];
		}

		throw new Refused('No pudimos entregar su turno. Intente de nuevo.');
	}

	/** @throws Refused the totem is unknown, or lists unidades and not this one */
	private function kiosk(string $slug, int $servicioId): Totem {
		try {
			$kiosk = $this->totems->findBySlug($slug);
		} catch (DoesNotExistException) {
			throw new Refused('Este tótem ya no existe.');
		}
		$offered = $this->links->ids(ServicioLinks::TOTEM, (int)$kiosk->getId());
		if ($offered !== [] && !in_array($servicioId, $offered, true)) {
			throw new Refused('Este tótem no ofrece esa unidad.');
		}

		return $kiosk;
	}
}
