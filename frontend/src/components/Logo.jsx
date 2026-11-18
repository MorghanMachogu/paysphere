import { Box, Typography } from '@mui/material'
import { tokens } from '../theme'

export default function Logo({ light = false, size = 20 }) {
  const color = light ? '#fff' : tokens.ink
  return (
    <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
      <svg width={size + 6} height={size + 6} viewBox="0 0 64 64" aria-hidden="true">
        <circle cx="32" cy="32" r="22" fill="none" stroke={color} strokeWidth="7" />
        <path d="M10 32h44" stroke={tokens.paid} strokeWidth="7" strokeLinecap="round" />
        <circle cx="32" cy="32" r="6" fill={color} />
      </svg>
      <Typography sx={{ fontWeight: 700, fontSize: size, color, letterSpacing: '-0.01em' }}>PaySphere</Typography>
    </Box>
  )
}
