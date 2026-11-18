import { useState } from 'react'
import { useQuery, useQueryClient } from 'react-query'
import { Link as RouterLink, useParams } from 'react-router-dom'
import { Box, Button, Dialog, DialogActions, DialogContent, DialogTitle, Grid, Paper, Table, TableBody, TableCell,
  TableHead, TableRow, Typography } from '@mui/material'
import { del, get, openDocument, post } from '../api'
import { useAuth } from '../auth'
import { useNotify } from '../notify'
import { date, dateTime, kes, PAYMENT_METHODS, period } from '../format'
import { ErrorState, Loading, PageHeader, StatusChip } from '../components/ui'

function Facts({ title, rows }) {
  return (
    <Paper sx={{ p: 2.5, height: '100%' }}>
      <Typography variant="h6" sx={{ mb: 1.5 }}>{title}</Typography>
      {rows.map(([k, v]) => (
        <Box key={k} sx={{ display: 'flex', justifyContent: 'space-between', gap: 2, py: 0.6, fontSize: 14.5 }}>
          <Typography color="text.secondary" sx={{ fontSize: 'inherit' }}>{k}</Typography>
          <Typography sx={{ fontSize: 'inherit', fontWeight: 500, textAlign: 'right' }}>{v || '—'}</Typography>
        </Box>
      ))}
    </Paper>
  )
}

