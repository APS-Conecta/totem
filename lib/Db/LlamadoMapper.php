<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Llamado> */
class LlamadoMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'totem_llamado', Llamado::class);
	}

	/**
	 * The display's cursor read: llamados of today's turnos with an id above
	 * $sinceId, oldest first. When more than $limit match, the newest $limit
	 * are kept (what a freshly opened display needs). An empty $servicioIds
	 * means every servicio.
	 *
	 * @param list<int> $servicioIds
	 * @return Llamado[]
	 */
	public function findSince(array $servicioIds, string $day, int $sinceId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('l.*')
			->from($this->getTableName(), 'l')
			->innerJoin('l', 'totem_turno', 't', $qb->expr()->eq('t.id', 'l.turno_id'))
			->where($qb->expr()->eq('t.day', $qb->createNamedParameter($day)))
			->andWhere($qb->expr()->gt('l.id', $qb->createNamedParameter($sinceId, IQueryBuilder::PARAM_INT)))
			->orderBy('l.id', 'DESC')
			->setMaxResults($limit);
		if ($servicioIds !== []) {
			$qb->andWhere($qb->expr()->in('l.servicio_id', $qb->createNamedParameter($servicioIds, IQueryBuilder::PARAM_INT_ARRAY)));
		}

		return array_reverse($this->findEntities($qb));
	}
}
