<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Servicio> */
class ServicioMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'totem_servicio', Servicio::class);
	}

	/** @return Servicio[] */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('name', 'ASC')->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/** @throws DoesNotExistException */
	public function find(int $id): Servicio {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/** @throws DoesNotExistException */
	public function findBySlug(string $slug): Servicio {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('slug', $qb->createNamedParameter($slug)));

		return $this->findEntity($qb);
	}
}
