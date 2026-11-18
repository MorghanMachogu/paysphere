import { useState } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { AppBar, Avatar, Box, Divider, Drawer, IconButton, List, ListItemButton, ListItemIcon, ListItemText,
  Menu, MenuItem, Toolbar, Typography } from '@mui/material'
import { AccessTime, Assessment, Dashboard, EventAvailable, Logout, Menu as MenuIcon, Payments, People,
  Settings as SettingsIcon, LockReset } from '@mui/icons-material'
import { useAuth } from '../auth'
import { ROLE_LABELS } from '../format'
import { tokens } from '../theme'
import Logo from './Logo'

const NAV = [
  { to: '/', label: 'Dashboard', icon: <Dashboard />, perm: 'employees.view', end: true },
  { to: '/employees', label: 'Employees', icon: <People />, perm: 'employees.view' },
  { to: '/payroll', label: 'Payroll', icon: <Payments />, perm: 'payroll.view' },
  { to: '/leave', label: 'Leave', icon: <EventAvailable />, perm: 'employees.view' },
  { to: '/attendance', label: 'Attendance', icon: <AccessTime />, perm: 'attendance.manage' },
  { to: '/reports', label: 'Reports', icon: <Assessment />, perm: 'reports.view' },
  { to: '/settings', label: 'Settings', icon: <SettingsIcon />, perm: 'employees.view' },
]
const WIDTH = 236

export default function AppLayout() {
  const { user, logout, can } = useAuth()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const [anchor, setAnchor] = useState(null)

  const drawer = (
    <Box sx={{ height: '100%', bgcolor: tokens.ink, color: '#fff', display: 'flex', flexDirection: 'column' }}>
      <Box sx={{ px: 2.5, py: 2.25 }}><Logo light /></Box>
      <Typography sx={{ px: 2.5, pb: 2, fontSize: 13, opacity: 0.75 }} noWrap>{user.organization_name}</Typography>
      <List sx={{ px: 1.25 }}>
        {NAV.filter((n) => can(n.perm)).map((n) => (
          <ListItemButton key={n.to} component={NavLink} to={n.to} end={n.end} onClick={() => setOpen(false)}
            sx={{ borderRadius: 1, mb: 0.25, color: 'rgba(255,255,255,.78)',
              '&.active': { bgcolor: 'rgba(255,255,255,.12)', color: '#fff' },
              '&:hover': { bgcolor: 'rgba(255,255,255,.08)' } }}>
            <ListItemIcon sx={{ color: 'inherit', minWidth: 36 }}>{n.icon}</ListItemIcon>
            <ListItemText primary={n.label} primaryTypographyProps={{ fontSize: 14.5, fontWeight: 500 }} />
          </ListItemButton>
        ))}
      </List>
    </Box>
  )

  return (
    <Box sx={{ display: 'flex', minHeight: '100vh' }}>
      <Box component="nav" sx={{ width: { md: WIDTH }, flexShrink: 0 }}>
        <Drawer variant="temporary" open={open} onClose={() => setOpen(false)}
          sx={{ display: { xs: 'block', md: 'none' }, '& .MuiDrawer-paper': { width: WIDTH } }}>{drawer}</Drawer>
        <Drawer variant="permanent" open
          sx={{ display: { xs: 'none', md: 'block' }, '& .MuiDrawer-paper': { width: WIDTH } }}>{drawer}</Drawer>
      </Box>
      <Box sx={{ flexGrow: 1, minWidth: 0 }}>
        <AppBar position="sticky" color="inherit" sx={{ bgcolor: '#fff', borderBottom: `1px solid ${tokens.line}` }}>
          <Toolbar sx={{ gap: 1 }}>
            <IconButton sx={{ display: { md: 'none' } }} onClick={() => setOpen(true)} aria-label="Open menu"><MenuIcon /></IconButton>
            <Box sx={{ flexGrow: 1 }} />
            <Box sx={{ textAlign: 'right', display: { xs: 'none', sm: 'block' } }}>
              <Typography sx={{ fontSize: 14, fontWeight: 600 }}>{user.first_name} {user.last_name}</Typography>
              <Typography sx={{ fontSize: 12, color: 'text.secondary' }}>{ROLE_LABELS[user.role]}</Typography>
            </Box>
            <IconButton onClick={(e) => setAnchor(e.currentTarget)} aria-label="Account menu">
              <Avatar sx={{ width: 34, height: 34, bgcolor: tokens.inkSoft, fontSize: 14 }}>
                {user.first_name?.[0]}{user.last_name?.[0]}
              </Avatar>
            </IconButton>
            <Menu anchorEl={anchor} open={Boolean(anchor)} onClose={() => setAnchor(null)}>
              <MenuItem onClick={() => { setAnchor(null); navigate('/change-password') }}>
                <ListItemIcon><LockReset fontSize="small" /></ListItemIcon>Change password
              </MenuItem>
              <Divider />
              <MenuItem onClick={async () => { await logout(); navigate('/login') }}>
                <ListItemIcon><Logout fontSize="small" /></ListItemIcon>Log out
              </MenuItem>
            </Menu>
          </Toolbar>
        </AppBar>
        <Box component="main" sx={{ p: { xs: 2, sm: 3 }, maxWidth: 1280, mx: 'auto' }}>
          <Outlet />
        </Box>
      </Box>
    </Box>
  )
}
