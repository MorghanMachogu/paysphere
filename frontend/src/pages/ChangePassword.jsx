import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Alert, Button, Paper, TextField, Typography } from '@mui/material'
import { post } from '../api'
import { useAuth } from '../auth'
import { useNotify } from '../notify'
import { bind } from '../components/ui'
import { AuthShell } from './Login'

export default function ChangePassword() {
  const { user, setUser, logout } = useAuth()
  const notify = useNotify()
  const navigate = useNavigate()
  const [form, setForm] = useState({})
  const [errors, setErrors] = useState({})
  const [busy, setBusy] = useState(false)
  const f = (n) => bind(form, setForm, errors, n)

  const submit = async (e) => {
    e.preventDefault()
    if (form.new_password !== form.confirm) return setErrors({ confirm: 'Passwords do not match' })
    setBusy(true)
    try {
      const res = await post('/auth.php?action=change_password',
        { current_password: form.current_password, new_password: form.new_password })
      setUser(res.data)
      notify('Password changed')
      navigate(res.data.user_type === 'employee' ? '/portal' : '/', { replace: true })
    } catch (err) {
      setErrors(err.errors?.current_password || err.errors?.new_password || err.errors?.password
        ? { ...err.errors, new_password: err.errors.new_password || err.errors.password } : { current_password: err.message })
    } finally {
      setBusy(false)
    }
  }

  return (
    <AuthShell>
      <Typography variant="h4" component="h1">Choose a new password</Typography>
      {user.force_password_change && (
        <Alert severity="info" sx={{ mt: 2 }}>You are using a temporary password. Choose your own before continuing.</Alert>
      )}
      <Paper component="form" onSubmit={submit} sx={{ p: 3, mt: 3, display: 'grid', gap: 2 }}>
        <TextField label={user.force_password_change ? 'Temporary password' : 'Current password'} type="password"
          required autoComplete="current-password" {...f('current_password')} />
        <TextField label="New password" type="password" required autoComplete="new-password" {...f('new_password')}
          helperText={errors.new_password || 'At least 8 characters with letters and numbers'} />
        <TextField label="Confirm new password" type="password" required autoComplete="new-password" {...f('confirm')} />
        <Button type="submit" variant="contained" size="large" disabled={busy}>{busy ? 'Saving…' : 'Save password'}</Button>
        <Button onClick={async () => { await logout(); navigate('/login') }}>Log out</Button>
      </Paper>
    </AuthShell>
  )
}
