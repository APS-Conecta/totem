<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Service;

use APS\Common\Text;
use OCA\Totem\Db\ServicioLinks;
use OCA\Totem\Db\ServicioMapper;
use OCA\Totem\Db\Totem;
use OCA\Totem\Db\TotemMapper;
use OCA\Totem\Exception\Refused;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The totems of the clinic: a kiosk with its own public address, the unidades it offers (none means all) and,
 * if it has one, the network printer the server prints its tickets to. The slug is the kiosk's address and is
 * fixed at creation: renaming a totem must not break the kiosk already open on it.
 */
class TotemService {
	use ChecksServicios;

	public function __construct(
		private TotemMapper $totems,
		private ServicioLinks $links,
		private ServicioMapper $servicios,
		private TicketPrinting $printing,
	) {
	}

	/** @return list<array<string, mixed>> */
	public function list(): array {
		return array_map($this->present(...), $this->totems->findAll());
	}

	/** @param array<string, mixed> $data */
	public function create(array $data): array {
		$totem = new Totem();
		$servicioIds = $this->fill($totem, $data);
		$slug = Text::slugFor($totem->getName());
		try {
			$this->totems->findBySlug($slug);
			throw new Refused('Ya existe un tótem con ese nombre.');
		} catch (DoesNotExistException) {
			// free
		}
		$totem->setSlug($slug);
		$totem = $this->totems->insert($totem);
		$this->links->replace(ServicioLinks::TOTEM, (int)$totem->getId(), $servicioIds);

		return $this->present($totem);
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws DoesNotExistException
	 */
	public function update(int $id, array $data): array {
		$totem = $this->totems->find($id);
		$before = [$totem->getPrinterHost(), $totem->getPrinterPort()];
		$servicioIds = $this->fill($totem, $data);
		if ($before !== [$totem->getPrinterHost(), $totem->getPrinterPort()]) {
			// The last result was about another printer.
			$totem->setPrinterOk(null);
			$totem->setPrinterCheckedAt(null);
		}
		$totem = $this->totems->update($totem);
		$this->links->replace(ServicioLinks::TOTEM, $id, $servicioIds);

		return $this->present($totem);
	}

	/** @throws DoesNotExistException */
	public function delete(int $id): void {
		$totem = $this->totems->find($id);
		$this->totems->delete($totem);
		$this->links->replace(ServicioLinks::TOTEM, $id, []);
	}

	/**
	 * Print a sample ticket at the totem's printer, so the admin sees it works.
	 *
	 * @return array{printed: bool, totem: array<string, mixed>}
	 * @throws DoesNotExistException
	 * @throws Refused when the totem has no network printer
	 */
	public function testPrint(int $id): array {
		$totem = $this->totems->find($id);
		if (!$totem->hasPrinter()) {
			throw new Refused('Este tótem no tiene impresora de red. Escriba su dirección y guarde.');
		}
		$printed = $this->printing->printTest($totem);

		return ['printed' => $printed, 'totem' => $this->present($totem)];
	}

	/**
	 * @param array<string, mixed> $data
	 * @return list<int> the servicio ids to link
	 */
	private function fill(Totem $totem, array $data): array {
		$totem->setName(Fields::text($data['name'] ?? '', 'El nombre', 32));
		[$host, $port] = Fields::printer($data['printerHost'] ?? null, $data['printerPort'] ?? null);
		$totem->setPrinterHost($host);
		$totem->setPrinterPort($port);
		$servicioIds = Fields::ids($data['servicioIds'] ?? []);
		$this->assertServiciosExist($servicioIds);

		return $servicioIds;
	}

	private function present(Totem $t): array {
		return [
			'id' => (int)$t->getId(),
			'slug' => $t->getSlug(),
			'name' => $t->getName(),
			'servicioIds' => $this->links->ids(ServicioLinks::TOTEM, (int)$t->getId()),
			'printerHost' => $t->getPrinterHost(),
			'printerPort' => $t->getPrinterPort(),
			'printerOk' => $t->getPrinterOk(),
			'printerCheckedAt' => $t->getPrinterCheckedAt(),
		];
	}
}
