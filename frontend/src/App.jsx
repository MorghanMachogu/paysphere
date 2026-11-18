import { Navigate, Route, Routes, useLocation } from 'react-router-dom'
import { Box, CircularProgress } from '@mui/material'
import { useAuth } from './auth'
import AppLayout from './components/AppLayout'
import Login from './pages/Login'
import Signup from './pages/Signup'
import ChangePassword from './pages/ChangePassword'
import Dashboard from './pages/Dashboard'
import Employees from './pages/Employees'
import EmployeeForm from './pages/EmployeeForm'
import EmployeeDetail from './pages/EmployeeDetail'
import Payroll from './pages/Payroll'
import Leave from './pages/Leave'
import Attendance from './pages/Attendance'
import Reports from './pages/Reports'
import Settings from './pages/Settings'
import EmployeePortal from './pages/EmployeePortal'

function Splash() {
  return (
    <Box sx={{ minHeight: '100vh', display: 'grid', placeItems: 'center' }}>
      <CircularProgress aria-label="Loading" />
    </Box>
  )
}

/** Requires login; sends each user type to its own area; enforces the first-login password change. */
function RequireAuth({ type, permission, children }) {
  const { user, loading, can } = useAuth()
  const location = useLocation()
  if (loading) return <Splash />
  if (!user) return <Navigate to="/login" replace state={{ from: location.pathname }} />
  if (user.force_password_change && location.pathname !== '/change-password') return <Navigate to="/change-password" replace />
  if (type && user.user_type !== type) return <Navigate to={user.user_type === 'employee' ? '/portal' : '/'} replace />
  if (permission && !can(permission)) return <Navigate to="/" replace />
  return children
}

function PublicOnly({ children }) {
  const { user, loading } = useAuth()
  if (loading) return <Splash />
  if (user) return <Navigate to={user.user_type === 'employee' ? '/portal' : '/'} replace />
  return children
}

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<PublicOnly><Login /></PublicOnly>} />
      <Route path="/signup" element={<PublicOnly><Signup /></PublicOnly>} />
      <Route path="/change-password" element={<RequireAuth><ChangePassword /></RequireAuth>} />
      <Route path="/portal" element={<RequireAuth type="employee"><EmployeePortal /></RequireAuth>} />
      <Route element={<RequireAuth type="employer"><AppLayout /></RequireAuth>}>
        <Route index element={<Dashboard />} />
        <Route path="employees" element={<Employees />} />
        <Route path="employees/new" element={<RequireAuth permission="employees.manage"><EmployeeForm /></RequireAuth>} />
        <Route path="employees/:id" element={<EmployeeDetail />} />
        <Route path="employees/:id/edit" element={<RequireAuth permission="employees.manage"><EmployeeForm /></RequireAuth>} />
        <Route path="payroll" element={<RequireAuth permission="payroll.view"><Payroll /></RequireAuth>} />
        <Route path="leave" element={<Leave />} />
        <Route path="attendance" element={<RequireAuth permission="attendance.manage"><Attendance /></RequireAuth>} />
        <Route path="reports" element={<RequireAuth permission="reports.view"><Reports /></RequireAuth>} />
        <Route path="settings" element={<Settings />} />
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
