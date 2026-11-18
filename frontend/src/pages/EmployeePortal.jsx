import { useEffect, useState } from 'react'
import { useQuery, useQueryClient } from 'react-query'
import { useNavigate } from 'react-router-dom'
import { Alert, AppBar, Box, Button, Grid, MenuItem, Paper, Tab, Table, TableBody, TableCell, TableContainer, TableHead,
  TableRow, Tabs, TextField, Toolbar, Typography } from '@mui/material'
import { get, openDocument, post, put } from '../api'
import { useAuth } from '../auth'
import { useNotify } from '../notify'
import { date, kes, num, PAYMENT_METHODS, period, today } from '../format'
import { tokens } from '../theme'
import Logo from '../components/Logo'
import { bind, Empty, Loading, StatusChip } from '../components/ui'

function Today({ goTo }) {
  const notify = useNotify()
  const qc = useQueryClient()
  const att = useQuery('my-today', () => get('/attendance.php', { action: 'today' }))
  const slips = useQuery('my-payslips', () => get('/payroll.php', { action: 'my_payslips' }))
  const bal = useQuery('my-balance', () => get('/leave.php', { action: 'balance' }))
  const clock = async (action) => {
    try {
      const res = await post(`/attendance.php?action=${action}`)
      notify(res.message)
      qc.invalidateQueries('my-today')
    } catch (err) { notify(err) }
  }
  const a = att.data
  const latest = slips.data?.[0]
  const annual = bal.data?.find((b) => b.code === 'ANNUAL')

  return (
    <Grid container spacing={2}>
      <Grid item xs={12} md={6}>
        <Paper sx={{ p: 3, height: '100%' }}>
          <Typography variant="h6">Attendance today</Typography>
          <Typography color="text.secondary" sx={{ mb: 2 }}>{new Date().toLocaleDateString('en-KE', { weekday: 'long', day: 'numeric', month: 'long' })}</Typography>
          {a?.clock_in && <Typography component="div" sx={{ mb: 1 }}>Clocked in at <b>{a.clock_in.slice(0, 5)}</b> <StatusChip status={a.status} />
            {a.clock_out && <> · out at <b>{a.clock_out.slice(0, 5)}</b></>}</Typography>}
          {a?.status === 'on_leave' && <Alert severity="info">You are on approved leave today.</Alert>}
          {!a?.clock_in && a?.status !== 'on_leave' && <Button variant="contained" size="large" onClick={() => clock('clock_in')}>Clock in</Button>}
          {a?.clock_in && !a.clock_out && <Button variant="outlined" size="large" onClick={() => clock('clock_out')}>Clock out</Button>}
        </Paper>
      </Grid>
      <Grid item xs={12} md={6}>
        <Paper sx={{ p: 3, height: '100%' }}>
          <Typography variant="h6">Latest pay</Typography>
          {latest ? (
            <>
              <Typography color="text.secondary">{period(latest.period_month, latest.period_year)}</Typography>
              <Typography sx={{ fontSize: 34, fontWeight: 700, color: latest.payment_status === 'paid' ? tokens.paid : tokens.ink, my: 1 }}>{kes(latest.net_pay)}</Typography>
              <Box sx={{ display: 'flex', gap: 1, alignItems: 'center' }}>
                <StatusChip status={latest.payment_status === 'paid' ? 'paid' : 'approved'} />
                <Button size="small" onClick={() => openDocument(`/payroll.php?action=payslip&id=${latest.id}`).catch(notify)}>Open payslip</Button>
              </Box>
            </>
          ) : <Typography color="text.secondary" sx={{ mt: 1 }}>Your payslips will appear here after your first approved payroll.</Typography>}
        </Paper>
      </Grid>
      <Grid item xs={12}>
        <Paper sx={{ p: 3, display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 2 }}>
          <Box>
            <Typography variant="h6">Annual leave</Typography>
            <Typography color="text.secondary">{annual ? `${Number(annual.remaining)} of ${annual.entitlement} days left this year${Number(annual.pending) ? `, ${Number(annual.pending)} pending` : ''}` : '—'}</Typography>
          </Box>
          <Button variant="outlined" onClick={() => goTo('leave')}>Apply for leave</Button>
        </Paper>
      </Grid>
    </Grid>
  )
}

