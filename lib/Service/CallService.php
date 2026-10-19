<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Service;

use OCA\Totem\Db\BoxMapper;
use OCA\Totem\Db\Llamado;
use OCA\Totem\Db\LlamadoMapper;
use OCA\Totem\Db\Servicio;
use OCA\Totem\Db\ServicioLinks;
use OCA\Totem\Db\ServicioMapper;
use OCA\Totem\Db\Turno;
use OCA\Totem\Db\TurnoMapper;
use OCA\Totem\Domain\Estado;
use OCA\Totem\Domain\NextCall;
use OCA\Totem\Domain\QueuedTurno;
use OCA\Totem\Domain\TurnoLabel;
use OCA\Totem\Exception\Refused;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * What a box does: call the next turno, call it again, start attending it,
 * finish it, or mark it as not shown. Every action answers with the box's
 * state, which is what the box console renders.
 */
class CallService {
	/** Another box may win the turno this one chose; choose again this many times. */
	private const ATTEMPTS = 3;

	public function __construct(
		private BoxMapper $boxes,
		private ServicioLinks $links,
		private ServicioMapper $servicios,
		private TurnoMapper $turnos,
		private LlamadoMapper $llamados,
		private Today $today,
	) {
	}

	/**
	 * @return array<string, mixed>
	 * @throws DoesNotExistException the box is unknown
	 * @throws Refused
	 */
	public function callNext(int $boxId): array {
		$this->boxes->find($boxId);
		if ($this->turnos->findActiveForBox($boxId, $this->today->day()) !== null) {
			throw new Refused('Termine o marque como ausente el turno actual antes de llamar otro.');
		}
		$servicioIds = $this->links->ids(ServicioLinks::BOX, $boxId);
		if ($servicioIds === []) {
			throw new Refused('Esta ventanilla no tiene unidades asignadas.');
		}

		for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
			$pick = $this->pick($servicioIds);
			if ($pick === null) {
				throw new Refused('No hay turnos en espera.');
			}
			if ($this->turnos->claim($pick['queued']->id, $boxId, $this->today->now())) {
				$this->log($pick['queued']->id, $pick['queued']->servicioId, $boxId);

				return $this->state($boxId);
			}
		}

