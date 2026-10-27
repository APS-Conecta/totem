<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Integration;

use OCA\Totem\Db\BoxMapper;
use OCA\Totem\Db\LlamadoMapper;
use OCA\Totem\Db\Servicio;
use OCA\Totem\Db\ServicioLinks;
use OCA\Totem\Db\ServicioMapper;
use OCA\Totem\Db\TurnoMapper;
use OCA\Totem\Exception\Refused;
use OCA\Totem\Service\BoxService;
use OCA\Totem\Service\CallService;
use OCA\Totem\Service\StatsService;
use OCA\Totem\Service\Today;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A box at midnight, against real PostgreSQL: the clock moves from D 23:55 to
 * D+1 00:01 while the box holds a turno. The box keeps that turno until it is
 * closed; a turno left waiting ends with its Día (CONTEXT.md, Día).
 */
final class CallAcrossTheDiaTest extends IntegrationTestCase {
	private const NEXT_DAY = '2026-10-19';
	private int $now;
	private TurnoMapper $turnos;
	private CallService $calls;
	private BoxService $boxes;
	private Servicio $farmacia;
	private int $boxId;

	protected function setUp(): void {
		parent::setUp();
		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$today = new Today($clock, Server::get(IDateTimeZone::class));
		$this->turnos = Server::get(TurnoMapper::class);
		$boxes = Server::get(BoxMapper::class);
		$links = Server::get(ServicioLinks::class);
		$servicios = Server::get(ServicioMapper::class);
		$this->calls = new CallService($boxes, $links, $servicios, $this->turnos, Server::get(LlamadoMapper::class), $today);
		$this->boxes = new BoxService($boxes, $links, $servicios, $this->turnos);

		$this->at(self::DAY, '23:55');
		$this->farmacia = $this->servicio();
		$this->boxId = $this->boxes->create(['name' => 'Box 1', 'servicioIds' => [$this->farmacia->getId()]])['id'];
	}

	public function testATurnoStartedBeforeMidnightIsFinishedAfterIt(): void {
		$turno = $this->turno($this->farmacia, false, 40, $this->now);
		$this->calls->callNext($this->boxId);
		$this->calls->start($this->boxId);

		$this->at(self::NEXT_DAY, '00:01');
		$this->calls->finish($this->boxId);

		$this->assertSame('completed', $this->turnos->find($turno->getId())->getEstado());
		$this->assertSame(['emitidos' => 1, 'atendidos' => 1, 'noShow' => 0, 'sinAtender' => 0], $this->totals(self::DAY));
	}

	public function testACalledTurnoFromTheDayBeforeStaysTheBoxsCurrentTurno(): void {
		$held = $this->turno($this->farmacia, false, 40, $this->now);
		$this->calls->callNext($this->boxId);

		$this->at(self::NEXT_DAY, '00:01');
		$waiting = $this->turno($this->farmacia, false, 1, $this->now, self::NEXT_DAY);
		$current = $this->calls->state($this->boxId)['current'];

		$this->assertNotNull($current, 'the box lost the turno it holds');
		$this->assertSame($held->getId(), $current['id']);
		$this->assertSame('called', $current['estado']);
		$this->assertSame('Termine o marque como ausente el turno actual antes de llamar otro.', $this->refusal(fn () => $this->calls->callNext($this->boxId)));
		$this->assertSame('waiting', $this->turnos->find($waiting->getId())->getEstado());
	}

	/** @return array<string, array{string, string, int}> the action, the estado it leaves, the turno's llamados */
	public static function closings(): array {
		return [
			'Llamar de nuevo' => ['recall', 'called', 2],
			'Iniciar atención' => ['start', 'in_service', 1],
			'No se presentó' => ['absent', 'no_show', 1],
		];
	}

	#[DataProvider('closings')]
	public function testACalledTurnoFromTheDayBeforeCanBeClosedOnTheNext(string $action, string $estado, int $llamados): void {
		$turno = $this->turno($this->farmacia, false, 40, $this->now);
		$this->calls->callNext($this->boxId);

		$this->at(self::NEXT_DAY, '00:01');
		$this->calls->$action($this->boxId);

		$this->assertSame($estado, $this->turnos->find($turno->getId())->getEstado());
		$this->assertCount($llamados, Server::get(LlamadoMapper::class)->findSince([], self::DAY, 0, 50));
	}

	public function testABoxHoldingATurnoFromAnEarlierDiaCannotBeDeleted(): void {
		$this->turno($this->farmacia, false, 40, $this->now);
		$this->calls->callNext($this->boxId);

		$this->at(self::NEXT_DAY, '00:01');

		$this->assertSame('Esta ventanilla tiene un turno en curso.', $this->refusal(fn () => $this->boxes->delete($this->boxId)));
	}

	public function testATurnoLeftWaitingEndsWithItsDia(): void {
		$this->at(self::DAY, '23:59');
		$this->turno($this->farmacia, false, 41, $this->now);

		$this->at(self::NEXT_DAY, '00:01');

		$this->assertSame([], $this->calls->state($this->boxId)['waiting']);
		$this->assertSame('No hay turnos en espera.', $this->refusal(fn () => $this->calls->callNext($this->boxId)));
		$this->assertSame(['emitidos' => 1, 'atendidos' => 0, 'noShow' => 0, 'sinAtender' => 1], $this->totals(self::DAY));
	}

	/** Move the clock to this day and time in the instance's time zone. */
	private function at(string $day, string $time): void {
		$this->now = (new \DateTimeImmutable("$day $time", Server::get(IDateTimeZone::class)->getDefaultTimeZone()))->getTimestamp();
	}

	/** @return array{emitidos: int, atendidos: int, noShow: int, sinAtender: int} the statistics board's totals for the day */
	private function totals(string $day): array {
		return Server::get(StatsService::class)->board('day', $day)['total']['total'];
	}

	private function refusal(callable $action): string {
		try {
			$action();
		} catch (Refused $e) {
			return $e->getMessage();
		}
		$this->fail('the action was not refused');
	}
}
