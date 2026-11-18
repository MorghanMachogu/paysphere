import { createTheme } from '@mui/material/styles'

// PaySphere tokens: ink navy for structure, green only for money that has been paid,
// amber for anything awaiting action. Plex Sans with tabular figures keeps amounts aligned.
export const tokens = {
  ink: '#0F3D5E',
  inkSoft: '#2C5878',
  paid: '#1F7A5C',
  pending: '#B76E00',
  danger: '#B42318',
  canvas: '#F4F6F8',
  line: '#DDE3E9',
  text: '#1D2939',
  muted: '#5B6B7A',
}

const theme = createTheme({
  palette: {
    primary: { main: tokens.ink },
    secondary: { main: tokens.paid },
    success: { main: tokens.paid },
    warning: { main: tokens.pending },
    error: { main: tokens.danger },
    background: { default: tokens.canvas, paper: '#FFFFFF' },
    text: { primary: tokens.text, secondary: tokens.muted },
    divider: tokens.line,
  },
  typography: {
    fontFamily: '"IBM Plex Sans", "Segoe UI", system-ui, -apple-system, sans-serif',
    h4: { fontWeight: 600, fontSize: '1.6rem', letterSpacing: '-0.01em' },
    h5: { fontWeight: 600, fontSize: '1.3rem' },
    h6: { fontWeight: 600, fontSize: '1.05rem' },
    subtitle2: { fontWeight: 600 },
    button: { textTransform: 'none', fontWeight: 600 },
  },
  shape: { borderRadius: 6 },
  components: {
    MuiCssBaseline: {
      styleOverrides: {
        body: { fontFeatureSettings: '"tnum" 1' },
        '*:focus-visible': { outline: `2px solid ${tokens.ink}`, outlineOffset: 2 },
      },
    },
    MuiPaper: { defaultProps: { elevation: 0 }, styleOverrides: { root: { border: `1px solid ${tokens.line}` } } },
    MuiAppBar: { styleOverrides: { root: { border: 0 } } },
    MuiDrawer: { styleOverrides: { paper: { border: 0 } } },
    MuiButton: { defaultProps: { disableElevation: true } },
    MuiTextField: { defaultProps: { size: 'small', fullWidth: true } },
    MuiTableCell: {
      styleOverrides: {
        head: { fontWeight: 600, color: tokens.muted, fontSize: '0.8rem', background: '#F8FAFB' },
        root: { borderColor: tokens.line },
      },
    },
    MuiChip: { styleOverrides: { root: { fontWeight: 600 } } },
  },
})

export default theme
