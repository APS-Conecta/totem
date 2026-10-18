<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Service;

use APS\Common\Text;
use OCA\Totem\Db\Pantalla;
use OCA\Totem\Db\PantallaMapper;
use OCA\Totem\Db\ServicioLinks;
use OCA\Totem\Db\ServicioMapper;
use OCA\Totem\Exception\Refused;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The admin's pantallas: a waiting-room display, the servicios it shows (none
 * means all) and its sound. The slug is the display's public address and is
 * fixed at creation: renaming a pantalla must not break the TV already open on it.
 */
class PantallaService {
	use ChecksServicios;

	public function __construct(
		private PantallaMapper $pantallas,
		private ServicioLinks $links,
		private ServicioMapper $servicios,
	) {
	}

	/** @return list<array<string, mixed>> */
	public function list(): array {
		return array_map($this->present(...), $this->pantallas->findAll());
	}

	/** @param array<string, mixed> $data */
	public function create(array $data): array {
		$pantalla = new Pantalla();
		$servicioIds = $this->fill($pantalla, $data);
		$slug = Text::slugFor($pantalla->getName());
		try {
			$this->pantallas->findBySlug($slug);
			throw new Refused('Ya existe una pantalla con ese nombre.');
		} catch (DoesNotExistException) {
			// free
		}
		$pantalla->setSlug($slug);
		$pantalla = $this->pantallas->insert($pantalla);
		$this->links->replace(ServicioLinks::PANTALLA, (int)$pantalla->getId(), $servicioIds);

		return $this->present($pantalla);
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws DoesNotExistException
	 */
	public function update(int $id, array $data): array {
		$pantalla = $this->pantallas->find($id);
		$servicioIds = $this->fill($pantalla, $data);
		$pantalla = $this->pantallas->update($pantalla);
		$this->links->replace(ServicioLinks::PANTALLA, $id, $servicioIds);

		return $this->present($pantalla);
	}

	/** @throws DoesNotExistException */
	public function delete(int $id): void {
		$pantalla = $this->pantallas->find($id);
		$this->pantallas->delete($pantalla);
		$this->links->replace(ServicioLinks::PANTALLA, $id, []);
	}

	/**
	 * @param array<string, mixed> $data
	 * @return list<int> the servicio ids to link
	 */
	private function fill(Pantalla $pantalla, array $data): array {
		$pantalla->setName(Fields::text($data['name'] ?? '', 'El nombre', 32));
		$pantalla->setVolume(Fields::volume($data['volume'] ?? 100));
		$pantalla->setVoice(Fields::voice($data['voice'] ?? 'mujer'));
		$servicioIds = Fields::ids($data['servicioIds'] ?? []);
		$this->assertServiciosExist($servicioIds);

		return $servicioIds;
	}

	private function present(Pantalla $p): array {
		return [
			'id' => (int)$p->getId(),
			'slug' => $p->getSlug(),
			'name' => $p->getName(),
			'volume' => $p->getVolume(),
			'voice' => $p->getVoice(),
			'servicioIds' => $this->links->ids(ServicioLinks::PANTALLA, (int)$p->getId()),
		];
	}
}
