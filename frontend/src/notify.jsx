import { createContext, useCallback, useContext, useState } from 'react'
import { Alert, Snackbar } from '@mui/material'

const NotifyContext = createContext(() => {})
export const useNotify = () => useContext(NotifyContext)

/** notify('Saved') or notify(error, 'error') */
export function NotifyProvider({ children }) {
  const [msg, setMsg] = useState(null)
  const notify = useCallback((text, severity = 'success') => {
    setMsg({ text: text instanceof Error ? text.message : text, severity: text instanceof Error ? 'error' : severity, key: Date.now() })
  }, [])
  return (
    <NotifyContext.Provider value={notify}>
      {children}
      <Snackbar key={msg?.key} open={Boolean(msg)} autoHideDuration={msg?.severity === 'error' ? 7000 : 4000}
        onClose={() => setMsg(null)} anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}>
        {msg ? <Alert severity={msg.severity} variant="filled" onClose={() => setMsg(null)}>{msg.text}</Alert> : <span />}
      </Snackbar>
    </NotifyContext.Provider>
  )
}
