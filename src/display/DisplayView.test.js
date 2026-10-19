// @vitest-environment jsdom
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import DisplayView from './DisplayView.vue'

vi.mock('@nextcloud/initial-state', () => ({ loadState: (app, key, fallback) => (key === 'slug' ? 'sala_farmacia' : fallback) }))
vi.mock('../api.js', () => ({ readFeed: vi.fn(), warnError: vi.fn(), messageFrom: vi.fn() }))
vi.mock('../lib/announce.js', () => ({ announce: vi.fn(() => Promise.resolve()), warmUp: vi.fn() }))
vi.mock('../lib/chime.js', () => ({ audioReady: vi.fn(() => true), unlockAudio: vi.fn(() => Promise.resolve(true)) }))

import { readFeed } from '../api.js'
import { announce, warmUp } from '../lib/announce.js'
import { audioReady, unlockAudio } from '../lib/chime.js'

const pantalla = { name: 'Sala Farmacia', volume: 80, voice: 'mujer' }
const llamado = (id, label, box = 'Box 2', extra = {}) => ({
	id, label, spoken: `Turno ${label}. Diríjase a ${box}.`, servicio: 'Farmacia', color: '#0b6e69', preferente: label.includes('-P-'), box, calledAt: 1000 + id, ...extra,
})
const feed = (llamados, cursor = llamados.at(-1)?.id ?? 0) => ({ pantalla, llamados, cursor })

async function open(first) {
	readFeed.mockResolvedValueOnce(first)
	const wrapper = mount(DisplayView)
	await flushPromises()
	return wrapper
}
const next = async (data) => {
	readFeed.mockResolvedValueOnce(data)
	await vi.advanceTimersByTimeAsync(2000)
	await flushPromises()
}

