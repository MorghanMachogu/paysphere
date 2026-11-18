import { useState } from 'react'
import { Link as RouterLink, useNavigate } from 'react-router-dom'
import { Alert, Box, Button, Grid, Link, Paper, Step, StepLabel, Stepper, TextField, Typography } from '@mui/material'
import { post } from '../api'
import { bind } from '../components/ui'
import { AuthShell } from './Login'

const STEPS = ['Organisation', 'Administrator', 'Review']
const STEP_FIELDS = [
  ['organization_name', 'kra_pin', 'organization_email', 'phone', 'county', 'address'],
  ['admin_first_name', 'admin_last_name', 'admin_email', 'admin_phone', 'username', 'password', 'confirm'],
]

export default function Signup() {
  const navigate = useNavigate()
  const [step, setStep] = useState(0)
  const [form, setForm] = useState({})
  const [errors, setErrors] = useState({})
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const f = (name) => bind(form, setForm, errors, name)

  const check = (s) => {
    const e = {}
    const req = s === 0 ? ['organization_name', 'organization_email']
      : ['admin_first_name', 'admin_last_name', 'admin_email', 'username', 'password', 'confirm']
    req.forEach((k) => { if (!String(form[k] || '').trim()) e[k] = 'Required' })
    if (s === 0 && form.kra_pin && !/^[AP]\d{9}[A-Z]$/i.test(form.kra_pin)) e.kra_pin = 'Format: P051234567X'
    if (s === 1 && form.password && (form.password.length < 8 || !/\d/.test(form.password) || !/[a-z]/i.test(form.password)))
      e.password = 'At least 8 characters with letters and numbers'
    if (s === 1 && form.confirm && form.confirm !== form.password) e.confirm = 'Passwords do not match'
    setErrors(e)
    return Object.keys(e).length === 0
  }

  const submit = async () => {
    setBusy(true)
    setError('')
    try {
      const { confirm, ...body } = form
      const res = await post('/organization_signup.php', body)
      navigate('/login', { state: { message: `${res.message} Your username is ${res.data.username}.` } })
    } catch (err) {
      setError(err.message)
      setErrors(err.errors)
      const back = STEP_FIELDS.findIndex((fields) => fields.some((k) => err.errors?.[k]))
      if (back >= 0) setStep(back)
    } finally {
      setBusy(false)
    }
  }

  return (
    <AuthShell wide>
      <Typography variant="h4" component="h1">Register your organisation</Typography>
      <Typography color="text.secondary" sx={{ mt: 0.5, mb: 3 }}>Free 30-day trial for up to 25 employees.</Typography>
      <Stepper activeStep={step} sx={{ mb: 3 }}>{STEPS.map((s) => <Step key={s}><StepLabel>{s}</StepLabel></Step>)}</Stepper>
      {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
      <Paper sx={{ p: 3 }}>
        {step === 0 && (
          <Grid container spacing={2}>
            <Grid item xs={12}><TextField label="Business name" required {...f('organization_name')} /></Grid>
            <Grid item xs={12} sm={6}><TextField label="KRA PIN" {...f('kra_pin')} placeholder="P051234567X" /></Grid>
            <Grid item xs={12} sm={6}><TextField label="Business email" type="email" required {...f('organization_email')} /></Grid>
            <Grid item xs={12} sm={6}><TextField label="Phone" {...f('phone')} /></Grid>
            <Grid item xs={12} sm={6}><TextField label="County" {...f('county')} /></Grid>
            <Grid item xs={12}><TextField label="Physical address" {...f('address')} /></Grid>
          </Grid>
        )}
        {step === 1 && (
          <Grid container spacing={2}>
            <Grid item xs={12} sm={6}><TextField label="First name" required {...f('admin_first_name')} /></Grid>
            <Grid item xs={12} sm={6}><TextField label="Last name" required {...f('admin_last_name')} /></Grid>
            <Grid item xs={12} sm={6}><TextField label="Email" type="email" required {...f('admin_email')} /></Grid>
            <Grid item xs={12} sm={6}><TextField label="Phone" {...f('admin_phone')} /></Grid>
            <Grid item xs={12}><TextField label="Username" required {...f('username')} autoComplete="username" /></Grid>
            <Grid item xs={12} sm={6}><TextField label="Password" type="password" required {...f('password')} autoComplete="new-password" /></Grid>
            <Grid item xs={12} sm={6}><TextField label="Confirm password" type="password" required {...f('confirm')} autoComplete="new-password" /></Grid>
          </Grid>
        )}
        {step === 2 && (
          <Box sx={{ display: 'grid', gap: 1, fontSize: 15 }}>
            <Typography><b>{form.organization_name}</b>{form.kra_pin ? `, KRA PIN ${form.kra_pin.toUpperCase()}` : ''}</Typography>
            <Typography color="text.secondary">{form.organization_email}</Typography>
            <Typography sx={{ mt: 1 }}>Administrator: {form.admin_first_name} {form.admin_last_name} ({form.username})</Typography>
            <Typography color="text.secondary" sx={{ mt: 1, fontSize: 14 }}>
              We will create default departments, positions and leave types (21 days annual leave). You can change them in Settings.
            </Typography>
          </Box>
        )}
        <Box sx={{ display: 'flex', justifyContent: 'space-between', mt: 3 }}>
          <Button disabled={step === 0} onClick={() => setStep(step - 1)}>Back</Button>
          {step < 2
            ? <Button variant="contained" onClick={() => check(step) && setStep(step + 1)}>Continue</Button>
            : <Button variant="contained" onClick={submit} disabled={busy}>{busy ? 'Registering…' : 'Register organisation'}</Button>}
        </Box>
      </Paper>
      <Typography sx={{ mt: 3, fontSize: 14 }} color="text.secondary">
        Already registered? <Link component={RouterLink} to="/login">Log in</Link>
      </Typography>
    </AuthShell>
  )
}
