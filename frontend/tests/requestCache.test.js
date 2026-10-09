import assert from 'node:assert/strict'
import test from 'node:test'
import { createRequestCache } from '../src/lib/requestCache.js'

test('deduplicates concurrent requests and serves a fresh cached result', async () => {
  let now = 100
  let calls = 0
  const cache = createRequestCache({ now: () => now })
  const loader = async () => ({ call: ++calls })

  const [first, second] = await Promise.all([
    cache.get('project:1', loader, { account: 'a' }),
    cache.get('project:1', loader, { account: 'a' }),
  ])
  const third = await cache.get('project:1', loader, { account: 'a' })

  assert.equal(calls, 1)
  assert.deepEqual(first, second)
  assert.deepEqual(third, first)
  now += 30001
  await cache.get('project:1', loader, { account: 'a' })
  assert.equal(calls, 2)
})

test('invalidating or changing account scope prevents an old response entering the cache', async () => {
  let resolveOld
  let calls = 0
  const cache = createRequestCache()
  const old = cache.get('project:1', () => new Promise((resolve) => { resolveOld = resolve }), { account: 'a' })
  await Promise.resolve()
  cache.invalidate()
  resolveOld({ source: 'old' })
  await old

  const fresh = await cache.get('project:1', async () => ({ source: ++calls }), { account: 'a' })
  assert.deepEqual(fresh, { source: 1 })

  const otherAccount = await cache.get('project:1', async () => ({ source: ++calls }), { account: 'b' })
  assert.deepEqual(otherAccount, { source: 2 })
})