function Payslips() {
  const notify = useNotify()
  const { data, isLoading } = useQuery('my-payslips', () => get('/payroll.php', { action: 'my_payslips' }))
  if (isLoading) return <Loading />
  if (!data.length) return <Empty title="No payslips yet">Payslips appear once HR approves a month’s payroll.</Empty>
  return (
    <TableContainer component={Paper}>
      <Table size="small">
        <TableHead><TableRow><TableCell>Month</TableCell><TableCell align="right">Gross</TableCell><TableCell align="right">PAYE</TableCell>
          <TableCell align="right">Deductions</TableCell><TableCell align="right">Net pay</TableCell><TableCell>Status</TableCell><TableCell /></TableRow></TableHead>
        <TableBody>
          {data.map((p) => (
            <TableRow key={p.id}>
              <TableCell><b>{period(p.period_month, p.period_year)}</b></TableCell>
              <TableCell align="right">{num(p.gross_pay)}</TableCell>
              <TableCell align="right">{num(p.paye)}</TableCell>
              <TableCell align="right">{num(p.total_deductions)}</TableCell>
              <TableCell align="right"><b>{num(p.net_pay)}</b></TableCell>
              <TableCell>{p.payment_status === 'paid' ? <StatusChip status="paid" /> : <StatusChip status="approved" />}
                <Typography color="text.secondary" sx={{ fontSize: 12 }}>{PAYMENT_METHODS[p.payment_method]}</Typography></TableCell>
              <TableCell align="right"><Button size="small" onClick={() => openDocument(`/payroll.php?action=payslip&id=${p.id}`).catch(notify)}>View / print</Button></TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </TableContainer>
  )
}

function LeaveTab() {
  const notify = useNotify()
  const qc = useQueryClient()
  const types = useQuery('leave-types', () => get('/leave.php', { action: 'types' }))
  const bal = useQuery('my-balance', () => get('/leave.php', { action: 'balance' }))
  const reqs = useQuery('my-leave', () => get('/leave.php'))
  const [form, setForm] = useState({ start_date: today(), end_date: today() })
  const [errors, setErrors] = useState({})
  const [busy, setBusy] = useState(false)
  const f = (n) => bind(form, setForm, errors, n)
  const refresh = () => ['my-balance', 'my-leave'].forEach((k) => qc.invalidateQueries(k))

  const apply = async (e) => {
    e.preventDefault()
    setBusy(true)
    try {
      const res = await post('/leave.php?action=apply', form)
      notify(res.message)
      setErrors({})
      setForm({ start_date: today(), end_date: today() })
      refresh()
    } catch (err) { setErrors(err.errors); notify(err) } finally { setBusy(false) }
  }
  const cancel = async (id) => {
    try { notify((await post('/leave.php?action=cancel', { id })).message); refresh() } catch (err) { notify(err) }
  }

  return (
    <Grid container spacing={2}>
      <Grid item xs={12} md={5}>
        <Paper component="form" onSubmit={apply} sx={{ p: 2.5, display: 'grid', gap: 2 }}>
          <Typography variant="h6">Apply for leave</Typography>
          <TextField select label="Leave type" required {...f('leave_type_id')}>
            {(types.data || []).map((t) => <MenuItem key={t.id} value={t.id}>{t.name}</MenuItem>)}
          </TextField>
          <Box sx={{ display: 'flex', gap: 1.5 }}>
            <TextField label="From" type="date" required InputLabelProps={{ shrink: true }} {...f('start_date')} />
            <TextField label="To" type="date" required InputLabelProps={{ shrink: true }} {...f('end_date')} />
          </Box>
          <TextField label="Reason" multiline minRows={2} {...f('reason')} />
          <Button type="submit" variant="contained" disabled={busy}>Submit request</Button>
          <Typography color="text.secondary" sx={{ fontSize: 12.5 }}>Days are counted Monday to Friday. Public holidays are not deducted automatically; mention them in the reason.</Typography>
        </Paper>
        <Paper sx={{ p: 2.5, mt: 2 }}>
          <Typography variant="h6" sx={{ mb: 1 }}>Balance {new Date().getFullYear()}</Typography>
          {(bal.data || []).map((b) => (
            <Box key={b.leave_type_id} sx={{ display: 'flex', justifyContent: 'space-between', py: 0.5, fontSize: 14.5 }}>
              <span>{b.name}</span><span><b>{Number(b.remaining)}</b> of {b.entitlement} days{Number(b.pending) ? ` (${Number(b.pending)} pending)` : ''}</span>
            </Box>
          ))}
        </Paper>
      </Grid>
      <Grid item xs={12} md={7}>
        <Paper sx={{ p: 2.5 }}>
          <Typography variant="h6" sx={{ mb: 1 }}>My requests</Typography>
          {!reqs.data?.length ? <Typography color="text.secondary">No leave requests yet.</Typography> : (
            <Table size="small">
              <TableBody>
                {reqs.data.map((r) => (
                  <TableRow key={r.id}>
                    <TableCell><b>{r.leave_type}</b><Typography color="text.secondary" sx={{ fontSize: 12.5 }}>{date(r.start_date)} – {date(r.end_date)} · {Number(r.days)} days</Typography>
                      {r.review_comment && <Typography sx={{ fontSize: 12.5 }}>“{r.review_comment}”</Typography>}</TableCell>
                    <TableCell><StatusChip status={r.status} /></TableCell>
                    <TableCell align="right">{r.status === 'pending' && <Button size="small" onClick={() => cancel(r.id)}>Cancel</Button>}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </Paper>
      </Grid>
    </Grid>
  )
}

function Profile() {
  const notify = useNotify()
  const { data, isLoading, refetch } = useQuery('my-profile', () => get('/employees.php', { action: 'me' }))
  const [form, setForm] = useState({})
  const [errors, setErrors] = useState({})
  useEffect(() => { if (data) setForm({ phone_number: data.phone_number || '', personal_email: data.personal_email || '' }) }, [data])
  if (isLoading) return <Loading />
  const save = async () => {
    try { await put('/employees.php?action=me', form); notify('Contact details saved'); setErrors({}); refetch() } catch (err) { setErrors(err.errors); notify(err) }
  }
  const facts = [['Employee number', data.employee_number], ['Department', data.department], ['Position', data.position],
    ['Hire date', date(data.hire_date)], ['National ID', data.national_id], ['KRA PIN', data.kra_pin], ['NSSF number', data.nssf_number],
    ['SHIF number', data.shif_number], ['Paid by', PAYMENT_METHODS[data.payment_method]]]
  return (
    <Grid container spacing={2}>
      <Grid item xs={12} md={6}>
        <Paper sx={{ p: 2.5 }}>
          <Typography variant="h6" sx={{ mb: 1 }}>{data.first_name} {data.last_name}</Typography>
          {facts.map(([k, v]) => (
            <Box key={k} sx={{ display: 'flex', justifyContent: 'space-between', py: 0.5, fontSize: 14.5 }}>
              <Typography color="text.secondary" sx={{ fontSize: 'inherit' }}>{k}</Typography><span>{v || '—'}</span>
            </Box>
          ))}
          <Typography color="text.secondary" sx={{ fontSize: 12.5, mt: 1.5 }}>To change your bank, M-Pesa or statutory details, contact HR.</Typography>
        </Paper>
      </Grid>
      <Grid item xs={12} md={6}>
        <Paper sx={{ p: 2.5, display: 'grid', gap: 2 }}>
          <Typography variant="h6">Contact details</Typography>
          <TextField label="Mobile number" {...bind(form, setForm, errors, 'phone_number')} />
          <TextField label="Personal email" {...bind(form, setForm, errors, 'personal_email')} />
          <Box><Button variant="contained" onClick={save}>Save contact details</Button></Box>
        </Paper>
      </Grid>
    </Grid>
  )
}

export default function EmployeePortal() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const [tab, setTab] = useState('today')
  return (
    <Box sx={{ minHeight: '100vh' }}>
      <AppBar position="sticky" sx={{ bgcolor: tokens.ink }}>
        <Toolbar sx={{ gap: 2 }}>
          <Logo light />
          <Typography sx={{ opacity: 0.75, display: { xs: 'none', sm: 'block' } }}>{user.organization_name}</Typography>
          <Box sx={{ flexGrow: 1 }} />
          <Button color="inherit" onClick={() => navigate('/change-password')}>Password</Button>
          <Button color="inherit" onClick={async () => { await logout(); navigate('/login') }}>Log out</Button>
        </Toolbar>
      </AppBar>
      <Box sx={{ maxWidth: 1080, mx: 'auto', p: { xs: 2, sm: 3 } }}>
        <Typography variant="h4" component="h1">Hello, {user.first_name}</Typography>
        <Tabs value={tab} onChange={(_, v) => setTab(v)} variant="scrollable" sx={{ my: 2 }}>
          <Tab value="today" label="Today" /><Tab value="payslips" label="Payslips" />
          <Tab value="leave" label="Leave" /><Tab value="profile" label="My profile" />
        </Tabs>
        {tab === 'today' && <Today goTo={setTab} />}
        {tab === 'payslips' && <Payslips />}
        {tab === 'leave' && <LeaveTab />}
        {tab === 'profile' && <Profile />}
      </Box>
    </Box>
  )
}
