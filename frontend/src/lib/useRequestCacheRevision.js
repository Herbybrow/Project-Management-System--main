import { useSyncExternalStore } from 'react'
import { requestCache } from './requestCache'

export function useRequestCacheRevision() {
  return useSyncExternalStore(requestCache.subscribe, requestCache.getRevision, requestCache.getRevision)
}
