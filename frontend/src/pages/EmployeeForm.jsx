import { useEffect, useState } from 'react'
import { useQuery, useQueryClient } from 'react-query'
import { useNavigate, useParams } from 'react-router-dom'
import { Alert, Box, Button, Dialog, DialogActions, DialogContent, DialogTitle, Grid, InputAdornment, MenuItem,
  Paper, TextField, Typography } from '@mui/material'
import { get, post, put } from '../api'
import { useNotify } from '../notify'
import { today } from '../format'
import { bind, ErrorState, Loading, PageHeader } from '../components/ui'

const SALARY = ['basic_salary', 'housing_allowance', 'transport_allowance', 'medical_allowance', 'other_allowances', 'other_deductions']

function Section({ title, children }) {
  return (
    <Paper sx={{ p: 2.5, mb: 2 }}>
      <Typography variant="h6" sx={{ mb: 2 }}>{title}</Typography>
      <Grid container spacing={2}>{children}</Grid>
    </Paper>
  )
}

export default function EmployeeForm() {
  const { id } = useParams()
  const editing = Boolean(id)
  const navigate = useNavigate()
  const notify = useNotify()
  const qc = useQueryClient()
  const [form, setForm] = useState({ employment_type: 'permanent', payment_method: 'mpesa', hire_date: today() })
  const [errors, setErrors] = useState({})
  const [busy, setBusy] = useState(false)
  const [created, setCreated] = useState(null)
  const f = (n) => bind(form, setForm, errors, n)

  const { data: departments = [] } = useQuery('departments', () => get('/departments.php'))
  const { data: positions = [] } = useQuery('positions', () => get('/positions.php'))
  const existing = useQuery(['employee', id], () => get('/employees.php', { id }), { enabled: editing })

  useEffect(() => {
    if (existing.data) {
      const e = existing.data
      const s = e.salary || {}
      setForm({ ...e, ...Object.fromEntries(SALARY.map((k) => [k, s[k] ?? ''])), salary_effective_date: today(),
        department_id: e.department_id ?? '', position_id: e.position_id ?? '' })
    }
  }, [existing.data])

  if (editing && existing.isLoading) return <Loading />
  if (editing && existing.error) return <ErrorState error={existing.error} />

  const submit = async (e) => {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    try {
      const body = { ...form }
      ;['salary', 'salary_history', 'payslips', 'department', 'position', 'username', 'last_login', 'login_active',
        'created_at', 'updated_at', 'organization_id', 'id'].forEach((k) => delete body[k])
      if (editing) {
        const res = await put(`/employees.php?id=${id}`, body)
        notify(res.message)
        qc.invalidateQueries('employees')
        qc.invalidateQueries(['employee', id])
        navigate(`/employees/${id}`)
      } else {
        const res = await post('/employees.php', body)
        qc.invalidateQueries('employees')
        setCreated(res.data)
      }
    } catch (err) {
      setErrors(err.errors)
      notify(err)
    } finally {
      setBusy(false)
    }
  }

  const money = (name, label, required) => (
    <Grid item xs={12} sm={6} md={4}>
      <TextField label={label} type="number" required={required} inputProps={{ min: 0, step: '0.01' }} {...f(name)}
        InputProps={{ startAdornment: <InputAdornment position="start">KES</InputAdornment> }} />
    </Grid>
  )

  return (
    <Box component="form" onSubmit={submit}>
      <PageHeader title={editing ? `Edit ${form.first_name || ''} ${form.last_name || ''}` : 'Add employee'}
        subtitle={editing ? form.employee_number : 'A login account is created automatically and the details are emailed to the employee.'} />
      {Object.keys(errors || {}).length > 0 && <Alert severity="error" sx={{ mb: 2 }}>Check the highlighted fields.</Alert>}

      <Section title="Personal details">
        <Grid item xs={12} sm={6}><TextField label="First name" required {...f('first_name')} /></Grid>
        <Grid item xs={12} sm={6}><TextField label="Last name" required {...f('last_name')} /></Grid>
        <Grid item xs={12} sm={4}><TextField label="National ID number" required {...f('national_id')} /></Grid>
        <Grid item xs={12} sm={4}><TextField label="Date of birth" type="date" InputLabelProps={{ shrink: true }} {...f('date_of_birth')} /></Grid>
        <Grid item xs={12} sm={4}>
          <TextField select label="Gender" {...f('gender')}>
            <MenuItem value="">Not specified</MenuItem><MenuItem value="female">Female</MenuItem>
            <MenuItem value="male">Male</MenuItem><MenuItem value="other">Other</MenuItem>
          </TextField>
        </Grid>
        <Grid item xs={12} sm={4}><TextField label="Mobile number" placeholder="0712345678" {...f('phone_number')} /></Grid>
        <Grid item xs={12} sm={4}><TextField label="Work email" type="email" {...f('work_email')} /></Grid>
        <Grid item xs={12} sm={4}><TextField label="Personal email" type="email" {...f('personal_email')} /></Grid>
      </Section>

      <Section title="Job">
        <Grid item xs={12} sm={6} md={3}>
          <TextField select label="Department" {...f('department_id')}>
            <MenuItem value="">None</MenuItem>
            {departments.filter((d) => d.is_active).map((d) => <MenuItem key={d.id} value={d.id}>{d.name}</MenuItem>)}
          </TextField>
        </Grid>
        <Grid item xs={12} sm={6} md={3}>
          <TextField select label="Position" {...f('position_id')}>
            <MenuItem value="">None</MenuItem>
            {positions.filter((p) => p.is_active).map((p) => <MenuItem key={p.id} value={p.id}>{p.title}</MenuItem>)}
          </TextField>
        </Grid>
        <Grid item xs={12} sm={6} md={3}>
          <TextField select label="Employment type" {...f('employment_type')}>
            <MenuItem value="permanent">Permanent</MenuItem><MenuItem value="contract">Contract</MenuItem>
            <MenuItem value="casual">Casual</MenuItem><MenuItem value="intern">Intern</MenuItem>
          </TextField>
        </Grid>
        <Grid item xs={12} sm={6} md={3}><TextField label="Hire date" type="date" required InputLabelProps={{ shrink: true }} {...f('hire_date')} /></Grid>
        {!editing && <Grid item xs={12} sm={6}><TextField label="Employee number" {...f('employee_number')} helperText={errors.employee_number || 'Leave blank to assign the next number (EMP001…)'} /></Grid>}
        {!editing && <Grid item xs={12} sm={6}><TextField label="Login username" {...f('username')} helperText={errors.username || 'Leave blank to use firstname.lastname'} /></Grid>}
        {editing && (
          <Grid item xs={12} sm={6} md={3}>
            <TextField select label="Status" {...f('status')}>
              <MenuItem value="active">Active</MenuItem><MenuItem value="inactive">Inactive</MenuItem>
              <MenuItem value="terminated">Terminated</MenuItem>
            </TextField>
          </Grid>
        )}
      </Section>

      <Section title="Statutory numbers">
        <Grid item xs={12} sm={4}><TextField label="KRA PIN" placeholder="A012345678Z" {...f('kra_pin')} /></Grid>
        <Grid item xs={12} sm={4}><TextField label="NSSF number" {...f('nssf_number')} /></Grid>
        <Grid item xs={12} sm={4}><TextField label="SHIF / SHA number" {...f('shif_number')} /></Grid>
      </Section>

      <Section title="Salary (monthly)">
        {money('basic_salary', 'Basic salary', true)}
        {money('housing_allowance', 'Housing allowance')}
        {money('transport_allowance', 'Transport allowance')}
        {money('medical_allowance', 'Medical allowance')}
        {money('other_allowances', 'Other allowances')}
        {money('other_deductions', 'Other deductions (loans, SACCO)')}
        {editing && (
          <Grid item xs={12} sm={6} md={4}>
            <TextField label="Salary change effective from" type="date" InputLabelProps={{ shrink: true }}
              {...f('salary_effective_date')} helperText="Only used if you changed an amount above" />
          </Grid>
        )}
      </Section>

      <Section title="Payment">
        <Grid item xs={12} sm={4}>
          <TextField select label="Pay by" {...f('payment_method')}>
            <MenuItem value="mpesa">M-Pesa</MenuItem><MenuItem value="bank_transfer">Bank transfer</MenuItem>
            <MenuItem value="cash">Cash</MenuItem><MenuItem value="cheque">Cheque</MenuItem>
          </TextField>
        </Grid>
        {form.payment_method === 'mpesa' && <Grid item xs={12} sm={4}><TextField label="M-Pesa number" required placeholder="0712345678" {...f('mpesa_number')} /></Grid>}
        {form.payment_method === 'bank_transfer' && (
          <>
            <Grid item xs={12} sm={4}><TextField label="Bank" required {...f('bank_name')} /></Grid>
            <Grid item xs={12} sm={4}><TextField label="Account number" required {...f('bank_account')} /></Grid>
          </>
        )}
      </Section>

      <Box sx={{ display: 'flex', gap: 1, justifyContent: 'flex-end' }}>
        <Button onClick={() => navigate(-1)}>Cancel</Button>
        <Button type="submit" variant="contained" disabled={busy}>{busy ? 'Saving…' : editing ? 'Save changes' : 'Add employee'}</Button>
      </Box>

      <Dialog open={Boolean(created)} onClose={() => navigate(`/employees/${created.employee.id}`)}>
        <DialogTitle>{created?.employee.first_name} has been added</DialogTitle>
        <DialogContent>
          <Typography>Share these login details with the employee. The temporary password is shown only once.</Typography>
          <Box sx={{ mt: 2, p: 2, bgcolor: 'background.default', borderRadius: 1, fontSize: 15 }}>
            Username: <b>{created?.username}</b><br />Temporary password: <b>{created?.temporary_password}</b>
          </Box>
          <Typography color="text.secondary" sx={{ mt: 1.5, fontSize: 14 }}>
            {created?.login_emailed ? 'These details were also emailed to the employee.' : 'No email address was given, so nothing was emailed.'}
          </Typography>
        </DialogContent>
        <DialogActions>
          <Button variant="contained" onClick={() => navigate(`/employees/${created.employee.id}`)}>Done</Button>
        </DialogActions>
      </Dialog>
    </Box>
  )
}
