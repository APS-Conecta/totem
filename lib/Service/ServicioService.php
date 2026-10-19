<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Service;

use APS\Common\Text;
use OCA\Totem\Db\Servicio;
use OCA\Totem\Db\ServicioMapper;
use OCA\Totem\Exception\Refused;
use OCP\AppFramework\Db\DoesNotExistException;

/** The admin's servicios: validated, unique by name and by prefix. */
class ServicioService {
	public function __construct(private ServicioMapper $servicios) {
	}

	/** @return list<array<string, mixed>> */
	public function list(): array {
		return array_map($this->present(...), $this->servicios->findAll());
	}

	/**
	 * What the totem may offer a patient: the active servicios, and only what a button needs. A totem that
	 * lists unidades offers just those (`$onlyIds`); none listed means all.
	 *
	 * @param list<int> $onlyIds
	 * @return list<array{id: int, name: string, prefix: string, color: string}>
	 */
	public function forTotem(array $onlyIds = []): array {
		$offered = [];
		foreach ($this->servicios->findAll() as $s) {
			if ($s->getActive() && ($onlyIds === [] || in_array((int)$s->getId(), $onlyIds, true))) {
				$offered[] = ['id' => (int)$s->getId(), 'name' => $s->getName(), 'prefix' => $s->getPrefix(), 'color' => $s->getColor()];
			}
		}

		return $offered;
	}

	/** @param array<string, mixed> $data */
	public function create(array $data): array {
		$servicio = new Servicio();
		$this->fill($servicio, $data);

		return $this->present($this->servicios->insert($servicio));
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws DoesNotExistException
	 */
	public function update(int $id, array $data): array {
		$servicio = $this->servicios->find($id);
		$this->fill($servicio, $data);

		return $this->present($this->servicios->update($servicio));
	}

	/** @param array<string, mixed> $data */
	private function fill(Servicio $servicio, array $data): void {
		$name = Fields::text($data['name'] ?? '', 'El nombre', 28);
		$prefix = Fields::prefix($data['prefix'] ?? '');
		$opens = Fields::time($data['opens'] ?? '', 'La hora de apertura');
		$closes = Fields::time($data['closes'] ?? '', 'La hora de cierre');
		if ($opens >= $closes) {
			throw new Refused('La hora de apertura debe ser anterior a la de cierre.');
		}
		$slug = Text::slugFor($name);
		$this->assertUnique($servicio, $slug, $prefix);

		$servicio->setSlug($slug);
		$servicio->setName($name);
		$servicio->setPrefix($prefix);
		$servicio->setColor(Fields::color($data['color'] ?? ''));
		$servicio->setOpens($opens);
		$servicio->setCloses($closes);
		$servicio->setDailyQuota(Fields::quota($data['dailyQuota'] ?? null));
		$servicio->setActive(($data['active'] ?? true) === true || ($data['active'] ?? null) === 1);
		$servicio->setIsSector(($data['isSector'] ?? false) === true);
	}

	/** Another servicio (not this one) must not already have the name or the prefix. */
	private function assertUnique(Servicio $servicio, string $slug, string $prefix): void {
		try {
			$same = $this->servicios->findBySlug($slug);
			if ($same->getId() !== $servicio->getId()) {
				throw new Refused('Ya existe una unidad con ese nombre.');
			}
		} catch (DoesNotExistException) {
			// free
		}
		foreach ($this->servicios->findAll() as $other) {
			if ($other->getPrefix() === $prefix && $other->getId() !== $servicio->getId()) {
				throw new Refused("Ya existe una unidad con el prefijo $prefix.");
			}
		}
	}

	/** @return array{id: int, name: string, prefix: string, color: string, opens: string, closes: string, dailyQuota: int|null, active: bool, isSector: bool} */
	private function present(Servicio $s): array {
		return [
			'id' => (int)$s->getId(),
			'name' => $s->getName(),
			'prefix' => $s->getPrefix(),
			'color' => $s->getColor(),
			'opens' => $s->getOpens(),
			'closes' => $s->getCloses(),
			'dailyQuota' => $s->getDailyQuota(),
			'active' => $s->getActive(),
			'isSector' => $s->getIsSector(),
		];
	}
}
