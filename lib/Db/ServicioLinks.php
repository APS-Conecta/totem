<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The servicios a box covers, a pantalla shows and a totem offers: three join tables with one
 * shape, so one reader and one writer (no foreign keys, by convention).
 */
class ServicioLinks {
	public const BOX = ['totem_box_servicio', 'box_id'];
	public const PANTALLA = ['totem_pantalla_servicio', 'pantalla_id'];
	public const TOTEM = ['totem_totem_servicio', 'totem_id'];

	public function __construct(private IDBConnection $db) {
	}

	/**
	 * @param array{string, string} $link one of the constants above
	 * @return list<int> servicio ids, ascending
	 */
	public function ids(array $link, int $ownerId): array {
		[$table, $column] = $link;
		$qb = $this->db->getQueryBuilder();
		$qb->select('servicio_id')
			->from($table)
			->where($qb->expr()->eq($column, $qb->createNamedParameter($ownerId, IQueryBuilder::PARAM_INT)))
			->orderBy('servicio_id', 'ASC');
		$result = $qb->executeQuery();
		$ids = array_map('intval', $result->fetchFirstColumn());
		$result->closeCursor();

		return $ids;
	}

	/**
	 * Make the owner's links exactly these servicio ids.
	 *
	 * @param array{string, string} $link
	 * @param list<int> $servicioIds
	 */
	public function replace(array $link, int $ownerId, array $servicioIds): void {
		[$table, $column] = $link;
		$qb = $this->db->getQueryBuilder();
		$qb->delete($table)
			->where($qb->expr()->eq($column, $qb->createNamedParameter($ownerId, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		foreach (array_unique($servicioIds) as $servicioId) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert($table)
				->values([
					$column => $qb->createNamedParameter($ownerId, IQueryBuilder::PARAM_INT),
					'servicio_id' => $qb->createNamedParameter($servicioId, IQueryBuilder::PARAM_INT),
				])
				->executeStatement();
		}
	}
}
