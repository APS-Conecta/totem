<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Db;

use OCA\Totem\Domain\Estado;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Turno> */
class TurnoMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'totem_turno', Turno::class);
	}

	/** @throws DoesNotExistException */
	public function find(int $id): Turno {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/** The highest sequence issued for this servicio, day and type; 0 when none. */
	public function maxSeq(int $servicioId, string $day, bool $preferente): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('seq'))
			->from($this->getTableName())
			->where($qb->expr()->eq('servicio_id', $qb->createNamedParameter($servicioId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('day', $qb->createNamedParameter($day)))
			->andWhere($qb->expr()->eq('preferente', $qb->createNamedParameter($preferente, IQueryBuilder::PARAM_BOOL)));
		$result = $qb->executeQuery();
		$max = $result->fetchOne();
		$result->closeCursor();

		return (int)$max;
	}

	/** Every turno issued for the servicio that day, whatever its estado: what the daily quota counts. */
	public function countForDay(int $servicioId, string $day): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName())
			->where($qb->expr()->eq('servicio_id', $qb->createNamedParameter($servicioId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('day', $qb->createNamedParameter($day)));
		$result = $qb->executeQuery();
		$count = $result->fetchOne();
		$result->closeCursor();

		return (int)$count;
	}

	/**
	 * How many turnos each (day, servicio, preferente, estado) holds between two
	 * days, both included: the raw material of the statistics board. Counts only;
	 * a turno has nothing else to report.
	 *
	 * @return list<array{day: string, servicio_id: int, preferente: bool, estado: string, n: int}>
	 */
	public function countsBetween(string $from, string $to): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('day', 'servicio_id', 'preferente', 'estado')
			->selectAlias($qb->func()->count('*'), 'n')
			->from($this->getTableName())
			->where($qb->expr()->gte('day', $qb->createNamedParameter($from)))
			->andWhere($qb->expr()->lte('day', $qb->createNamedParameter($to)))
			->groupBy('day', 'servicio_id', 'preferente', 'estado')
			->orderBy('day')
			->addOrderBy('servicio_id')
			->addOrderBy('preferente')
			->addOrderBy('estado');
		$result = $qb->executeQuery();
		$counts = [];
		while ($row = $result->fetch()) {
			$counts[] = [
				'day' => (string)$row['day'],
				'servicio_id' => (int)$row['servicio_id'],
				'preferente' => (bool)$row['preferente'],
				'estado' => (string)$row['estado'],
				'n' => (int)$row['n'],
			];
		}
		$result->closeCursor();

		return $counts;
	}

	/** @return Turno[] oldest first */
	public function findWaiting(int $servicioId, string $day): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('servicio_id', $qb->createNamedParameter($servicioId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('day', $qb->createNamedParameter($day)))
			->andWhere($qb->expr()->eq('estado', $qb->createNamedParameter(Estado::Waiting->value)))
			->orderBy('created_at', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Take a waiting turno for a box. One conditional UPDATE: of two boxes
	 * racing for the same turno exactly one sees an affected row.
	 */
	public function claim(int $id, int $boxId, int $now): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('estado', $qb->createNamedParameter(Estado::Called->value))
			->set('box_id', $qb->createNamedParameter($boxId, IQueryBuilder::PARAM_INT))
			->set('called_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('estado', $qb->createNamedParameter(Estado::Waiting->value)));

		return $qb->executeStatement() === 1;
	}

	/** Move a turno from the estado the caller saw to the next one; false when it was not in that estado. */
	public function transition(int $id, Estado $from, Estado $to, int $now): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('estado', $qb->createNamedParameter($to->value))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('estado', $qb->createNamedParameter($from->value)));
		if ($to === Estado::InService) {
			$qb->set('served_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT));
		} elseif ($to === Estado::Completed || $to === Estado::NoShow) {
			$qb->set('finished_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT));
		}

		return $qb->executeStatement() === 1;
	}

	/** The turno a box is attending: called or in service. */
	public function findActiveForBox(int $boxId, string $day): ?Turno {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('box_id', $qb->createNamedParameter($boxId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('day', $qb->createNamedParameter($day)))
			->andWhere($qb->expr()->in('estado', $qb->createNamedParameter(
				[Estado::Called->value, Estado::InService->value], IQueryBuilder::PARAM_STR_ARRAY,
			)))
			->orderBy('called_at', 'DESC')
			->setMaxResults(1);

		return $this->findEntities($qb)[0] ?? null;
	}

	/**
	 * Whether each of the last turnos called today in this servicio was
	 * preferente, newest first. called_at is the FIRST call, so a re-llamado
	 * does not count as another call.
	 *
	 * @return list<bool>
	 */
	public function lastCalledPreferente(int $servicioId, string $day, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('preferente')
			->from($this->getTableName())
			->where($qb->expr()->eq('servicio_id', $qb->createNamedParameter($servicioId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('day', $qb->createNamedParameter($day)))
			->andWhere($qb->expr()->isNotNull('called_at'))
			->orderBy('called_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit);
		$result = $qb->executeQuery();
		$flags = array_map(static fn ($v) => (bool)$v, $result->fetchFirstColumn());
		$result->closeCursor();

		return $flags;
	}
}
