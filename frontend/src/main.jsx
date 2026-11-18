import React from 'react'
import ReactDOM from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from 'react-query'
import { CssBaseline, ThemeProvider } from '@mui/material'
import theme from './theme'
import App from './App'
import { AuthProvider } from './auth'
import { NotifyProvider } from './notify'

const queryClient = new QueryClient({
  defaultOptions: { queries: { refetchOnWindowFocus: false, retry: (count, err) => err?.status >= 500 && count < 1 } },
})

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <QueryClientProvider client={queryClient}>
      <ThemeProvider theme={theme}>
        <CssBaseline />
        <BrowserRouter future={{ v7_startTransition: true, v7_relativeSplatPath: true }}>
          <NotifyProvider>
            <AuthProvider>
              <App />
            </AuthProvider>
          </NotifyProvider>
        </BrowserRouter>
      </ThemeProvider>
    </QueryClientProvider>
  </React.StrictMode>,
)
