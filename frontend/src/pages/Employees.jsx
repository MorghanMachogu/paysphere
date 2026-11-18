import { useState } from 'react'
import { useQuery } from 'react-query'
import { Link as RouterLink, useNavigate } from 'react-router-dom'
import { Box, Button, InputAdornment, MenuItem, Paper, Table, TableBody, TableCell, TableContainer, TableHead,
  TableRow, TextField } from '@mui/material'
import { Add, Search } from '@mui/icons-material'
import { get } from '../api'
import { useAuth } from '../auth'
import { kes } from '../format'
import { Empty, ErrorState, Loading, PageHeader, StatusChip } from '../components/ui'

export default function Employees() {
  const { can } = useAuth()
  const navigate = useNavigate()
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('active')
  const [dept, setDept] = useState('')
  const { data: departments = [] } = useQuery('departments', () => get('/departments.php'))
  const { data, isLoading, error, refetch } = useQuery(['employees', status, dept],
    () => get('/employees.php', { status: status || undefined, department_id: dept || undefined }), { keepPreviousData: true })

  const q = search.trim().toLowerCase()
  const rows = (data || []).filter((e) => !q || `${e.first_name} ${e.last_name} ${e.employee_number} ${e.national_id}`
    .toLowerCase().includes(q))

  return (
    <>
      <PageHeader title="Employees" subtitle={data ? `${rows.length} shown` : ''}
        actions={can('employees.manage') && (
          <Button variant="contained" startIcon={<Add />} component={RouterLink} to="/employees/new">Add employee</Button>)} />
      <Box sx={{ display: 'flex', gap: 1.5, mb: 2, flexWrap: 'wrap' }}>
        <TextField placeholder="Search by name, number or ID" value={search} onChange={(e) => setSearch(e.target.value)}
          sx={{ maxWidth: 340 }} InputProps={{ startAdornment: <InputAdornment position="start"><Search /></InputAdornment> }} />
        <TextField select label="Status" value={status} onChange={(e) => setStatus(e.target.value)} sx={{ width: 150 }}>
          <MenuItem value="">All</MenuItem><MenuItem value="active">Active</MenuItem>
          <MenuItem value="inactive">Inactive</MenuItem><MenuItem value="terminated">Terminated</MenuItem>
        </TextField>
        <TextField select label="Department" value={dept} onChange={(e) => setDept(e.target.value)} sx={{ width: 190 }}>
          <MenuItem value="">All departments</MenuItem>
          {departments.map((d) => <MenuItem key={d.id} value={d.id}>{d.name}</MenuItem>)}
        </TextField>
      </Box>
      {isLoading ? <Loading /> : error ? <ErrorState error={error} onRetry={refetch} /> : !rows.length ? (
        <Empty title={data.length ? 'No employees match your search' : 'No employees yet'}
          action={!data.length && can('employees.manage') && <Button variant="contained" component={RouterLink} to="/employees/new">Add your first employee</Button>}>
          {data.length ? 'Try a different name or clear the filters.' : 'Add employees with their salary to start running payroll.'}
        </Empty>
      ) : (
        <TableContainer component={Paper}>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>No.</TableCell><TableCell>Name</TableCell><TableCell>Department</TableCell>
                <TableCell>Position</TableCell><TableCell>Phone</TableCell>
                {can('payroll.view') && <TableCell align="right">Basic salary</TableCell>}
                <TableCell>Status</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {rows.map((e) => (
                <TableRow key={e.id} hover sx={{ cursor: 'pointer' }} onClick={() => navigate(`/employees/${e.id}`)}>
                  <TableCell>{e.employee_number}</TableCell>
                  <TableCell sx={{ fontWeight: 500 }}>{e.first_name} {e.last_name}</TableCell>
                  <TableCell>{e.department || '—'}</TableCell>
                  <TableCell>{e.position || '—'}</TableCell>
                  <TableCell>{e.phone_number || '—'}</TableCell>
                  {can('payroll.view') && <TableCell align="right">{kes(e.basic_salary)}</TableCell>}
                  <TableCell><StatusChip status={e.status} /></TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TableContainer>
      )}
    </>
  )
}