		throw new Refused('Otra ventanilla tomó ese turno. Intente de nuevo.');
	}

	/** Announce the held turno again: another llamado, the turno itself unchanged. */
	public function recall(int $boxId): array {
		$turno = $this->held($boxId, Estado::Called, 'No hay un turno llamado para repetir.');
		$this->log((int)$turno->getId(), $turno->getServicioId(), $boxId);

		return $this->state($boxId);
	}

	public function start(int $boxId): array {
		return $this->move($boxId, Estado::Called, Estado::InService, 'No hay un turno llamado para iniciar.');
	}

	public function finish(int $boxId): array {
		return $this->move($boxId, Estado::InService, Estado::Completed, 'No hay un turno en atención para finalizar.');
	}

	public function absent(int $boxId): array {
		return $this->move($boxId, Estado::Called, Estado::NoShow, 'No hay un turno llamado para marcar como ausente.');
	}

	/**
	 * The box console's whole picture: who is held, who waits (oldest first)
	 * and, when nobody is held, who is next and why.
	 *
	 * @return array<string, mixed>
	 * @throws DoesNotExistException
	 */
	public function state(int $boxId): array {
		$box = $this->boxes->find($boxId);
		$servicioIds = $this->links->ids(ServicioLinks::BOX, $boxId);
		$held = $this->turnos->findActiveForBox($boxId, $this->today->day());

		$waiting = [];
		foreach ($servicioIds as $servicioId) {
			foreach ($this->turnos->findWaiting($servicioId, $this->today->day()) as $t) {
				$waiting[] = $t;
			}
		}
		usort($waiting, static fn (Turno $a, Turno $b) => [$a->getCreatedAt(), $a->getId()] <=> [$b->getCreatedAt(), $b->getId()]);

		$next = null;
		$why = '';
		if ($held === null) {
			$pick = $this->pick($servicioIds);
			if ($pick === null) {
				$why = 'No hay turnos en espera en las unidades de esta ventanilla.';
			} else {
				$next = $this->present($pick['turno']);
				$why = sprintf('Siguiente: %s (%s): %s.', $next['label'], $next['servicio'], NextCall::why($pick['queued'], $pick['generalDue']));
				if ($pick['competed']) {
					$why .= ' Entre las unidades de la ventanilla, es el que más espera.';
				}
			}
		}

		return [
			'box' => [
				'id' => $boxId,
				'name' => $box->getName(),
				'servicios' => array_map(fn (int $id) => $this->servicios->find($id)->getName(), $servicioIds),
			],
			'current' => $held === null ? null : $this->present($held) + [
				'estado' => $held->getEstado(),
				'since' => $held->getEstado() === Estado::InService->value ? $held->getServedAt() : $held->getCalledAt(),
			],
			'waiting' => array_map(fn (Turno $t) => $this->present($t) + [
				'waitedMinutes' => max(0, intdiv($this->today->now() - $t->getCreatedAt(), 60)),
			], $waiting),
			'next' => $next,
			'why' => $why,
		];
	}

	/**
	 * Each servicio's own candidate (the 2:1 rule), then the one that has waited longest.
	 *
	 * @param list<int> $servicioIds
	 * @return array{queued: QueuedTurno, turno: Turno, generalDue: bool, competed: bool}|null
	 */
	private function pick(array $servicioIds): ?array {
		$day = $this->today->day();
		$candidates = [];
		$entities = [];
		$due = [];
		foreach ($servicioIds as $servicioId) {
			$waiting = $this->turnos->findWaiting($servicioId, $day);
			$queued = array_map(static fn (Turno $t) => new QueuedTurno((int)$t->getId(), $t->getServicioId(), $t->getPreferente(), $t->getCreatedAt()), $waiting);
			$history = $this->turnos->lastCalledPreferente($servicioId, $day, 2);
			$due[$servicioId] = NextCall::generalIsDue($history);
			foreach ($waiting as $t) {
				$entities[(int)$t->getId()] = $t;
			}
			$candidate = NextCall::candidate($queued, $history);
			if ($candidate !== null) {
				$candidates[] = $candidate;
			}
		}
		$chosen = NextCall::oldest($candidates);
		if ($chosen === null) {
			return null;
		}

		return ['queued' => $chosen, 'turno' => $entities[$chosen->id], 'generalDue' => $due[$chosen->servicioId], 'competed' => count($candidates) > 1];
	}

	private function log(int $turnoId, int $servicioId, int $boxId): void {
		$llamado = new Llamado();
		$llamado->setTurnoId($turnoId);
		$llamado->setServicioId($servicioId);
		$llamado->setBoxId($boxId);
		$llamado->setCalledAt($this->today->now());
		$this->llamados->insert($llamado);
	}

	/** The turno the box holds, if it is in the estado the action needs. */
	private function held(int $boxId, Estado $needed, string $refusal): Turno {
		$this->boxes->find($boxId);
		$turno = $this->turnos->findActiveForBox($boxId, $this->today->day());
		if ($turno === null || $turno->getEstado() !== $needed->value) {
			throw new Refused($refusal);
		}

		return $turno;
	}

	private function move(int $boxId, Estado $from, Estado $to, string $refusal): array {
		$turno = $this->held($boxId, $from, $refusal);
		if (!$this->turnos->transition((int)$turno->getId(), $from, $to, $this->today->now())) {
			throw new Refused('El turno ya cambió. Actualice la pantalla.');
		}

		return $this->state($boxId);
	}

	/** @return array{id: int, label: string, servicio: string, color: string, preferente: bool} */
	private function present(Turno $t): array {
		$servicio = $this->servicios->find($t->getServicioId());

		return [
			'id' => (int)$t->getId(),
			'label' => TurnoLabel::of($servicio->getPrefix(), $t->getPreferente(), $t->getSeq()),
			'servicio' => $servicio->getName(),
			'color' => $servicio->getColor(),
			'preferente' => $t->getPreferente(),
		];
	}
}
