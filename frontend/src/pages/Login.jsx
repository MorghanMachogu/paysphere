import { useState } from 'react'
import { Link as RouterLink, useLocation, useNavigate } from 'react-router-dom'
import { Alert, Box, Button, IconButton, InputAdornment, Link, Paper, TextField, Typography } from '@mui/material'
import { Visibility, VisibilityOff } from '@mui/icons-material'
import { useAuth } from '../auth'
import Logo from '../components/Logo'
import { tokens } from '../theme'

export function AuthShell({ children, wide = false }) {
  return (
    <Box sx={{ minHeight: '100vh', display: 'grid', gridTemplateColumns: { md: '1fr 1fr' } }}>
      <Box sx={{ display: { xs: 'none', md: 'flex' }, bgcolor: tokens.ink, color: '#fff', p: 6,
        flexDirection: 'column', justifyContent: 'space-between' }}>
        <Logo light size={24} />
        <Box sx={{ maxWidth: 420 }}>
          <Typography sx={{ fontSize: 34, fontWeight: 600, lineHeight: 1.2 }}>
            Payroll that keeps up with KRA, NSSF and SHA, so you don’t have to.
          </Typography>
          <Typography sx={{ mt: 2, opacity: 0.8, lineHeight: 1.6 }}>
            PAYE, NSSF, SHIF and the Housing Levy calculated for every employee, payslips your staff can open on
            their phones, and leave tracked in one place.
          </Typography>
        </Box>
        <Typography sx={{ fontSize: 13, opacity: 0.6 }}>Built for Kenyan SMEs</Typography>
      </Box>
      <Box sx={{ display: 'grid', placeItems: 'center', p: 3 }}>
        <Box sx={{ width: '100%', maxWidth: wide ? 560 : 400 }}>
          <Box sx={{ display: { md: 'none' }, mb: 3 }}><Logo size={22} /></Box>
          {children}
        </Box>
      </Box>
    </Box>
  )
}

export default function Login() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [form, setForm] = useState({ username: '', password: '' })
  const [show, setShow] = useState(false)
  const [error, setError] = useState(location.state?.message ? null : '')
  const [busy, setBusy] = useState(false)

  const submit = async (e) => {
    e.preventDefault()
    setBusy(true)
    setError('')
    try {
      const user = await login(form.username.trim(), form.password)
      if (user.force_password_change) navigate('/change-password', { replace: true })
      else if (user.user_type === 'employee') navigate('/portal', { replace: true })
      else navigate(location.state?.from && location.state.from !== '/login' ? location.state.from : '/', { replace: true })
    } catch (err) {
      setError(err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <AuthShell>
      <Typography variant="h4" component="h1">Log in</Typography>
      <Typography color="text.secondary" sx={{ mt: 0.5, mb: 3 }}>HR staff and employees use the same login.</Typography>
      {location.state?.message && <Alert severity="success" sx={{ mb: 2 }}>{location.state.message}</Alert>}
      {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
      <Paper component="form" onSubmit={submit} sx={{ p: 3, display: 'grid', gap: 2 }}>
        <TextField label="Username" autoComplete="username" autoFocus required value={form.username}
          onChange={(e) => setForm({ ...form, username: e.target.value })} />
        <TextField label="Password" type={show ? 'text' : 'password'} autoComplete="current-password" required
          value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })}
          InputProps={{ endAdornment: (
            <InputAdornment position="end">
              <IconButton onClick={() => setShow(!show)} edge="end" aria-label={show ? 'Hide password' : 'Show password'}>
                {show ? <VisibilityOff /> : <Visibility />}
              </IconButton>
            </InputAdornment>) }} />
        <Button type="submit" variant="contained" size="large" disabled={busy}>{busy ? 'Logging in…' : 'Log in'}</Button>
      </Paper>
      <Typography sx={{ mt: 3, fontSize: 14 }} color="text.secondary">
        New to PaySphere? <Link component={RouterLink} to="/signup">Register your organisation</Link>
      </Typography>
      <Typography sx={{ mt: 1, fontSize: 13 }} color="text.secondary">
        Employees: forgot your password? Ask your HR office to reset it.
      </Typography>
    </AuthShell>
  )
}
