import { createContext, useCallback, useContext, useEffect, useState } from 'react'
import { get, post, getToken, setToken } from './api'

const AuthContext = createContext(null)
export const useAuth = () => useContext(AuthContext)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(Boolean(getToken()))

  const refresh = useCallback(async () => {
    if (!getToken()) {
      setUser(null)
      setLoading(false)
      return
    }
    try {
      setUser(await get('/auth.php', { action: 'me' }))
    } catch {
      setToken(null)
      setUser(null)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    refresh()
    const onLogout = () => setUser(null)
    window.addEventListener('paysphere:logout', onLogout)
    return () => window.removeEventListener('paysphere:logout', onLogout)
  }, [refresh])

  const login = async (username, password) => {
    const res = await post('/auth.php?action=login', { username, password })
    setToken(res.data.token)
    setUser(res.data.user)
    return res.data.user
  }

  const logout = async () => {
    try {
      await post('/auth.php?action=logout')
    } catch {
      /* token may already be invalid */
    }
    setToken(null)
    setUser(null)
  }

  const can = (permission) => Boolean(user?.permissions?.includes(permission))

  return (
    <AuthContext.Provider value={{ user, setUser, loading, login, logout, refresh, can }}>
      {children}
    </AuthContext.Provider>
  )
}
