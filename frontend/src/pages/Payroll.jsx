import { useState } from 'react'
import { useQuery, useQueryClient } from 'react-query'
import { Alert, Box, Button, Dialog, DialogActions, DialogContent, DialogTitle, Grid, Paper, Table, TableBody,
  TableCell, TableContainer, TableHead, TableRow, TextField, Typography } from '@mui/material'
import { Check } from '@mui/icons-material'
import { downloadFile, get, openDocument, post } from '../api'
import { useAuth } from '../auth'
import { useNotify } from '../notify'
import { dateTime, kes, lastMonth, num, PAYMENT_METHODS, period } from '../format'
import { tokens } from '../theme'
import { Empty, ErrorState, Loading, MonthPicker, PageHeader } from '../components/ui'

const STAGES = [
  { key: 'draft', label: 'Draft', note: 'Calculated, open to correction', at: 'generated_at' },
  { key: 'approved', label: 'Approved', note: 'Locked for payment', at: 'approved_at' },
  { key: 'paid', label: 'Paid', note: 'Salaries paid and recorded', at: 'paid_at' },
]

/** The run's lifecycle as a horizontal track: the one bold element on this page. */
function RunTrack({ run }) {
  const reached = STAGES.findIndex((s) => s.key === run.status)
  return (
    <Box sx={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', position: 'relative', mb: 3 }}>
      <Box sx={{ position: 'absolute', top: 15, left: '16.6%', right: '16.6%', height: 3, bgcolor: tokens.line }} />
      <Box sx={{ position: 'absolute', top: 15, left: '16.6%', height: 3, bgcolor: run.status === 'paid' ? tokens.paid : tokens.ink,
        width: `${(reached / 2) * 66.8}%`, transition: 'width .4s ease', '@media (prefers-reduced-motion: reduce)': { transition: 'none' } }} />
      {STAGES.map((s, i) => {
        const done = i <= reached
        const colour = run.status === 'paid' ? tokens.paid : tokens.ink
        return (
          <Box key={s.key} sx={{ textAlign: 'center', position: 'relative' }}>
            <Box sx={{ width: 32, height: 32, borderRadius: '50%', mx: 'auto', display: 'grid', placeItems: 'center',
              bgcolor: done ? colour : '#fff', border: `3px solid ${done ? colour : tokens.line}`, color: '#fff' }}>
              {done && <Check sx={{ fontSize: 18 }} />}
            </Box>
            <Typography sx={{ mt: 1, fontWeight: 600, color: done ? 'text.primary' : 'text.secondary' }}>{s.label}</Typography>
            <Typography sx={{ fontSize: 12.5 }} color="text.secondary">{done ? dateTime(run[s.at]) : s.note}</Typography>
          </Box>
        )
      })}
    </Box>
  )
}

export default function Payroll() {
  const { can } = useAuth()
  const notify = useNotify()
  const qc = useQueryClient()
  const [sel, setSel] = useState(lastMonth())
  const [busy, setBusy] = useState(false)
  const [dialog, setDialog] = useState(null) // 'approve' | 'pay'
  const [refs, setRefs] = useState({})
  const key = ['payroll-run', sel.month, sel.year]
  const { data: run, isLoading, error, refetch } = useQuery(key, () => get('/payroll.php', { action: 'run', ...sel }))

  const act = async (fn) => {
    setBusy(true)
    try {
      const res = await fn()
      notify(res.message)
      if (res.data?.id) qc.setQueryData(key, res.data)
      qc.invalidateQueries('dashboard')
      setDialog(null)
    } catch (err) {
      notify(err)
    } finally {
      setBusy(false)
    }
  }
  const generate = () => act(() => post('/payroll.php?action=generate', sel))
  const approve = () => act(() => post('/payroll.php?action=approve', { run_id: run.id }))
  const pay = () => act(() => post('/payroll.php?action=pay', { run_id: run.id, references: refs }))
  const email = () => act(() => post('/payroll.php?action=email_payslips', { run_id: run.id }))

  const t = run?.totals || {}
  return (
    <>
      <PageHeader title="Payroll" subtitle="Generate, check, approve and record payment for each month."
        actions={<MonthPicker {...sel} onChange={setSel} />} />

      {isLoading ? <Loading /> : error ? <ErrorState error={error} onRetry={refetch} /> : !run ? (
        <Empty title={`No payroll for ${period(sel.month, sel.year)} yet`}
          action={can('payroll.manage') && <Button variant="contained" size="large" onClick={generate} disabled={busy}>
            {busy ? 'Calculating…' : `Generate ${period(sel.month, sel.year)} payroll`}</Button>}>
          PaySphere calculates gross pay from each salary and that month’s attendance (overtime and unpaid absence), then
          deducts NSSF, SHIF, the Housing Levy and PAYE at the rates in force for the month.
        </Empty>
      ) : (
        <>
          <Paper sx={{ p: { xs: 2, sm: 3 }, mb: 2 }}>
            <Box sx={{ display: 'flex', justifyContent: 'space-between', flexWrap: 'wrap', gap: 2, mb: 3 }}>
              <Box>
                <Typography variant="h5">{period(run.period_month, run.period_year)}</Typography>
                <Typography color="text.secondary">{run.employee_count} employees · calculated in {run.processing_ms} ms</Typography>
              </Box>
              <Box sx={{ display: 'flex', gap: 1, flexWrap: 'wrap', alignItems: 'flex-start' }}>
                {run.status === 'draft' && can('payroll.manage') && <Button variant="outlined" onClick={generate} disabled={busy}>Recalculate</Button>}
                {run.status === 'draft' && can('payroll.approve') && <Button variant="contained" onClick={() => setDialog('approve')}>Approve payroll</Button>}
                {run.status === 'approved' && can('payroll.manage') && <Button variant="contained" color="success" onClick={() => setDialog('pay')}>Record payment</Button>}
                {run.status !== 'draft' && can('payroll.manage') && <Button variant="outlined" onClick={email} disabled={busy}>Email payslips</Button>}
              </Box>
            </Box>
            <RunTrack run={run} />
            {run.status === 'draft' && !can('payroll.approve') && (
              <Alert severity="info">An HR manager or administrator needs to approve this payroll before payment.</Alert>
            )}
            <Grid container spacing={2} sx={{ mt: 0.5 }}>
              {[['Gross pay', t.gross], ['PAYE', t.paye], ['NSSF', t.nssf], ['SHIF', t.shif], ['Housing Levy', t.housing_levy]].map(([l, v]) => (
                <Grid item xs={6} sm={4} md={2} key={l}>
                  <Typography color="text.secondary" sx={{ fontSize: 13 }}>{l}</Typography>
                  <Typography sx={{ fontWeight: 600, fontSize: 17 }}>{num(v)}</Typography>
                </Grid>
              ))}
              <Grid item xs={6} sm={4} md={2}>
                <Typography color="text.secondary" sx={{ fontSize: 13 }}>Net pay</Typography>
                <Typography sx={{ fontWeight: 700, fontSize: 20, color: run.status === 'paid' ? tokens.paid : tokens.ink }}>{num(t.net)}</Typography>
              </Grid>
            </Grid>
            <Typography color="text.secondary" sx={{ fontSize: 13, mt: 1.5 }}>
              Amounts in KES. Employer contributions on top of gross: NSSF {num(t.employer_nssf)}, Housing Levy {num(t.employer_housing_levy)}.
            </Typography>
          </Paper>

          <Box sx={{ display: 'flex', gap: 1, mb: 1.5, flexWrap: 'wrap' }}>
            <Button size="small" onClick={() => openDocument(`/payroll.php?action=report&type=summary&run_id=${run.id}`).catch(notify)}>Summary report</Button>
            <Button size="small" onClick={() => openDocument(`/payroll.php?action=report&type=detailed&run_id=${run.id}`).catch(notify)}>Payroll register</Button>
            <Button size="small" onClick={() => openDocument(`/payroll.php?action=report&type=statutory&run_id=${run.id}`).catch(notify)}>Statutory report</Button>
            <Button size="small" onClick={() => downloadFile(`/payroll.php?action=report&type=statutory&format=csv&run_id=${run.id}`,
              `statutory-${run.period_year}-${String(run.period_month).padStart(2, '0')}.csv`).catch(notify)}>Statutory CSV</Button>
          </Box>

          <TableContainer component={Paper}>
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>Employee</TableCell><TableCell align="right">Gross</TableCell><TableCell align="right">NSSF</TableCell>
                  <TableCell align="right">SHIF</TableCell><TableCell align="right">AHL</TableCell><TableCell align="right">PAYE</TableCell>
                  <TableCell align="right">Other</TableCell><TableCell align="right">Net pay</TableCell><TableCell>Pay to</TableCell><TableCell />
                </TableRow>
              </TableHead>
              <TableBody>
                {run.payslips.map((p) => (
                  <TableRow key={p.id} hover>
                    <TableCell><b>{p.employee_name}</b><Typography color="text.secondary" sx={{ fontSize: 12 }}>{p.employee_number}{p.overtime_pay > 0 ? ` · ${Number(p.overtime_hours)} h overtime` : ''}{p.absent_days > 0 ? ` · ${Number(p.absent_days)} days absent` : ''}</Typography></TableCell>
                    <TableCell align="right">{num(p.gross_pay)}</TableCell>
                    <TableCell align="right">{num(p.nssf_employee)}</TableCell>
                    <TableCell align="right">{num(p.shif)}</TableCell>
                    <TableCell align="right">{num(p.housing_levy)}</TableCell>
                    <TableCell align="right">{num(p.paye)}</TableCell>
                    <TableCell align="right">{num(p.other_deductions)}</TableCell>
                    <TableCell align="right"><b>{num(p.net_pay)}</b></TableCell>
                    <TableCell sx={{ fontSize: 13 }}>{PAYMENT_METHODS[p.payment_method]}{p.payment_reference ? ` · ${p.payment_reference}` : ''}</TableCell>
                    <TableCell align="right"><Button size="small" onClick={() => openDocument(`/payroll.php?action=payslip&id=${p.id}`).catch(notify)}>Payslip</Button></TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </TableContainer>
        </>
      )}

      <Dialog open={dialog === 'approve'} onClose={() => setDialog(null)}>
        <DialogTitle>Approve {run && period(run.period_month, run.period_year)} payroll?</DialogTitle>
        <DialogContent>
          <Typography>Net pay of {kes(t.net)} for {run?.employee_count} employees. Once approved, this payroll and the
            month’s attendance are locked and can no longer be recalculated.</Typography>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDialog(null)}>Cancel</Button>
          <Button variant="contained" onClick={approve} disabled={busy}>Approve payroll</Button>
        </DialogActions>
      </Dialog>

      <Dialog open={dialog === 'pay'} onClose={() => setDialog(null)} maxWidth="md" fullWidth>
        <DialogTitle>Record salary payment</DialogTitle>
        <DialogContent>
          <Typography sx={{ mb: 2 }}>Pay salaries through your M-Pesa business account or bank, then record them here. Transaction
            references are optional but make reconciliation easier.</Typography>
          <Table size="small">
            <TableHead><TableRow><TableCell>Employee</TableCell><TableCell>Method</TableCell><TableCell align="right">Net pay</TableCell>
              <TableCell>Reference</TableCell></TableRow></TableHead>
            <TableBody>
              {run?.payslips.map((p) => (
                <TableRow key={p.id}>
                  <TableCell>{p.employee_name}</TableCell>
                  <TableCell>{PAYMENT_METHODS[p.payment_method]}{p.payment_method === 'mpesa' && p.mpesa_number ? ` ${p.mpesa_number}` : ''}</TableCell>
                  <TableCell align="right">{num(p.net_pay)}</TableCell>
                  <TableCell sx={{ width: 200 }}>
                    <TextField size="small" placeholder={p.payment_method === 'mpesa' ? 'e.g. SKL4ABC123' : 'Optional'}
                      value={refs[p.id] || ''} onChange={(e) => setRefs({ ...refs, [p.id]: e.target.value })}
                      inputProps={{ 'aria-label': `Reference for ${p.employee_name}` }} />
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDialog(null)}>Cancel</Button>
          <Button variant="contained" color="success" onClick={pay} disabled={busy}>Record {kes(t.net)} as paid</Button>
        </DialogActions>
      </Dialog>
    </>
  )
}
