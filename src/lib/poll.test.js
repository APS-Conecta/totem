import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { poll } from './poll.js'

describe('poll', () => {
	beforeEach(() => vi.useFakeTimers())
	afterEach(() => vi.useRealTimers())

	it('loads at once, then every interval, handing each result on', async () => {
		const load = vi.fn().mockResolvedValueOnce(1).mockResolvedValueOnce(2).mockResolvedValue(3)
		const onData = vi.fn()
		const { stop } = poll({ load, onData, every: 2000 })

		await vi.advanceTimersByTimeAsync(0)
		expect(onData).toHaveBeenLastCalledWith(1)
		await vi.advanceTimersByTimeAsync(2000)
		expect(onData).toHaveBeenLastCalledWith(2)
		await vi.advanceTimersByTimeAsync(2000)
		expect(onData).toHaveBeenLastCalledWith(3)
		stop()
	})

	it('says offline once on failure and backs off, doubling up to the ceiling', async () => {
		const load = vi.fn().mockRejectedValue(new Error('down'))
		const onOffline = vi.fn()
		const { stop } = poll({ load, onData: vi.fn(), onOffline, every: 2000, maxBackoff: 8000 })

		await vi.advanceTimersByTimeAsync(0) // attempt 1, next in 4 s
		expect(load).toHaveBeenCalledTimes(1)
		await vi.advanceTimersByTimeAsync(3999)
		expect(load).toHaveBeenCalledTimes(1)
		await vi.advanceTimersByTimeAsync(1) // attempt 2, next in 8 s
		expect(load).toHaveBeenCalledTimes(2)
		await vi.advanceTimersByTimeAsync(8000) // attempt 3, next in 8 s (ceiling)
		expect(load).toHaveBeenCalledTimes(3)
		await vi.advanceTimersByTimeAsync(8000)
		expect(load).toHaveBeenCalledTimes(4)
		expect(onOffline).toHaveBeenCalledTimes(1)
		stop()
	})

	it('says online once on recovery and goes back to the normal interval', async () => {
		const load = vi.fn().mockRejectedValueOnce(new Error('down')).mockResolvedValue('ok')
		const onOnline = vi.fn()
		const onData = vi.fn()
		const { stop } = poll({ load, onData, onOffline: vi.fn(), onOnline, every: 2000 })

		await vi.advanceTimersByTimeAsync(0)
		await vi.advanceTimersByTimeAsync(4000) // the retry succeeds
		expect(onOnline).toHaveBeenCalledTimes(1)
		expect(onData).toHaveBeenCalledWith('ok')
		await vi.advanceTimersByTimeAsync(2000) // and the next one is 2 s away again
		expect(load).toHaveBeenCalledTimes(3)
		expect(onOnline).toHaveBeenCalledTimes(1)
		stop()
	})

	it('stops asking once stopped', async () => {
		const load = vi.fn().mockResolvedValue(1)
		const { stop } = poll({ load, onData: vi.fn(), every: 2000 })

		await vi.advanceTimersByTimeAsync(0)
		stop()
		await vi.advanceTimersByTimeAsync(10000)

		expect(load).toHaveBeenCalledTimes(1)
	})
})
