import { Alert, Box, Button, Chip, CircularProgress, MenuItem, Paper, TextField, Typography } from '@mui/material'
import { MONTHS } from '../format'

export function PageHeader({ title, subtitle, actions }) {
  return (
    <Box sx={{ display: 'flex', alignItems: { sm: 'flex-end' }, justifyContent: 'space-between', gap: 2, mb: 3,
      flexDirection: { xs: 'column', sm: 'row' } }}>
      <Box>
        <Typography variant="h4" component="h1">{title}</Typography>
        {subtitle && <Typography component="div" color="text.secondary" sx={{ mt: 0.5 }}>{subtitle}</Typography>}
      </Box>
      {actions && <Box sx={{ display: 'flex', gap: 1, flexWrap: 'wrap' }}>{actions}</Box>}
    </Box>
  )
}

export function Loading({ label = 'Loading' }) {
  return <Box sx={{ py: 8, display: 'grid', placeItems: 'center' }}><CircularProgress aria-label={label} /></Box>
}

export function ErrorState({ error, onRetry }) {
  return (
    <Alert severity="error" action={onRetry && <Button color="inherit" size="small" onClick={onRetry}>Try again</Button>}>
      {error?.message || 'Something went wrong.'}
    </Alert>
  )
}

export function Empty({ title, children, action }) {
  return (
    <Paper sx={{ p: 5, textAlign: 'center' }}>
      <Typography variant="h6">{title}</Typography>
      {children && <Typography color="text.secondary" sx={{ mt: 1, maxWidth: 480, mx: 'auto' }}>{children}</Typography>}
      {action && <Box sx={{ mt: 2.5 }}>{action}</Box>}
    </Paper>
  )
}

const STATUS = {
  active: ['Active', 'success'], inactive: ['Inactive', 'default'], terminated: ['Terminated', 'default'],
  draft: ['Draft', 'warning'], approved: ['Approved', 'primary'], paid: ['Paid', 'success'],
  pending: ['Pending', 'warning'], rejected: ['Rejected', 'error'], cancelled: ['Cancelled', 'default'],
  failed: ['Failed', 'error'], present: ['Present', 'success'], late: ['Late', 'warning'], absent: ['Absent', 'error'],
  on_leave: ['On leave', 'primary'], half_day: ['Half day', 'warning'],
}
export function StatusChip({ status, size = 'small' }) {
  const [label, color] = STATUS[status] || [status, 'default']
  return <Chip label={label} color={color} size={size} variant={color === 'default' ? 'outlined' : 'filled'} />
}

export function MonthPicker({ month, year, onChange }) {
  const thisYear = new Date().getFullYear()
  return (
    <Box sx={{ display: 'flex', gap: 1 }}>
      <TextField select label="Month" value={month} sx={{ width: 150 }}
        onChange={(e) => onChange({ month: Number(e.target.value), year })}>
        {MONTHS.map((m, i) => <MenuItem key={m} value={i + 1}>{m}</MenuItem>)}
      </TextField>
      <TextField select label="Year" value={year} sx={{ width: 110 }}
        onChange={(e) => onChange({ month, year: Number(e.target.value) })}>
        {[thisYear + 1, thisYear, thisYear - 1, thisYear - 2].map((y) => <MenuItem key={y} value={y}>{y}</MenuItem>)}
      </TextField>
    </Box>
  )
}

export function Stat({ label, value, hint, color }) {
  return (
    <Paper sx={{ p: 2.25, height: '100%' }}>
      <Typography color="text.secondary" sx={{ fontSize: 13.5 }}>{label}</Typography>
      <Typography sx={{ fontSize: 26, fontWeight: 600, mt: 0.5, color }}>{value}</Typography>
      {hint && <Typography color="text.secondary" sx={{ fontSize: 12.5, mt: 0.25 }}>{hint}</Typography>}
    </Paper>
  )
}

/** Field props for MUI TextField from a form-state object and server errors. */
export function bind(form, setForm, errors, name) {
  return {
    name,
    value: form[name] ?? '',
    onChange: (e) => setForm((f) => ({ ...f, [name]: e.target.value })),
    error: Boolean(errors?.[name]),
    helperText: errors?.[name] || undefined,
  }
}
