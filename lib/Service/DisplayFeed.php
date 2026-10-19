<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Service;

use OCA\Totem\Db\BoxMapper;
use OCA\Totem\Db\LlamadoMapper;
use OCA\Totem\Db\PantallaMapper;
use OCA\Totem\Db\ServicioLinks;
use OCA\Totem\Db\ServicioMapper;
use OCA\Totem\Db\TurnoMapper;
use OCA\Totem\Domain\SpokenPlace;
use OCA\Totem\Domain\TurnoLabel;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * What a waiting-room display asks every two seconds: the llamados after the
 * last one it saw, for the servicios it shows. Public and read-only, so it
 * carries labels, servicio and box names and colors, and nothing else: there is
 * no patient data to leak (docs/threat-model.md).
 */
class DisplayFeed {
	/** A freshly opened display gets the latest llamados; a polling one gets the few that are new. */
	private const LIMIT = 30;

	public function __construct(
		private PantallaMapper $pantallas,
		private ServicioLinks $links,
		private LlamadoMapper $llamados,
		private TurnoMapper $turnos,
		private ServicioMapper $servicios,
		private BoxMapper $boxes,
		private Today $today,
	) {
	}

	/**
	 * @return array{pantalla: array{name: string, volume: int, voice: string}, llamados: list<array<string, mixed>>, cursor: int}
	 * @throws DoesNotExistException the pantalla is unknown
	 */
	public function since(string $slug, int $cursor): array {
		$pantalla = $this->pantallas->findBySlug($slug);
		$servicioIds = $this->links->ids(ServicioLinks::PANTALLA, (int)$pantalla->getId());

		$out = [];
		$last = $cursor;
		$onlyOneVentanilla = null;
		foreach ($this->llamados->findSince($servicioIds, $this->today->day(), $cursor, self::LIMIT) as $llamado) {
			$turno = $this->turnos->find($llamado->getTurnoId());
			$servicio = $this->servicios->find($llamado->getServicioId());
			$box = $this->boxName($llamado->getBoxId());
			$onlyOneVentanilla ??= count($this->boxes->findAll()) === 1;
			$out[] = [
				'id' => (int)$llamado->getId(),
				'label' => TurnoLabel::of($servicio->getPrefix(), $turno->getPreferente(), $turno->getSeq()),
				'spoken' => TurnoLabel::spoken($servicio->getName(), $turno->getPreferente(), $turno->getSeq(), SpokenPlace::of($box, $onlyOneVentanilla)),
				'servicio' => $servicio->getName(),
				'color' => $servicio->getColor(),
				'preferente' => $turno->getPreferente(),
				'box' => $box,
				'calledAt' => $llamado->getCalledAt(),
			];
			$last = max($last, (int)$llamado->getId());
		}

		return [
			'pantalla' => ['name' => $pantalla->getName(), 'volume' => $pantalla->getVolume(), 'voice' => $pantalla->getVoice()],
			'llamados' => $out,
			'cursor' => $last,
		];
	}

	/** A box deleted after it called still shows its old llamados, under a generic name. */
	private function boxName(int $boxId): string {
		try {
			return $this->boxes->find($boxId)->getName();
		} catch (DoesNotExistException) {
			return 'Ventanilla';
		}
	}
}
