import api from './axios'
import { requestCache } from './requestCache'

export function fetchCached(url, { params = {}, ttl = 30000, force = false } = {}) {
  const sortedParams = Object.fromEntries(Object.entries(params).sort(([a], [b]) => a.localeCompare(b)))
  const key = JSON.stringify([url, sortedParams])
  return requestCache.get(key, () => api.get(url, { params }), {
    ttl, force, account: localStorage.getItem('auth_token') || '',
  })
}
