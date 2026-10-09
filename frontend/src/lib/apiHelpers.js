import { apiBaseUrl } from './apiConfig'

const SELECTED_PROJECT_KEY = 'selected_project_id'

export function unwrapList(payload) {
  if (Array.isArray(payload)) return payload
  if (Array.isArray(payload?.data)) return payload.data
  return []
}

export function unwrapItem(payload) {
  if (payload?.data && typeof payload.data === 'object' && !Array.isArray(payload.data)) {
    return payload.data
  }
  return payload
}

export function getStoredProjectId() {
  const raw = localStorage.getItem(SELECTED_PROJECT_KEY)
  return raw ? Number(raw) : null
}

export function storeProjectId(id) {
  if (id == null) {
    localStorage.removeItem(SELECTED_PROJECT_KEY)
    return
  }
  localStorage.setItem(SELECTED_PROJECT_KEY, String(id))
}

export function apiStatusToUi(status) {
  const map = {
    Pending: 'pending',
    Ongoing: 'ongoing',
    Completed: 'completed',
    pending: 'pending',
    ongoing: 'ongoing',
    completed: 'completed',
    not_started: 'not_started',
  }
  return map[status] ?? 'pending'
}

export function apiTestResultToUi(result) {
  if (!result) return 'not_tested'
  const map = { Pass: 'pass', Fail: 'fail', pass: 'pass', fail: 'fail' }
  return map[result] ?? 'not_tested'
}

export function uiTestResultToApi(result) {
  if (result === 'pass' || result === 'Pass') return 'Pass'
  if (result === 'fail' || result === 'Fail') return 'Fail'
  return null
}

export function datesToImplementationStatus({ actual_start_date, actual_end_date }) {
  if (actual_end_date) return 'Completed'
  if (actual_start_date) return 'Ongoing'
  return 'Pending'
}

export function documentFileUrl(documentId) {
  return `${apiBaseUrl.replace(/\/$/, '')}/documents/${documentId}/file`
}

export async function fetchAuthorizedFileUrl(documentId) {
  const token = localStorage.getItem('auth_token')
  const response = await fetch(documentFileUrl(documentId), {
    headers: token ? { Authorization: `Bearer ${token}` } : {},
  })
  if (!response.ok) throw new Error('Could not load file')
  const blob = await response.blob()
  return URL.createObjectURL(blob)
}
