<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Service;

use OCA\Totem\Db\ServicioMapper;
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
 */
class TurnoService {
	/** Issuing is MAX(seq)+1 with the unique index as the net; this many tries before giving up. */
	private const ATTEMPTS = 3;

	public function __construct(
		private ServicioMapper $servicios,
		private TurnoMapper $turnos,
		private Today $today,
	) {
	}

	/**
	 * @return array{id: int, label: string, servicio: string, preferente: bool}
	 * @throws DoesNotExistException the servicio is unknown
	 * @throws Refused the servicio cannot issue a turno right now
	 */
	public function issue(int $servicioId, bool $preferente): array {
		$servicio = $this->servicios->find($servicioId);
		$name = $servicio->getName();
		$day = $this->today->day();

		if (!$servicio->getActive()) {
			throw new Refused('Este servicio no está disponible.');
		}
		$time = $this->today->time();
		if ($time < $servicio->getOpens() || $time > $servicio->getCloses()) {
			throw new Refused(sprintf('%s atiende de %s a %s.', $name, $servicio->getOpens(), $servicio->getCloses()));
		}
		$quota = $servicio->getDailyQuota();
		if ($quota !== null && $this->turnos->countForDay($servicioId, $day) >= $quota) {
			throw new Refused(sprintf('%s ya entregó todos los turnos de hoy.', $name));
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

			return [
				'id' => (int)$turno->getId(),
				'label' => TurnoLabel::of($servicio->getPrefix(), $preferente, $turno->getSeq()),
				'servicio' => $name,
				'preferente' => $preferente,
			];
		}

		throw new Refused('No pudimos entregar su turno. Intente de nuevo.');
	}
}
