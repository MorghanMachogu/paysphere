import { useEffect, useState } from 'react'
import { useQuery, useQueryClient } from 'react-query'
import { Alert, Box, Button, Dialog, DialogActions, DialogContent, DialogTitle, Grid, MenuItem, Paper, Switch, Tab,
  Table, TableBody, TableCell, TableContainer, TableHead, TableRow, Tabs, TextField, Typography } from '@mui/material'
import { get, post, put } from '../api'
import { useAuth } from '../auth'
import { useNotify } from '../notify'
import { date, dateTime, num, ROLE_LABELS, period } from '../format'
import { bind, ErrorState, Loading, PageHeader, StatusChip } from '../components/ui'

function Organisation({ editable }) {
  const notify = useNotify()
  const { data, isLoading } = useQuery('organization', () => get('/settings.php', { action: 'organization' }))
  const sub = useQuery('subscription', () => get('/settings.php', { action: 'subscription' }))
  const [form, setForm] = useState({})
  const [errors, setErrors] = useState({})
  useEffect(() => { if (data) setForm(data) }, [data])
  if (isLoading) return <Loading />
  const f = (n) => ({ ...bind(form, setForm, errors, n), disabled: !editable })
  const save = async () => {
    try {
      const res = await put('/settings.php?action=organization', form)
      setErrors({})
      notify(res.message === 'OK' ? 'Organisation details saved' : res.message)
    } catch (err) { setErrors(err.errors); notify(err) }
  }
  return (
    <Grid container spacing={2}>
      <Grid item xs={12} md={8}>
        <Paper sx={{ p: 2.5 }}>
          <Grid container spacing={2}>
            <Grid item xs={12}><TextField label="Business name" {...f('organization_name')} /></Grid>
            <Grid item xs={12} sm={6}><TextField label="KRA PIN" {...f('kra_pin')} helperText={errors.kra_pin || 'Printed on payslips and reports'} /></Grid>
            <Grid item xs={12} sm={6}><TextField label="Email" {...f('email')} /></Grid>
            <Grid item xs={12} sm={6}><TextField label="Phone" {...f('phone')} /></Grid>
            <Grid item xs={12} sm={6}><TextField label="County" {...f('county')} /></Grid>
            <Grid item xs={12}><TextField label="Address" {...f('address')} /></Grid>
          </Grid>
          {editable && <Box sx={{ textAlign: 'right', mt: 2 }}><Button variant="contained" onClick={save}>Save details</Button></Box>}
        </Paper>
      </Grid>
      <Grid item xs={12} md={4}>
        <Paper sx={{ p: 2.5 }}>
          <Typography variant="h6">Subscription</Typography>
          {sub.data && (
            <Box sx={{ mt: 1, fontSize: 14.5, display: 'grid', gap: 0.75 }}>
              <span>Plan: <b>{sub.data.plan[0].toUpperCase() + sub.data.plan.slice(1)}</b></span>
              <span>Active employees: <b>{sub.data.active_employees} of {sub.data.max_employees}</b></span>
              {sub.data.end_date && <span>Renews or ends: <b>{date(sub.data.end_date)}</b></span>}
              <span>Organisation code: <b>{form.organization_code}</b></span>
            </Box>
          )}
        </Paper>
      </Grid>
    </Grid>
  )
}

