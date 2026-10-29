<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Db;

use OCA\Totem\Service\Today;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Llamado> */
class LlamadoMapper extends QBMapper {
	public function __construct(IDBConnection $db, private Today $today) {
		parent::__construct($db, 'totem_llamado', Llamado::class);
	}

	/**
	 * The display's cursor read: llamados made on this Día with an id above
	 * $sinceId, oldest first — whichever Día the turno it calls was issued on,
	 * because a box may recall a turno it holds across the Día change. When
	 * more than $limit match, the newest $limit are kept (what a freshly
	 * opened display needs). An empty $servicioIds means every servicio.
	 *
	 * @param list<int> $servicioIds
	 * @return Llamado[]
	 */
	public function findSince(array $servicioIds, string $day, int $sinceId, int $limit): array {
		[$from, $to] = $this->today->dayWindow($day);
		$qb = $this->db->getQueryBuilder();
		$qb->select('l.*')
			->from($this->getTableName(), 'l')
			->where($qb->expr()->gte('l.called_at', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('l.called_at', $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('l.id', $qb->createNamedParameter($sinceId, IQueryBuilder::PARAM_INT)))
			->orderBy('l.id', 'DESC')
			->setMaxResults($limit);
		if ($servicioIds !== []) {
			$qb->andWhere($qb->expr()->in('l.servicio_id', $qb->createNamedParameter($servicioIds, IQueryBuilder::PARAM_INT_ARRAY)));
		}

		return array_reverse($this->findEntities($qb));
	}
}
