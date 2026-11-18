import { useQuery } from 'react-query'
import { Link as RouterLink } from 'react-router-dom'
import { Box, Button, Grid, List, ListItem, ListItemText, Paper, Typography } from '@mui/material'
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { get } from '../api'
import { useAuth } from '../auth'
import { dateTime, kes, MONTHS, period } from '../format'
import { tokens } from '../theme'
import { ErrorState, Loading, PageHeader, Stat, StatusChip } from '../components/ui'

const ACTIONS = {
  'payroll.generated': 'generated payroll', 'payroll.regenerated': 'regenerated payroll', 'payroll.approved': 'approved payroll',
  'payroll.paid': 'recorded salary payment', 'employee.created': 'added an employee', 'employee.updated': 'updated an employee',
  'employee.salary_changed': 'changed a salary', 'leave.applied': 'applied for leave', 'leave.approved': 'approved leave',
  'leave.rejected': 'rejected leave', 'attendance.recorded': 'recorded attendance', 'attendance.clock_in': 'clocked in',
}

export default function Dashboard() {
  const { user, can } = useAuth()
  const { data, isLoading, error, refetch } = useQuery('dashboard', () => get('/dashboard.php'))
  if (isLoading) return <Loading />
  if (error) return <ErrorState error={error} onRetry={refetch} />

  const trend = (data.payroll_trend || []).map((r) => ({
    name: `${MONTHS[r.period_month - 1].slice(0, 3)} ${String(r.period_year).slice(2)}`,
    Gross: Number(r.total_gross), 'Net pay': Number(r.total_net),
  }))
  const latest = data.latest_run

  return (
    <>
      <PageHeader title={`Good ${new Date().getHours() < 12 ? 'morning' : 'afternoon'}, ${user.first_name}`}
        subtitle={user.organization_name}
        actions={can('payroll.manage') && <Button variant="contained" component={RouterLink} to="/payroll">Run payroll</Button>} />

      <Grid container spacing={2} sx={{ mb: 2 }}>
        <Grid item xs={6} md={3}><Stat label="Active employees" value={data.employees_active} hint={`${data.employees_total} on record`} /></Grid>
        <Grid item xs={6} md={3}><Stat label="Present today" value={data.present_today} /></Grid>
        <Grid item xs={6} md={3}><Stat label="On leave today" value={data.on_leave_today} /></Grid>
        <Grid item xs={6} md={3}>
          <Stat label="Leave awaiting review" value={data.pending_leave}
            color={data.pending_leave ? tokens.pending : undefined} hint={data.pending_leave ? 'Open the Leave page to review' : 'Nothing waiting'} />
        </Grid>
      </Grid>

      <Grid container spacing={2}>
        {can('payroll.view') && (
          <Grid item xs={12} md={8}>
            <Paper sx={{ p: 2.5, height: '100%' }}>
              <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', mb: 2 }}>
                <Typography variant="h6">Payroll cost, last six runs</Typography>
                {latest && (
                  <Box sx={{ display: 'flex', gap: 1, alignItems: 'center' }}>
                    <Typography color="text.secondary" sx={{ fontSize: 14 }}>{period(latest.period_month, latest.period_year)}</Typography>
                    <StatusChip status={latest.status} />
                  </Box>
                )}
              </Box>
              {trend.length ? (
                <Box sx={{ height: 260 }}>
                  <ResponsiveContainer>
                    <BarChart data={trend} margin={{ left: 10 }}>
                      <CartesianGrid vertical={false} stroke={tokens.line} />
                      <XAxis dataKey="name" tickLine={false} axisLine={false} fontSize={12} />
                      <YAxis tickLine={false} axisLine={false} fontSize={12} tickFormatter={(v) => `${Math.round(v / 1000)}k`} />
                      <Tooltip formatter={(v) => kes(v)} />
                      <Legend />
                      <Bar dataKey="Gross" fill={tokens.inkSoft} radius={[3, 3, 0, 0]} maxBarSize={56} />
                      <Bar dataKey="Net pay" fill={tokens.paid} radius={[3, 3, 0, 0]} maxBarSize={56} />
                    </BarChart>
                  </ResponsiveContainer>
                </Box>
              ) : (
                <Typography color="text.secondary">No payroll has been run yet. Generate your first payroll from the Payroll page.</Typography>
              )}
            </Paper>
          </Grid>
        )}
        <Grid item xs={12} md={can('payroll.view') ? 4 : 6}>
          <Paper sx={{ p: 2.5, height: '100%' }}>
            <Typography variant="h6" sx={{ mb: 1.5 }}>Headcount by department</Typography>
            {data.by_department.map((d) => (
              <Box key={d.department} sx={{ mb: 1.25 }}>
                <Box sx={{ display: 'flex', justifyContent: 'space-between', fontSize: 14 }}>
                  <span>{d.department}</span><b>{d.employees}</b>
                </Box>
                <Box sx={{ height: 6, bgcolor: tokens.canvas, borderRadius: 3, mt: 0.5 }}>
                  <Box sx={{ height: 6, borderRadius: 3, bgcolor: tokens.inkSoft,
                    width: `${(d.employees / Math.max(1, data.employees_active)) * 100}%` }} />
                </Box>
              </Box>
            ))}
            {!data.by_department.length && <Typography color="text.secondary">Add employees to see your headcount.</Typography>}
          </Paper>
        </Grid>
        <Grid item xs={12} md={can('payroll.view') ? 12 : 6}>
          <Paper sx={{ p: 2.5 }}>
            <Typography variant="h6">Recent activity</Typography>
            <List dense>
              {data.recent_activity.map((a, i) => (
                <ListItem key={i} disableGutters divider={i < data.recent_activity.length - 1}>
                  <ListItemText primary={`${a.actor || 'System'} ${ACTIONS[a.action] || a.action.replace('.', ' ')}`}
                    secondary={dateTime(a.created_at)} />
                </ListItem>
              ))}
              {!data.recent_activity.length && <Typography color="text.secondary">No activity yet.</Typography>}
            </List>
          </Paper>
        </Grid>
      </Grid>
    </>
  )
}
