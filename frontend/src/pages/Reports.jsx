import { useQuery } from 'react-query'
import { Box, Button, Paper, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, Typography } from '@mui/material'
import { downloadFile, get, openDocument } from '../api'
import { useNotify } from '../notify'
import { num, period } from '../format'
import { Empty, ErrorState, Loading, PageHeader, StatusChip } from '../components/ui'

export default function Reports() {
  const notify = useNotify()
  const { data, isLoading, error, refetch } = useQuery('runs', () => get('/payroll.php', { action: 'runs' }))
  const open = (type, id) => openDocument(`/payroll.php?action=report&type=${type}&run_id=${id}`).catch(notify)

  return (
    <>
      <PageHeader title="Reports" subtitle="Payroll summaries, registers and the statutory amounts to remit for each month." />
      <Paper sx={{ p: 2.5, mb: 2 }}>
        <Typography sx={{ fontSize: 14.5 }}>
          <b>Summary</b>: totals and the full cost to the business. <b>Register</b>: every employee’s pay line by line.{' '}
          <b>Statutory</b>: PAYE, NSSF, SHIF and Housing Levy to remit to KRA, NSSF and the Social Health Authority,
          with a CSV for your records.
        </Typography>
      </Paper>
      {isLoading ? <Loading /> : error ? <ErrorState error={error} onRetry={refetch} /> : !data.length ? (
        <Empty title="No payroll runs yet">Reports appear here once a month’s payroll has been generated.</Empty>
      ) : (
        <TableContainer component={Paper}>
          <Table size="small">
            <TableHead><TableRow><TableCell>Month</TableCell><TableCell>Status</TableCell><TableCell align="right">Employees</TableCell>
              <TableCell align="right">Gross (KES)</TableCell><TableCell align="right">Net (KES)</TableCell><TableCell>Reports</TableCell></TableRow></TableHead>
            <TableBody>
              {data.map((r) => (
                <TableRow key={r.id}>
                  <TableCell><b>{period(r.period_month, r.period_year)}</b></TableCell>
                  <TableCell><StatusChip status={r.status} /></TableCell>
                  <TableCell align="right">{r.employee_count}</TableCell>
                  <TableCell align="right">{num(r.total_gross)}</TableCell>
                  <TableCell align="right">{num(r.total_net)}</TableCell>
                  <TableCell>
                    <Box sx={{ display: 'flex', gap: 0.5, flexWrap: 'wrap' }}>
                      <Button size="small" onClick={() => open('summary', r.id)}>Summary</Button>
                      <Button size="small" onClick={() => open('detailed', r.id)}>Register</Button>
                      <Button size="small" onClick={() => open('statutory', r.id)}>Statutory</Button>
                      <Button size="small" onClick={() => downloadFile(`/payroll.php?action=report&type=statutory&format=csv&run_id=${r.id}`,
                        `statutory-${r.period_year}-${String(r.period_month).padStart(2, '0')}.csv`).catch(notify)}>CSV</Button>
                    </Box>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TableContainer>
      )}
    </>
  )
}