function Structure({ editable }) {
  const notify = useNotify()
  const qc = useQueryClient()
  const [edit, setEdit] = useState(null) // { kind, row }
  const [errors, setErrors] = useState({})
  const depts = useQuery('departments', () => get('/departments.php'))
  const pos = useQuery('positions', () => get('/positions.php'))
  const kinds = { departments: { label: 'department', name: 'name', q: depts }, positions: { label: 'position', name: 'title', q: pos } }

  const save = async () => {
    const { kind, row } = edit
    try {
      const res = row.id ? await put(`/${kind}.php?id=${row.id}`, row) : await post(`/${kind}.php`, row)
      notify(res.message)
      qc.invalidateQueries(kind)
      setEdit(null)
    } catch (err) { setErrors(err.errors); notify(err) }
  }

  return (
    <Grid container spacing={2}>
      {Object.entries(kinds).map(([kind, k]) => (
        <Grid item xs={12} md={6} key={kind}>
          <Paper sx={{ p: 2.5 }}>
            <Box sx={{ display: 'flex', justifyContent: 'space-between', mb: 1 }}>
              <Typography variant="h6">{kind === 'departments' ? 'Departments' : 'Positions'}</Typography>
              {editable && <Button size="small" onClick={() => { setErrors({}); setEdit({ kind, row: { is_active: 1 } }) }}>Add {k.label}</Button>}
            </Box>
            <Table size="small">
              <TableHead><TableRow><TableCell>Code</TableCell><TableCell>Name</TableCell><TableCell align="right">Staff</TableCell><TableCell /></TableRow></TableHead>
              <TableBody>
                {(k.q.data || []).map((r) => (
                  <TableRow key={r.id} sx={{ opacity: r.is_active ? 1 : 0.5 }}>
                    <TableCell>{r.code}</TableCell><TableCell>{r[k.name]}{!r.is_active && ' (inactive)'}</TableCell>
                    <TableCell align="right">{r.employee_count}</TableCell>
                    <TableCell align="right">{editable && <Button size="small" onClick={() => { setErrors({}); setEdit({ kind, row: { ...r } }) }}>Edit</Button>}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Paper>
        </Grid>
      ))}
      <Dialog open={Boolean(edit)} onClose={() => setEdit(null)} fullWidth maxWidth="xs">
        {edit && (
          <>
            <DialogTitle>{edit.row.id ? 'Edit' : 'Add'} {kinds[edit.kind].label}</DialogTitle>
            <DialogContent sx={{ display: 'grid', gap: 2, pt: '8px !important' }}>
              {['code', kinds[edit.kind].name, 'description'].map((n) => (
                <TextField key={n} label={n === 'code' ? 'Code' : n === 'description' ? 'Description' : 'Name'} required={n !== 'description'}
                  value={edit.row[n] || ''} error={Boolean(errors?.[n])} helperText={errors?.[n]}
                  onChange={(e) => setEdit({ ...edit, row: { ...edit.row, [n]: e.target.value } })} />
              ))}
              {edit.row.id && (
                <Box><Switch checked={Boolean(Number(edit.row.is_active))} onChange={(e) => setEdit({ ...edit, row: { ...edit.row, is_active: e.target.checked ? 1 : 0 } })} /> Active</Box>
              )}
            </DialogContent>
            <DialogActions><Button onClick={() => setEdit(null)}>Cancel</Button><Button variant="contained" onClick={save}>Save</Button></DialogActions>
          </>
        )}
      </Dialog>
    </Grid>
  )
}

function LeaveTypes({ editable }) {
  const notify = useNotify()
  const { data, isLoading, refetch } = useQuery('leave-types-all', () => get('/settings.php', { action: 'leave_types' }))
  const [days, setDays] = useState({})
  if (isLoading) return <Loading />
  const save = async (t) => {
    try {
      await put(`/settings.php?action=leave_types&id=${t.id}`, { days_per_year: days[t.id] ?? t.days_per_year, is_active: t.is_active })
      notify(`${t.name} updated`)
      refetch()
    } catch (err) { notify(err) }
  }
  return (
    <Paper sx={{ p: 2.5, maxWidth: 640 }}>
      <Typography color="text.secondary" sx={{ mb: 2, fontSize: 14 }}>Entitlements in working days per calendar year. The Employment Act
        sets a minimum of 21 days annual leave, three months maternity and two weeks paternity leave.</Typography>
      <Table size="small">
        <TableHead><TableRow><TableCell>Leave type</TableCell><TableCell>Days per year</TableCell><TableCell /></TableRow></TableHead>
        <TableBody>
          {data.map((t) => (
            <TableRow key={t.id}>
              <TableCell>{t.name}</TableCell>
              <TableCell sx={{ width: 140 }}>
                <TextField type="number" disabled={!editable} value={days[t.id] ?? t.days_per_year}
                  onChange={(e) => setDays({ ...days, [t.id]: e.target.value })} inputProps={{ min: 0, max: 365, 'aria-label': `${t.name} days` }} />
              </TableCell>
              <TableCell align="right">{editable && days[t.id] !== undefined && <Button size="small" onClick={() => save(t)}>Save</Button>}</TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </Paper>
  )
}

function Users() {
  const notify = useNotify()
  const { user } = useAuth()
  const { data, isLoading, error, refetch } = useQuery('hr-users', () => get('/settings.php', { action: 'users' }))
  const [form, setForm] = useState(null)
  const [errors, setErrors] = useState({})
  if (isLoading) return <Loading />
  if (error) return <ErrorState error={error} />
  const f = (n) => bind(form, setForm, errors, n)
  const create = async () => {
    try {
      await post('/settings.php?action=users', form)
      notify('User added')
      setForm(null)
      refetch()
    } catch (err) { setErrors(err.errors); notify(err) }
  }
  const update = async (u, change) => {
    try {
      await put(`/settings.php?action=users&id=${u.id}`, change)
      notify('User updated')
      refetch()
    } catch (err) { notify(err) }
  }
  return (
    <>
      <Box sx={{ display: 'flex', justifyContent: 'space-between', mb: 2, gap: 2, flexWrap: 'wrap' }}>
        <Typography color="text.secondary" sx={{ maxWidth: 680, fontSize: 14 }}>
          Administrators can do everything. HR managers manage employees, payroll and leave. Payroll officers prepare payroll
          and reports but cannot approve it. Line managers review leave and attendance.
        </Typography>
        <Button variant="contained" onClick={() => { setErrors({}); setForm({ role: 'hr_manager' }) }}>Add user</Button>
      </Box>
      <TableContainer component={Paper}>
        <Table size="small">
          <TableHead><TableRow><TableCell>Name</TableCell><TableCell>Username</TableCell><TableCell>Role</TableCell>
            <TableCell>Last login</TableCell><TableCell>Active</TableCell></TableRow></TableHead>
          <TableBody>
            {data.map((u) => (
              <TableRow key={u.id}>
                <TableCell><b>{u.first_name} {u.last_name}</b><Typography color="text.secondary" sx={{ fontSize: 12 }}>{u.email}</Typography></TableCell>
                <TableCell>{u.username}</TableCell>
                <TableCell sx={{ width: 190 }}>
                  <TextField select value={u.role} disabled={u.id === user.id} onChange={(e) => update(u, { role: e.target.value })}
                    inputProps={{ 'aria-label': `Role for ${u.username}` }}>
                    {Object.entries(ROLE_LABELS).filter(([k]) => k !== 'employee').map(([k, l]) => <MenuItem key={k} value={k}>{l}</MenuItem>)}
                  </TextField>
                </TableCell>
                <TableCell>{u.last_login ? dateTime(u.last_login) : 'Never'}</TableCell>
                <TableCell><Switch checked={Boolean(Number(u.is_active))} disabled={u.id === user.id}
                  onChange={(e) => update(u, { is_active: e.target.checked })} inputProps={{ 'aria-label': `Active: ${u.username}` }} /></TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </TableContainer>
      <Dialog open={Boolean(form)} onClose={() => setForm(null)} fullWidth maxWidth="sm">
        {form && (
          <>
            <DialogTitle>Add HR user</DialogTitle>
            <DialogContent>
              <Grid container spacing={2} sx={{ pt: 1 }}>
                <Grid item xs={6}><TextField label="First name" {...f('first_name')} /></Grid>
                <Grid item xs={6}><TextField label="Last name" {...f('last_name')} /></Grid>
                <Grid item xs={12}><TextField label="Email" {...f('email')} /></Grid>
                <Grid item xs={6}><TextField label="Username" {...f('username')} /></Grid>
                <Grid item xs={6}>
                  <TextField select label="Role" {...f('role')}>
                    {Object.entries(ROLE_LABELS).filter(([k]) => k !== 'employee').map(([k, l]) => <MenuItem key={k} value={k}>{l}</MenuItem>)}
                  </TextField>
                </Grid>
                <Grid item xs={12}><TextField label="Initial password" type="password" {...f('password')} helperText={errors?.password || 'At least 8 characters with letters and numbers'} /></Grid>
              </Grid>
            </DialogContent>
            <DialogActions><Button onClick={() => setForm(null)}>Cancel</Button><Button variant="contained" onClick={create}>Add user</Button></DialogActions>
          </>
        )}
      </Dialog>
    </>
  )
}

function Rates() {
  const now = new Date()
  const { data, isLoading } = useQuery('rates', () => get('/payroll.php', { action: 'rates', month: now.getMonth() + 1, year: now.getFullYear() }))
  if (isLoading) return <Loading />
  const row = (k, v) => <TableRow key={k}><TableCell>{k}</TableCell><TableCell align="right">{v}</TableCell></TableRow>
  let lower = 0
  return (
    <Grid container spacing={2}>
      <Grid item xs={12}>
        <Alert severity="info">Rates in force for {period(now.getMonth() + 1, now.getFullYear())}. They are kept in
          backend/config/payroll_config.php with the date each one took effect, so past payrolls keep the rates of their own month.</Alert>
      </Grid>
      <Grid item xs={12} md={6}>
        <Paper sx={{ p: 2.5 }}>
          <Typography variant="h6" sx={{ mb: 1 }}>PAYE monthly bands</Typography>
          <Table size="small"><TableBody>
            {data.paye.bands.map((b) => {
              const label = b.upto ? `${num(lower + (lower ? 1 : 0)).replace('.00', '')} – ${num(b.upto).replace('.00', '')}` : `Above ${num(lower).replace('.00', '')}`
              lower = b.upto || lower
              return row(label, `${(b.rate * 100).toFixed(1).replace('.0', '')}%`)
            })}
            {row('Personal relief', `KES ${num(data.paye.personal_relief)}`)}
          </TableBody></Table>
        </Paper>
      </Grid>
      <Grid item xs={12} md={6}>
        <Paper sx={{ p: 2.5 }}>
          <Typography variant="h6" sx={{ mb: 1 }}>Contributions</Typography>
          <Table size="small"><TableBody>
            {row('NSSF (employee and employer each)', `${data.nssf.rate * 100}%`)}
            {row('NSSF Tier I limit (LEL)', `KES ${num(data.nssf.lel)}`)}
            {row('NSSF Tier II limit (UEL)', `KES ${num(data.nssf.uel)}`)}
            {row('SHIF', `${data.shif.rate * 100}% (minimum KES ${num(data.shif.minimum)})`)}
            {row('Housing Levy (employee and employer each)', `${data.housing_levy.employee_rate * 100}%`)}
            {row('Deducted before PAYE', ['nssf', 'shif', 'housing_levy'].filter((k) => data.tax_deductible[k]).map((k) => ({ nssf: 'NSSF', shif: 'SHIF', housing_levy: 'Housing Levy' })[k]).join(', '))}
            {row('Overtime', `${data.working_time.overtime_rate}× hourly rate (basic ÷ ${data.working_time.hours_per_month} h)`)}
          </TableBody></Table>
        </Paper>
      </Grid>
    </Grid>
  )
}

function Security() {
  const logins = useQuery('login-logs', () => get('/settings.php', { action: 'login_logs' }))
  const audit = useQuery('audit', () => get('/settings.php', { action: 'audit' }))
  if (logins.isLoading || audit.isLoading) return <Loading />
  return (
    <Grid container spacing={2}>
      <Grid item xs={12} lg={5}>
        <Paper sx={{ p: 2.5 }}>
          <Typography variant="h6" sx={{ mb: 1 }}>Login attempts</Typography>
          <Table size="small">
            <TableHead><TableRow><TableCell>When</TableCell><TableCell>User</TableCell><TableCell>Result</TableCell></TableRow></TableHead>
            <TableBody>
              {logins.data.slice(0, 50).map((l) => (
                <TableRow key={l.id}><TableCell>{dateTime(l.created_at)}</TableCell><TableCell>{l.username}</TableCell>
                  <TableCell>{l.success ? <StatusChip status="active" /> : <Typography color="error" sx={{ fontSize: 13 }}>{l.reason}</Typography>}</TableCell></TableRow>
              ))}
            </TableBody>
          </Table>
        </Paper>
      </Grid>
      <Grid item xs={12} lg={7}>
        <Paper sx={{ p: 2.5 }}>
          <Typography variant="h6" sx={{ mb: 1 }}>Audit trail</Typography>
          <Typography color="text.secondary" sx={{ fontSize: 13, mb: 1 }}>Every change to employees, salaries, payroll and leave. Entries cannot be edited or deleted.</Typography>
          <Table size="small">
            <TableHead><TableRow><TableCell>When</TableCell><TableCell>Who</TableCell><TableCell>Action</TableCell></TableRow></TableHead>
            <TableBody>
              {audit.data.slice(0, 100).map((a) => (
                <TableRow key={a.id}><TableCell sx={{ whiteSpace: 'nowrap' }}>{dateTime(a.created_at)}</TableCell><TableCell>{a.actor || 'System'}</TableCell>
                  <TableCell>{a.action}{a.details && <Typography color="text.secondary" sx={{ fontSize: 12, wordBreak: 'break-all' }}>{a.details}</Typography>}</TableCell></TableRow>
              ))}
            </TableBody>
          </Table>
        </Paper>
      </Grid>
    </Grid>
  )
}

export default function Settings() {
  const { can } = useAuth()
  const admin = can('settings.manage')
  const tabs = [['Organisation', <Organisation editable={admin} />], ['Departments and positions', <Structure editable={can('employees.manage')} />],
    ['Leave types', <LeaveTypes editable={admin} />], ['Statutory rates', <Rates />],
    ...(admin ? [['Users and roles', <Users />], ['Security', <Security />]] : [])]
  const [tab, setTab] = useState(0)
  return (
    <>
      <PageHeader title="Settings" />
      <Tabs value={tab} onChange={(_, v) => setTab(v)} variant="scrollable" sx={{ mb: 2 }}>
        {tabs.map(([l]) => <Tab key={l} label={l} />)}
      </Tabs>
      {tabs[tab][1]}
    </>
  )
}
