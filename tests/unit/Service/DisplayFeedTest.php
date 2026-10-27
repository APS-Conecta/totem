<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Totem\Tests\Unit\Service;

use OCA\Totem\Db\Box;
use OCA\Totem\Db\BoxMapper;
use OCA\Totem\Db\Llamado;
use OCA\Totem\Db\LlamadoMapper;
use OCA\Totem\Db\Pantalla;
use OCA\Totem\Db\PantallaMapper;
use OCA\Totem\Db\Servicio;
use OCA\Totem\Db\ServicioLinks;
use OCA\Totem\Db\ServicioMapper;
use OCA\Totem\Db\Turno;
use OCA\Totem\Db\TurnoMapper;
use OCA\Totem\Service\DisplayFeed;
use OCA\Totem\Service\Today;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DisplayFeedTest extends TestCase {
	private PantallaMapper&MockObject $pantallas;
	private ServicioLinks&MockObject $links;
	private LlamadoMapper&MockObject $llamados;
	private DisplayFeed $feed;
	/** @var list<Box> every ventanilla of the clinic: two by default, so a number is spoken */
	private array $allBoxes = [];

	protected function setUp(): void {
		$p = new Pantalla();
		$p->setId(2);
		$p->setName('Sala Farmacia');
		$p->setVolume(70);
		$p->setVoice('hombre');
		$this->pantallas = $this->createMock(PantallaMapper::class);
		$this->pantallas->method('findBySlug')->willReturnCallback(static fn (string $slug) => $slug === 'sala' ? $p : throw new DoesNotExistException('x'));
		$this->links = $this->createMock(ServicioLinks::class);
		$this->llamados = $this->createMock(LlamadoMapper::class);

		$turnos = $this->createMock(TurnoMapper::class);
		$turnos->method('find')->willReturnCallback(static function (int $id): Turno {
			$t = new Turno();
			$t->setId($id);
			$t->setServicioId(3);
			$t->setPreferente(true);
			$t->setSeq(12);

			return $t;
		});
		$servicios = $this->createMock(ServicioMapper::class);
		$s = new Servicio();
		$s->setId(3);
		$s->setName('Farmacia');
		$s->setPrefix('FAR');
		$s->setColor('#0b6e69');
		$servicios->method('find')->willReturn($s);
		$boxes = $this->createMock(BoxMapper::class);
		$boxes->method('findAll')->willReturnCallback(fn (): array => $this->allBoxes);
		$boxes->method('find')->willReturnCallback(static function (int $id): Box {
			if ($id !== 8) {
				throw new DoesNotExistException('deleted');
			}
			$b = new Box();
			$b->setId(8);
			$b->setName('Box 2');

			return $b;
		});
		$today = $this->createMock(Today::class);
		$today->method('day')->willReturn('2026-10-18');

		$this->allBoxes = [$this->boxNamed(8, 'Box 2'), $this->boxNamed(9, 'Box 3')];
		$this->feed = new DisplayFeed($this->pantallas, $this->links, $this->llamados, $turnos, $servicios, $boxes, $today);
	}

	private function boxNamed(int $id, string $name): Box {
		$b = new Box();
		$b->setId($id);
		$b->setName($name);

		return $b;
	}

	private function llamado(int $id, int $boxId = 8): Llamado {
		$l = new Llamado();
		$l->setId($id);
		$l->setTurnoId(40 + $id);
		$l->setServicioId(3);
		$l->setBoxId($boxId);
		$l->setCalledAt(5000 + $id);

		return $l;
	}

	public function testItReadsTodaysLlamadosAfterTheCursorForThePantallasServicios(): void {
		$this->links->method('ids')->with(ServicioLinks::PANTALLA, 2)->willReturn([3, 4]);
		$this->llamados->expects($this->once())->method('findSince')->with([3, 4], '2026-10-18', 17, 30)->willReturn([]);

		$this->feed->since('sala', 17);
	}

	public function testAPantallaWithNoServiciosAsksForEveryServicio(): void {
		$this->links->method('ids')->willReturn([]);
		$this->llamados->expects($this->once())->method('findSince')->with([], '2026-10-18', 0, 30)->willReturn([$this->llamado(21)]);

		$this->assertCount(1, $this->feed->since('sala', 0)['llamados']);
	}

	public function testItDescribesEachLlamadoWithTheWordsTheDisplayAndTheVoiceNeed(): void {
		$this->links->method('ids')->willReturn([]);
		$this->llamados->method('findSince')->willReturn([$this->llamado(21)]);

		$out = $this->feed->since('sala', 0);

		$this->assertSame([[
			'id' => 21,
			'label' => 'FAR-P-012',
			'spoken' => 'Farmacia, preferente 12. Diríjase a ventanilla 2.',
			'servicio' => 'Farmacia',
			'color' => '#0b6e69',
			'preferente' => true,
			'box' => 'Box 2',
			'calledAt' => 5021,
		]], $out['llamados']);
	}

	public function testTheCursorIsTheLastLlamadoSeenOrTheOneGiven(): void {
		$this->links->method('ids')->willReturn([]);
		$this->llamados->method('findSince')->willReturnOnConsecutiveCalls([$this->llamado(21), $this->llamado(22)], []);

		$this->assertSame(22, $this->feed->since('sala', 0)['cursor']);
		$this->assertSame(22, $this->feed->since('sala', 22)['cursor']);
	}

	public function testTheAnswerNamesItsDayEvenWithNoLlamados(): void {
		$this->links->method('ids')->willReturn([]);
		$this->llamados->method('findSince')->willReturn([]);

		$answer = $this->feed->since('sala', 22);

		$this->assertSame('2026-10-18', $answer['day'] ?? null);
	}

	public function testTheDayItNamesIsTheDayThatFilteredTheLlamados(): void {
		$today = $this->createMock(Today::class);
		$today->method('day')->willReturnOnConsecutiveCalls('2026-10-18', '2026-10-19');
		$feed = new DisplayFeed($this->pantallas, $this->links, $this->llamados, $this->createMock(TurnoMapper::class), $this->createMock(ServicioMapper::class), $this->createMock(BoxMapper::class), $today);
		$this->links->method('ids')->willReturn([]);
		$this->llamados->expects($this->once())->method('findSince')->with([], '2026-10-18', 0, 30)->willReturn([]);

		$this->assertSame('2026-10-18', $feed->since('sala', 0)['day']);
	}

	public function testItCarriesThePantallasNameAndSound(): void {
		$this->links->method('ids')->willReturn([]);
		$this->llamados->method('findSince')->willReturn([]);

		$this->assertSame(['name' => 'Sala Farmacia', 'volume' => 70, 'voice' => 'hombre'], $this->feed->since('sala', 0)['pantalla']);
	}

	public function testNoPersonalDataIsEverInTheFeed(): void {
		$this->links->method('ids')->willReturn([]);
		$this->llamados->method('findSince')->willReturn([$this->llamado(21)]);

		$keys = array_keys($this->feed->since('sala', 0)['llamados'][0]);

		$this->assertSame(['id', 'label', 'spoken', 'servicio', 'color', 'preferente', 'box', 'calledAt'], $keys);
	}

	public function testALlamadoOfADeletedBoxStillShowsWithAGenericName(): void {
		$this->links->method('ids')->willReturn([]);
		$this->llamados->method('findSince')->willReturn([$this->llamado(21, 99)]);

		$out = $this->feed->since('sala', 0)['llamados'][0];

		$this->assertSame('Ventanilla', $out['box']);
		$this->assertSame('Farmacia, preferente 12. Diríjase a Ventanilla.', $out['spoken']);
	}

	public function testWhenTheClinicHasOneVentanillaTheCallJustSaysVentanilla(): void {
		$this->allBoxes = [$this->boxNamed(8, 'Box 2')];
		$this->links->method('ids')->willReturn([]);
		$this->llamados->method('findSince')->willReturn([$this->llamado(21)]);

		$out = $this->feed->since('sala', 0)['llamados'][0];

		$this->assertSame('Farmacia, preferente 12. Diríjase a ventanilla.', $out['spoken']);
		$this->assertSame('Box 2', $out['box']);
	}

	public function testWithSeveralVentanillasTheCallSaysWhichByItsNumber(): void {
		$this->links->method('ids')->willReturn([]);
		$this->llamados->method('findSince')->willReturn([$this->llamado(21)]);

		$this->assertStringEndsWith('Diríjase a ventanilla 2.', $this->feed->since('sala', 0)['llamados'][0]['spoken']);
	}

	public function testAnUnknownPantallaIsNotFound(): void {
		$this->expectException(DoesNotExistException::class);
		$this->feed->since('otra', 0);
	}
}