describe('DisplayView', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.clearAllMocks()
		audioReady.mockReturnValue(true)
	})
	afterEach(() => vi.useRealTimers())

	it('waits for the first call when nothing has been called today', async () => {
		const wrapper = await open(feed([], 0))

		expect(wrapper.text()).toContain('Esperando el primer llamado.')
	})

	it('shows the latest call large: servicio, label and "→ BOX", on the servicio color', async () => {
		const wrapper = await open(feed([llamado(1, 'MUE-G-005', 'Box 5'), llamado(2, 'FAR-P-012', 'Box 2', { preferente: true })]))

		const card = wrapper.get('[role="status"]')
		expect(card.text()).toContain('FARMACIA')
		expect(card.text()).toContain('FAR-P-012')
		expect(card.text()).toContain('→ BOX 2')
		expect(card.text()).toContain('Atención preferente')
		expect(card.attributes('style')).toContain('background: rgb(11, 110, 105)')
	})

	it('lists the earlier calls newest first under "Últimos llamados", one row per turno', async () => {
		const wrapper = await open(feed([llamado(1, 'MUE-G-005', 'Box 5'), llamado(2, 'FAR-G-029', 'Box 2'), llamado(3, 'FAR-P-011', 'Box 3'), llamado(4, 'FAR-P-012', 'Box 2')]))

		const rows = wrapper.findAll('.d-row').map((r) => r.text())
		expect(wrapper.text()).toContain('Últimos llamados')
		expect(rows).toEqual(['FAR-P-011Box 3', 'FAR-G-029Box 2', 'MUE-G-005Box 5'])
	})

	it('a repeated call of a turno moves it to the card and does not add another row', async () => {
		const wrapper = await open(feed([llamado(1, 'MUE-G-005', 'Box 5'), llamado(2, 'FAR-P-012', 'Box 2')]))
		await next(feed([llamado(3, 'MUE-G-005', 'Box 5')]))

		expect(wrapper.get('[role="status"]').text()).toContain('MUE-G-005')
		expect(wrapper.findAll('.d-row').map((r) => r.text())).toEqual(['FAR-P-012Box 2'])
	})

	it('keeps six rows at most', async () => {
		const llamados = Array.from({ length: 10 }, (_, i) => llamado(i + 1, `FAR-G-${String(i + 1).padStart(3, '0')}`))
		const wrapper = await open(feed(llamados))

		expect(wrapper.findAll('.d-row')).toHaveLength(6)
	})

	it('does not announce the calls it finds when it first opens (a reload must not repeat the day)', async () => {
		await open(feed([llamado(1, 'FAR-P-012')]))

		expect(announce).not.toHaveBeenCalled()
	})

	it('announces each new call with its spoken phrase and the pantalla sound settings, in order', async () => {
		await open(feed([llamado(1, 'FAR-P-012')]))
		await next(feed([llamado(2, 'FAR-P-013'), llamado(3, 'MUE-G-006', 'Box 5')]))

		expect(announce).toHaveBeenCalledTimes(2)
		expect(announce).toHaveBeenNthCalledWith(1, { text: 'Turno FAR-P-013. Diríjase a Box 2.', volume: 80, voice: 'mujer' })
		expect(announce).toHaveBeenNthCalledWith(2, { text: 'Turno MUE-G-006. Diríjase a Box 5.', volume: 80, voice: 'mujer' })
	})

	it('warms the voice up as soon as it knows the pantalla, so the first call is not slowed by loading the model', async () => {
		await open(feed([]))

		expect(warmUp).toHaveBeenCalledTimes(1)
		expect(warmUp).toHaveBeenCalledWith('mujer')
	})

	it('warms nothing up for a pantalla without voice or sound', async () => {
		readFeed.mockResolvedValueOnce({ pantalla: { ...pantalla, voice: 'off' }, llamados: [], cursor: 0 })
		mount(DisplayView)
		await flushPromises()

		expect(warmUp).not.toHaveBeenCalled()
	})

	it('asks only for what is new: the next poll starts after the cursor it was given', async () => {
		await open(feed([llamado(7, 'FAR-P-012')], 7))
		await next(feed([], 7))

		expect(readFeed).toHaveBeenNthCalledWith(1, 'sala_farmacia', 0)
		expect(readFeed).toHaveBeenNthCalledWith(2, 'sala_farmacia', 7)
	})

	it('pulses a gold frame for six seconds after a new call, then stops', async () => {
		const wrapper = await open(feed([llamado(1, 'FAR-P-012')]))
		expect(wrapper.get('[role="status"]').classes()).not.toContain('pulse')

		await next(feed([llamado(2, 'FAR-P-013')]))
		expect(wrapper.get('[role="status"]').classes()).toContain('pulse')

		await vi.advanceTimersByTimeAsync(6000)
		expect(wrapper.get('[role="status"]').classes()).not.toContain('pulse')
	})

	it('writes what is said in the footer, with the sound state', async () => {
		const wrapper = await open(feed([llamado(1, 'FAR-P-012', 'Box 2')]))

		expect(wrapper.get('.d-foot').text()).toContain('Sonido y voz activados · Turno FAR-P-012. Diríjase a Box 2.')
	})

	it('says plainly when the pantalla has no voice or no sound', async () => {
		readFeed.mockResolvedValueOnce({ pantalla: { ...pantalla, voice: 'off' }, llamados: [llamado(1, 'FAR-P-012')], cursor: 1 })
		const quiet = mount(DisplayView)
		await flushPromises()
		expect(quiet.get('.d-foot').text()).toContain('Sonido activado ·')
		quiet.unmount()

		readFeed.mockResolvedValueOnce({ pantalla: { ...pantalla, volume: 0 }, llamados: [llamado(1, 'FAR-P-012')], cursor: 1 })
		const mute = mount(DisplayView)
		await flushPromises()
		expect(mute.get('.d-foot').text()).toContain('Sonido apagado ·')
	})

	it('says it lost the server and keeps showing the last call, then recovers by itself', async () => {
		const wrapper = await open(feed([llamado(1, 'FAR-P-012')]))
		readFeed.mockRejectedValueOnce(new Error('down'))
		await vi.advanceTimersByTimeAsync(2000)
		await flushPromises()

		expect(wrapper.text()).toContain('Sin conexión con el servidor. Reintentando…')
		expect(wrapper.get('[role="status"]').text()).toContain('FAR-P-012')

		readFeed.mockResolvedValueOnce(feed([], 1))
		await vi.advanceTimersByTimeAsync(4000)
		await flushPromises()
		expect(wrapper.text()).not.toContain('Sin conexión')
	})

	it('asks for one touch when the browser is holding the sound back, and unlocks it', async () => {
		audioReady.mockReturnValue(false)
		const wrapper = await open(feed([llamado(1, 'FAR-P-012')]))

		const button = wrapper.get('button.d-unlock')
		expect(button.text()).toBe('Toque aquí para activar el sonido')
		await button.trigger('click')
		await flushPromises()

		expect(unlockAudio).toHaveBeenCalled()
		expect(wrapper.find('button.d-unlock').exists()).toBe(false)
	})

	it('does not ask for a touch when sound already works', async () => {
		const wrapper = await open(feed([llamado(1, 'FAR-P-012')]))

		expect(wrapper.find('button.d-unlock').exists()).toBe(false)
	})
})
