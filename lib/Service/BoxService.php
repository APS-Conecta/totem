<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Service;

use OCA\Totem\Db\Box;
use OCA\Totem\Db\BoxMapper;
use OCA\Totem\Db\ServicioLinks;
use OCA\Totem\Db\ServicioMapper;
use OCA\Totem\Db\TurnoMapper;
use OCA\Totem\Exception\Refused;
use OCP\AppFramework\Db\DoesNotExistException;

/** The admin's boxes: a desk and the servicios it covers. */
class BoxService {
	use ChecksServicios;

	public function __construct(
		private BoxMapper $boxes,
		private ServicioLinks $links,
		private ServicioMapper $servicios,
		private TurnoMapper $turnos,
		private Today $today,
	) {
	}

	/** @return list<array{id: int, name: string, servicioIds: list<int>}> */
	public function list(): array {
		return array_map($this->present(...), $this->boxes->findAll());
	}

	/** @param array<string, mixed> $data */
	public function create(array $data): array {
		[$name, $servicioIds] = $this->clean($data);
		$box = new Box();
		$box->setName($name);
		$box = $this->boxes->insert($box);
		$this->links->replace(ServicioLinks::BOX, (int)$box->getId(), $servicioIds);

		return $this->present($box);
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws DoesNotExistException
	 */
	public function update(int $id, array $data): array {
		$box = $this->boxes->find($id);
		[$name, $servicioIds] = $this->clean($data);
		$box->setName($name);
		$box = $this->boxes->update($box);
		$this->links->replace(ServicioLinks::BOX, $id, $servicioIds);

		return $this->present($box);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws Refused the box is attending someone right now
	 */
	public function delete(int $id): void {
		$box = $this->boxes->find($id);
		if ($this->turnos->findActiveForBox($id, $this->today->day()) !== null) {
			throw new Refused('Esta ventanilla tiene un turno en curso.');
		}
		$this->boxes->delete($box);
		$this->links->replace(ServicioLinks::BOX, $id, []);
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array{string, list<int>}
	 */
	private function clean(array $data): array {
		$name = Fields::text($data['name'] ?? '', 'El nombre', 14);
		$servicioIds = Fields::ids($data['servicioIds'] ?? []);
		if ($servicioIds === []) {
			throw new Refused('Elija al menos una unidad para la ventanilla.');
		}
		$this->assertServiciosExist($servicioIds);

		return [$name, $servicioIds];
	}

	private function present(Box $box): array {
		return ['id' => (int)$box->getId(), 'name' => $box->getName(), 'servicioIds' => $this->links->ids(ServicioLinks::BOX, (int)$box->getId())];
	}
}
