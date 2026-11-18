const kesFmt = new Intl.NumberFormat('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
export const kes = (v) => (v === null || v === undefined || v === '' ? '—' : `KES ${kesFmt.format(Number(v))}`)
export const num = (v) => kesFmt.format(Number(v || 0))

export const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September',
  'October', 'November', 'December']
export const period = (m, y) => `${MONTHS[Number(m) - 1]} ${y}`

export const date = (d) =>
  d ? new Date(d.length === 10 ? `${d}T00:00:00` : d.replace(' ', 'T')).toLocaleDateString('en-KE',
    { day: 'numeric', month: 'short', year: 'numeric' }) : '—'
export const dateTime = (d) =>
  d ? new Date(d.replace(' ', 'T')).toLocaleString('en-KE', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—'
export const today = () => new Date().toLocaleDateString('en-CA') // YYYY-MM-DD in local time

export const ROLE_LABELS = {
  super_admin: 'Administrator',
  hr_manager: 'HR manager',
  payroll_officer: 'Payroll officer',
  manager: 'Line manager',
  employee: 'Employee',
}
export const PAYMENT_METHODS = { mpesa: 'M-Pesa', bank_transfer: 'Bank transfer', cash: 'Cash', cheque: 'Cheque' }
export const fullName = (p) => (p ? `${p.first_name} ${p.last_name}` : '')

/** Last calendar month, the usual payroll month. */
export function lastMonth() {
  const d = new Date()
  d.setDate(1)
  d.setMonth(d.getMonth() - 1)
  return { month: d.getMonth() + 1, year: d.getFullYear() }
}
