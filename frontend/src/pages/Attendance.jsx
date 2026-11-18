import { useEffect, useState } from 'react'
import { useQuery, useQueryClient } from 'react-query'
import { Alert, Box, Button, MenuItem, Paper, Tab, Table, TableBody, TableCell, TableContainer, TableHead, TableRow,
  Tabs, TextField, Typography } from '@mui/material'
import { get, post } from '../api'
import { useNotify } from '../notify'
import { lastMonth, today } from '../format'
import { Empty, ErrorState, Loading, MonthPicker, PageHeader } from '../components/ui'

const STATUSES = [['present', 'Present'], ['late', 'Late'], ['absent', 'Absent'], ['half_day', 'Half day'], ['on_leave', 'On leave']]

function DailyRegister() {
  const notify = useNotify()
  const qc = useQueryClient()
  const [day, setDay] = useState(today())
  const [rows, setRows] = useState([])
  const [busy, setBusy] = useState(false)
  const { data, isLoading, error, refetch } = useQuery(['register', day], () => get('/attendance.php', { action: 'register', date: day }))
  useEffect(() => { if (data) setRows(data.map((r) => ({ ...r, status: r.status || '', overtime_hours: r.overtime_hours ?? 0 }))) }, [data])
  const set = (i, k, v) => setRows((rs) => rs.map((r, j) => (j === i ? { ...r, [k]: v } : r)))

  const save = async () => {
    setBusy(true)
    try {
      const res = await post('/attendance.php?action=bulk', {
        attendance_date: day,
        records: rows.filter((r) => r.status).map((r) => ({ employee_id: r.employee_id, status: r.status,
          clock_in: r.clock_in?.slice(0, 5) || null, clock_out: r.clock_out?.slice(0, 5) || null,
          overtime_hours: r.overtime_hours || 0, notes: r.notes })),
      })
      notify(res.message)
      qc.invalidateQueries('register')
      qc.invalidateQueries('attendance-month')
    } catch (err) {
      notify(err)
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <Box sx={{ display: 'flex', gap: 1.5, mb: 2, alignItems: 'center', flexWrap: 'wrap' }}>
        <TextField type="date" label="Date" value={day} onChange={(e) => setDay(e.target.value)} sx={{ width: 180 }}
          InputLabelProps={{ shrink: true }} inputProps={{ max: today() }} />
        <Button variant="outlined" onClick={() => setRows((rs) => rs.map((r) => ({ ...r, status: r.status || 'present' })))}>Mark the rest present</Button>
        <Box sx={{ flexGrow: 1 }} />
        <Button variant="contained" onClick={save} disabled={busy || !rows.some((r) => r.status)}>Save attendance</Button>
      </Box>
      <Alert severity="info" sx={{ mb: 2 }}>Overtime hours and absences recorded here are used when that month’s payroll is calculated.</Alert>
      {isLoading ? <Loading /> : error ? <ErrorState error={error} onRetry={refetch} /> : !rows.length ? <Empty title="No active employees on this date" /> : (
        <TableContainer component={Paper}>
          <Table size="small">
            <TableHead><TableRow><TableCell>Employee</TableCell><TableCell>Status</TableCell><TableCell>In</TableCell>
              <TableCell>Out</TableCell><TableCell>Overtime (h)</TableCell></TableRow></TableHead>
            <TableBody>
              {rows.map((r, i) => (
                <TableRow key={r.employee_id}>
                  <TableCell><b>{r.employee_name}</b><Typography color="text.secondary" sx={{ fontSize: 12 }}>
                    {r.employee_number}{r.recorded_by === 'employee' ? ' · clocked in on the app' : ''}</Typography></TableCell>
                  <TableCell sx={{ width: 160 }}>
                    <TextField select value={r.status} onChange={(e) => set(i, 'status', e.target.value)} inputProps={{ 'aria-label': `Status for ${r.employee_name}` }}>
                      <MenuItem value=""><em>Not recorded</em></MenuItem>
                      {STATUSES.map(([v, l]) => <MenuItem key={v} value={v}>{l}</MenuItem>)}
                    </TextField>
                  </TableCell>
                  <TableCell sx={{ width: 130 }}><TextField type="time" value={r.clock_in?.slice(0, 5) || ''} onChange={(e) => set(i, 'clock_in', e.target.value)} inputProps={{ 'aria-label': 'Clock in' }} /></TableCell>
                  <TableCell sx={{ width: 130 }}><TextField type="time" value={r.clock_out?.slice(0, 5) || ''} onChange={(e) => set(i, 'clock_out', e.target.value)} inputProps={{ 'aria-label': 'Clock out' }} /></TableCell>
                  <TableCell sx={{ width: 120 }}><TextField type="number" value={r.overtime_hours} inputProps={{ min: 0, max: 16, step: 0.5, 'aria-label': 'Overtime hours' }} onChange={(e) => set(i, 'overtime_hours', e.target.value)} /></TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TableContainer>
      )}
    </>
  )
}

function MonthSummary() {
  const [sel, setSel] = useState(lastMonth())
  const { data, isLoading, error, refetch } = useQuery(['attendance-month', sel.month, sel.year], () => get('/attendance.php', sel))
  return (
    <>
      <Box sx={{ mb: 2 }}><MonthPicker {...sel} onChange={setSel} /></Box>
      {isLoading ? <Loading /> : error ? <ErrorState error={error} onRetry={refetch} /> : !data.summary.length ? <Empty title="No attendance recorded for this month" /> : (
        <TableContainer component={Paper}>
          <Table size="small">
            <TableHead><TableRow><TableCell>Employee</TableCell><TableCell align="right">Present</TableCell><TableCell align="right">Late</TableCell>
              <TableCell align="right">Absent</TableCell><TableCell align="right">On leave</TableCell><TableCell align="right">Overtime (h)</TableCell></TableRow></TableHead>
            <TableBody>
              {data.summary.map((s) => (
                <TableRow key={s.employee_id}>
                  <TableCell><b>{s.employee_name}</b> <Typography component="span" color="text.secondary" sx={{ fontSize: 12 }}>{s.employee_number}</Typography></TableCell>
                  <TableCell align="right">{Number(s.present_days)}</TableCell><TableCell align="right">{Number(s.late_days)}</TableCell>
                  <TableCell align="right">{Number(s.absent_days)}</TableCell><TableCell align="right">{Number(s.leave_days)}</TableCell>
                  <TableCell align="right">{Number(s.overtime_hours)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TableContainer>
      )}
    </>
  )
}

export default function Attendance() {
  const [tab, setTab] = useState(0)
  return (
    <>
      <PageHeader title="Attendance" subtitle="Record each day’s attendance, or let employees clock in from the mobile app." />
      <Tabs value={tab} onChange={(_, v) => setTab(v)} sx={{ mb: 2 }}><Tab label="Daily register" /><Tab label="Monthly summary" /></Tabs>
      {tab === 0 ? <DailyRegister /> : <MonthSummary />}
    </>
  )
}
