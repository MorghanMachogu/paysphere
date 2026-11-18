import axios from 'axios'

const TOKEN_KEY = 'paysphere_token'
export const getToken = () => localStorage.getItem(TOKEN_KEY)
export const setToken = (t) => (t ? localStorage.setItem(TOKEN_KEY, t) : localStorage.removeItem(TOKEN_KEY))

const http = axios.create({ baseURL: import.meta.env.VITE_API_BASE_URL || '/api', timeout: 30000 })

http.interceptors.request.use((config) => {
  const token = getToken()
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

http.interceptors.response.use(
  (res) => res.data,
  (err) => {
    const status = err.response?.status
    const body = err.response?.data || {}
    if (status === 401 && !String(err.config?.url).includes('action=login')) {
      setToken(null)
      window.dispatchEvent(new Event('paysphere:logout'))
    }
    const error = new Error(body.message || 'Could not reach the PaySphere server. Check your connection and try again.')
    error.status = status
    error.errors = body.errors || {}
    return Promise.reject(error)
  },
)

/** GET returning the `data` part of the response. */
export const get = (url, params) => http.get(url, { params }).then((r) => r.data)
/** POST/PUT/DELETE returning the whole body: { success, message, data }. */
export const post = (url, body) => http.post(url, body ?? {})
export const put = (url, body) => http.put(url, body ?? {})
export const del = (url) => http.delete(url)

/** Opens a printable document (payslip or report) in a new tab, sending the auth token. */
export async function openDocument(url) {
  const win = window.open('', '_blank')
  if (win) win.document.write('<p style="font-family:sans-serif;padding:24px">Loading document…</p>')
  try {
    const html = await http.get(url, { responseType: 'text' })
    if (win) {
      win.document.open()
      win.document.write(html)
      win.document.close()
    }
  } catch (e) {
    if (win) win.close()
    throw e
  }
}

/** Downloads a file (CSV export) with the auth token. */
export async function downloadFile(url, filename) {
  const blob = await http.get(url, { responseType: 'blob' })
  const link = document.createElement('a')
  link.href = URL.createObjectURL(blob)
  link.download = filename
  link.click()
  URL.revokeObjectURL(link.href)
}
