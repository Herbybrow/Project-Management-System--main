import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import api from '../lib/axios'
import { requestCache } from '../lib/requestCache'
import { getAssignedRoles } from '../layouts/nav'

const AuthContext = createContext(null)
const STORAGE_ROLE_ID = 'currentRole'
const STORAGE_ROLE_CODES = 'roleCodes'

function readStoredRoleId() {
  try {
    const raw = sessionStorage.getItem(STORAGE_ROLE_ID)
    if (raw == null || raw === 'undefined') return null
    return JSON.parse(raw)
  } catch {
    return null
  }
}

function persistActiveRole(role) {
  if (!role) {
    sessionStorage.removeItem(STORAGE_ROLE_ID)
    sessionStorage.removeItem(STORAGE_ROLE_CODES)
    return
  }
  sessionStorage.setItem(STORAGE_ROLE_ID, JSON.stringify(role.id ?? role.name))
  sessionStorage.setItem(STORAGE_ROLE_CODES, JSON.stringify([role.name]))
}

function resolveActiveRole(user) {
  const assigned = getAssignedRoles(user)
  if (!assigned.length) return null
  const storedId = readStoredRoleId()
  return assigned.find((role) => String(role.id) === String(storedId) || role.name === storedId) ?? assigned[0]
}

export const AuthProvider = ({ children }) => {
  const [user, setUser] = useState(() => {
    const raw = localStorage.getItem('user_data')
    try {
      return raw ? JSON.parse(raw) : null
    } catch {
      return null
    }
  })
  const [token, setToken] = useState(() => localStorage.getItem('auth_token') || null)
  const [isLoadingAuth, setIsLoadingAuth] = useState(() => Boolean(localStorage.getItem('auth_token')))
  const [activeRole, setActiveRole] = useState(() => {
    try {
      const raw = localStorage.getItem('user_data')
      return resolveActiveRole(raw ? JSON.parse(raw) : null)
    } catch {
      return null
    }
  })

  useEffect(() => {
    const hasToken = Boolean(localStorage.getItem('auth_token'))

    if (!hasToken) {
      setIsLoadingAuth(false)
      return
    }

    ;(async () => {
      try {
        const resp = await api.get('/me')
        const meUser = resp.data?.data ?? resp.data?.user ?? null
        if (!meUser) throw new Error('No user returned from /me')

        setUser(meUser)
        setToken(localStorage.getItem('auth_token'))
        localStorage.setItem('user_data', JSON.stringify(meUser))
      } catch {
        requestCache.invalidate()
        persistActiveRole(null)
        localStorage.clear()
        setActiveRole(null)
        setUser(null)
        setToken(null)
      } finally {
        setIsLoadingAuth(false)
      }
    })()
  }, [])

  useEffect(() => {
    const next = resolveActiveRole(user)
    setActiveRole(next)
    persistActiveRole(next)
  }, [user])

  const login = (userData, authToken) => {
    requestCache.invalidate()
    setUser(userData)
    setToken(authToken)
    localStorage.setItem('user_data', JSON.stringify(userData))
    localStorage.setItem('auth_token', authToken)
  }

  const switchRole = useCallback((role) => {
    if (!role?.name) return
    persistActiveRole(role)
    setActiveRole(role)
  }, [])

  const logout = async () => {
    try {
      await api.post('/logout')
    } catch {
      // Even if server logout fails, clear the client session.
    } finally {
      requestCache.invalidate()
      persistActiveRole(null)
      setActiveRole(null)
      setUser(null)
      setToken(null)
      localStorage.clear()
    }
  }

  const isAuthenticated = Boolean(token)
  const assignedRoles = useMemo(() => getAssignedRoles(user), [user])

  const value = useMemo(
    () => ({
      user,
      token,
      login,
      logout,
      switchRole,
      activeRole,
      assignedRoles,
      isLoadingAuth,
      isAuthenticated,
    }),
    [user, token, switchRole, activeRole, assignedRoles, isLoadingAuth, isAuthenticated],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth() {
  const context = useContext(AuthContext)
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider')
  }
  return context
}
