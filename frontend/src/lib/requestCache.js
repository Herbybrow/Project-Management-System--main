// Short-lived, in-memory GET caching. No API data is written to browser storage.
export function createRequestCache({ now = Date.now, maxEntries = 100 } = {}) {
  const entries = new Map()
  const pending = new Map()
  const listeners = new Set()
  let generation = 0
  let scope

  function invalidate() {
    generation += 1
    entries.clear()
    pending.clear()
    listeners.forEach((listener) => listener())
  }

  return {
    invalidate,
    getRevision: () => generation,
    subscribe(listener) {
      listeners.add(listener)
      return () => listeners.delete(listener)
    },
    get(key, loader, { ttl = 30000, account = '', force = false } = {}) {
      if (scope !== account) {
        scope = account
        invalidate()
      }
      const cached = entries.get(key)
      if (!force && cached && now() - cached.time < ttl) return Promise.resolve(cached.value)
      if (pending.has(key)) return pending.get(key)

      const version = generation
      const request = Promise.resolve().then(loader).then((value) => {
        if (version === generation) {
          if (entries.size >= maxEntries) entries.delete(entries.keys().next().value)
          entries.set(key, { value, time: now() })
        }
        return value
      }).finally(() => {
        // An older request must not remove a newer request after invalidation.
        if (pending.get(key) === request) pending.delete(key)
      })
      pending.set(key, request)
      return request
    },
  }
}

export const requestCache = createRequestCache()
