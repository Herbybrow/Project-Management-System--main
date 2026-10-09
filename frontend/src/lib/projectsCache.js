import { fetchCached } from './cachedApi'
import { requestCache } from './requestCache'

export function fetchProjectsCached(params) {
  return fetchCached('/projects', { params, ttl: 30000 })
}

export function invalidateProjectsCache() {
  requestCache.invalidate()
}
