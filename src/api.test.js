import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { admin, issueTurno, publicServicios, readFeed, box, stats } from './api.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))

describe('the client for the server', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		for (const verb of ['get', 'post', 'put', 'delete']) axios[verb].mockResolvedValue({ data: { ok: verb } })
	})

	it('lists the servicios the default totem may offer', async () => {
		expect(await publicServicios()).toEqual({ ok: 'get' })
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/totem/public/servicios', { params: { totem: undefined } })
	})

	it('lists only the servicios of the totem it is asked for', async () => {
		await publicServicios('sala-sur')
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/totem/public/servicios', { params: { totem: 'sala-sur' } })
	})

	it('issues a turno with the two answers the patient gave', async () => {
		await issueTurno(3, true)
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/totem/turnos', { servicioId: 3, preferente: true, totem: undefined })
	})

	it('says which totem the turno is taken at, so its printer is the one that prints', async () => {
		await issueTurno(3, false, 'sala-sur')
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/totem/turnos', { servicioId: 3, preferente: false, totem: 'sala-sur' })
	})

	it('reads the display feed after a cursor', async () => {
		await readFeed('sala_farmacia', 17)
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/totem/display/sala_farmacia/feed', { params: { since: 17 } })
	})

	it('drives a box through the console routes', async () => {
		await box.boxes()
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/totem/console/boxes')
		await box.state(4)
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/totem/console/4/state')
		for (const action of ['call', 'recall', 'start', 'finish', 'absent']) {
			await box.act(4, action)
			expect(axios.post).toHaveBeenLastCalledWith(`/index.php/apps/totem/console/4/${action}`)
		}
	})

	it('refuses an action the console does not have', () => {
		expect(() => box.act(4, 'delete')).toThrow('Acción desconocida')
	})

	it('reads the statistics board for a kind of period and a day', async () => {
		await stats.board('week', '2026-10-21')
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/totem/stats', { params: { kind: 'week', date: '2026-10-21' } })
	})

	it('links the CSV download of the same period, as a plain URL', () => {
		expect(stats.csvUrl('month', '2026-10-01')).toBe('/index.php/apps/totem/stats/csv?kind=month&date=2026-10-01')
	})

	it('asks a totem to print a sample ticket', async () => {
		await admin.totems.testPrint(4)
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/totem/totems/4/test-print')
	})

	it('saves servicios, boxes, pantallas and totems through one shape', async () => {
		for (const [name, resource] of [['servicios', admin.servicios], ['boxes', admin.boxes], ['pantallas', admin.pantallas], ['totems', admin.totems]]) {
			await resource.list()
			expect(axios.get).toHaveBeenLastCalledWith(`/index.php/apps/totem/${name}`)
			await resource.save(null, { name: 'x' })
			expect(axios.post).toHaveBeenLastCalledWith(`/index.php/apps/totem/${name}`, { name: 'x' })
			await resource.save(7, { name: 'y' })
			expect(axios.put).toHaveBeenLastCalledWith(`/index.php/apps/totem/${name}/7`, { name: 'y' })
		}
	})

	it('deletes boxes, pantallas and totems, which servicios cannot', async () => {
		await admin.totems.remove(3)
		expect(axios.delete).toHaveBeenLastCalledWith('/index.php/apps/totem/totems/3')
		await admin.boxes.remove(7)
		expect(axios.delete).toHaveBeenLastCalledWith('/index.php/apps/totem/boxes/7')
		await admin.pantallas.remove(2)
		expect(axios.delete).toHaveBeenLastCalledWith('/index.php/apps/totem/pantallas/2')
		expect(admin.servicios.remove).toBeUndefined()
	})
})