export default function EmployeeDetail() {
  const { id } = useParams()
  const { can } = useAuth()
  const notify = useNotify()
  const qc = useQueryClient()
  const [reset, setReset] = useState(null)
  const [confirmOff, setConfirmOff] = useState(false)
  const { data: e, isLoading, error, refetch } = useQuery(['employee', id], () => get('/employees.php', { id }))
  const balance = useQuery(['balance', id], () => get('/leave.php', { action: 'balance', employee_id: id }))

  if (isLoading) return <Loading />
  if (error) return <ErrorState error={error} onRetry={refetch} />
  const s = e.salary || {}

  const resetPassword = async () => {
    try {
      const res = await post(`/employees.php?action=reset_password&id=${id}`)
      setReset(res.data)
    } catch (err) { notify(err) }
  }
  const deactivate = async () => {
    try {
      const res = await del(`/employees.php?id=${id}`)
      notify(res.message)
      setConfirmOff(false)
      qc.invalidateQueries('employees')
      refetch()
    } catch (err) { notify(err) }
  }

  return (
    <>
      <PageHeader title={`${e.first_name} ${e.last_name}`}
        subtitle={<>{e.employee_number} · {e.position || 'No position'} · {e.department || 'No department'} <StatusChip status={e.status} /></>}
        actions={can('employees.manage') && (
          <>
            <Button variant="contained" component={RouterLink} to={`/employees/${id}/edit`}>Edit</Button>
            <Button variant="outlined" onClick={resetPassword}>Reset password</Button>
            {e.status === 'active' && <Button color="error" onClick={() => setConfirmOff(true)}>Deactivate</Button>}
          </>
        )} />

      <Grid container spacing={2}>
        <Grid item xs={12} md={4}>
          <Facts title="Personal" rows={[['National ID', e.national_id], ['Date of birth', date(e.date_of_birth)],
            ['Gender', e.gender && e.gender[0].toUpperCase() + e.gender.slice(1)], ['Mobile', e.phone_number],
            ['Work email', e.work_email], ['Personal email', e.personal_email]]} />
        </Grid>
        <Grid item xs={12} md={4}>
          <Facts title="Employment" rows={[['Hire date', date(e.hire_date)], ['Type', e.employment_type],
            ['KRA PIN', e.kra_pin], ['NSSF number', e.nssf_number], ['SHIF number', e.shif_number],
            ['Login', e.username ? `${e.username}${e.last_login ? `, last seen ${dateTime(e.last_login)}` : ', never logged in'}` : 'None']]} />
        </Grid>
        <Grid item xs={12} md={4}>
          <Facts title="Pay" rows={[['Basic salary', kes(s.basic_salary)],
            ['Allowances', kes(Number(s.housing_allowance || 0) + Number(s.transport_allowance || 0) + Number(s.medical_allowance || 0) + Number(s.other_allowances || 0))],
            ['Other deductions', kes(s.other_deductions)], ['Effective from', date(s.effective_date)],
            ['Paid by', PAYMENT_METHODS[e.payment_method]],
            [e.payment_method === 'bank_transfer' ? 'Account' : 'M-Pesa', e.payment_method === 'bank_transfer' ? `${e.bank_name || ''} ${e.bank_account || ''}` : e.mpesa_number]]} />
        </Grid>

        <Grid item xs={12} md={5}>
          <Paper sx={{ p: 2.5, height: '100%' }}>
            <Typography variant="h6" sx={{ mb: 1 }}>Leave balance {new Date().getFullYear()}</Typography>
            <Table size="small">
              <TableHead><TableRow><TableCell>Type</TableCell><TableCell align="right">Used</TableCell>
                <TableCell align="right">Pending</TableCell><TableCell align="right">Left</TableCell></TableRow></TableHead>
              <TableBody>
                {(balance.data || []).map((b) => (
                  <TableRow key={b.leave_type_id}><TableCell>{b.name}</TableCell><TableCell align="right">{Number(b.used)}</TableCell>
                    <TableCell align="right">{Number(b.pending)}</TableCell><TableCell align="right"><b>{Number(b.remaining)}</b></TableCell></TableRow>
                ))}
              </TableBody>
            </Table>
          </Paper>
        </Grid>

        {e.payslips && (
          <Grid item xs={12} md={7}>
            <Paper sx={{ p: 2.5, height: '100%' }}>
              <Typography variant="h6" sx={{ mb: 1 }}>Payslips</Typography>
              {e.payslips.length ? (
                <Table size="small">
                  <TableHead><TableRow><TableCell>Month</TableCell><TableCell align="right">Gross</TableCell>
                    <TableCell align="right">Net pay</TableCell><TableCell>Status</TableCell><TableCell /></TableRow></TableHead>
                  <TableBody>
                    {e.payslips.map((p) => (
                      <TableRow key={p.id}>
                        <TableCell>{period(p.period_month, p.period_year)}</TableCell>
                        <TableCell align="right">{kes(p.gross_pay)}</TableCell>
                        <TableCell align="right">{kes(p.net_pay)}</TableCell>
                        <TableCell><StatusChip status={p.run_status} /></TableCell>
                        <TableCell align="right">
                          <Button size="small" onClick={() => openDocument(`/payroll.php?action=payslip&id=${p.id}`).catch(notify)}>View</Button>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              ) : <Typography color="text.secondary">No payslips yet.</Typography>}
            </Paper>
          </Grid>
        )}

        {e.salary_history?.length > 1 && (
          <Grid item xs={12}>
            <Paper sx={{ p: 2.5 }}>
              <Typography variant="h6" sx={{ mb: 1 }}>Salary history</Typography>
              <Table size="small">
                <TableHead><TableRow><TableCell>Effective from</TableCell><TableCell align="right">Basic</TableCell>
                  <TableCell align="right">Housing</TableCell><TableCell align="right">Transport</TableCell>
                  <TableCell align="right">Medical</TableCell><TableCell align="right">Other</TableCell></TableRow></TableHead>
                <TableBody>
                  {e.salary_history.map((h) => (
                    <TableRow key={h.id}><TableCell>{date(h.effective_date)}</TableCell><TableCell align="right">{kes(h.basic_salary)}</TableCell>
                      <TableCell align="right">{kes(h.housing_allowance)}</TableCell><TableCell align="right">{kes(h.transport_allowance)}</TableCell>
                      <TableCell align="right">{kes(h.medical_allowance)}</TableCell><TableCell align="right">{kes(h.other_allowances)}</TableCell></TableRow>
                  ))}
                </TableBody>
              </Table>
            </Paper>
          </Grid>
        )}
      </Grid>

      <Dialog open={Boolean(reset)} onClose={() => setReset(null)}>
        <DialogTitle>Password reset</DialogTitle>
        <DialogContent>
          <Typography>Give these details to {e.first_name}. They will choose a new password at their next login.</Typography>
          <Box sx={{ mt: 2, p: 2, bgcolor: 'background.default', borderRadius: 1 }}>
            Username: <b>{reset?.username}</b><br />Temporary password: <b>{reset?.temporary_password}</b>
          </Box>
        </DialogContent>
        <DialogActions><Button variant="contained" onClick={() => setReset(null)}>Done</Button></DialogActions>
      </Dialog>

      <Dialog open={confirmOff} onClose={() => setConfirmOff(false)}>
        <DialogTitle>Deactivate {e.first_name}?</DialogTitle>
        <DialogContent>
          <Typography>They will be left out of future payrolls and can no longer log in. Their payslips and records are kept.</Typography>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setConfirmOff(false)}>Cancel</Button>
          <Button color="error" variant="contained" onClick={deactivate}>Deactivate</Button>
        </DialogActions>
      </Dialog>
    </>
  )
}
