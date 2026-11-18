import { useState } from 'react'
import { useQuery, useQueryClient } from 'react-query'
import { Box, Button, Dialog, DialogActions, DialogContent, DialogTitle, Paper, Tab, Table, TableBody, TableCell,
  TableContainer, TableHead, TableRow, Tabs, TextField, Typography } from '@mui/material'
import { get, post } from '../api'
import { useAuth } from '../auth'
import { useNotify } from '../notify'
import { date } from '../format'
import { Empty, ErrorState, Loading, PageHeader, StatusChip } from '../components/ui'

export default function Leave() {
  const { can } = useAuth()
  const notify = useNotify()
  const qc = useQueryClient()
  const [tab, setTab] = useState('pending')
  const [review, setReview] = useState(null) // { request, status }
  const [comment, setComment] = useState('')
  const [busy, setBusy] = useState(false)
  const { data, isLoading, error, refetch } = useQuery(['leave', tab],
    () => get('/leave.php', { status: tab === 'all' ? undefined : tab }))

  const submit = async () => {
    setBusy(true)
    try {
      const res = await post('/leave.php?action=review', { id: review.request.id, status: review.status, comment })
      notify(res.message)
      setReview(null)
      setComment('')
      qc.invalidateQueries('leave')
      qc.invalidateQueries('dashboard')
    } catch (err) {
      notify(err)
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <PageHeader title="Leave" subtitle="Employees apply from their portal or the mobile app; requests appear here for review." />
      <Tabs value={tab} onChange={(_, v) => setTab(v)} sx={{ mb: 2 }}>
        <Tab value="pending" label="Awaiting review" /><Tab value="approved" label="Approved" />
        <Tab value="rejected" label="Rejected" /><Tab value="all" label="All" />
      </Tabs>
      {isLoading ? <Loading /> : error ? <ErrorState error={error} onRetry={refetch} /> : !data.length ? (
        <Empty title={tab === 'pending' ? 'No leave requests waiting' : 'No leave requests here'} />
      ) : (
        <TableContainer component={Paper}>
          <Table size="small">
            <TableHead>
              <TableRow><TableCell>Employee</TableCell><TableCell>Type</TableCell><TableCell>Dates</TableCell>
                <TableCell align="right">Days</TableCell><TableCell>Reason</TableCell><TableCell>Status</TableCell><TableCell /></TableRow>
            </TableHead>
            <TableBody>
              {data.map((r) => (
                <TableRow key={r.id}>
                  <TableCell><b>{r.employee_name}</b><Typography color="text.secondary" sx={{ fontSize: 12 }}>{r.employee_number}</Typography></TableCell>
                  <TableCell>{r.leave_type}</TableCell>
                  <TableCell>{date(r.start_date)} – {date(r.end_date)}</TableCell>
                  <TableCell align="right">{Number(r.days)}</TableCell>
                  <TableCell sx={{ maxWidth: 260 }}>{r.reason || '—'}
                    {r.review_comment && <Typography color="text.secondary" sx={{ fontSize: 12 }}>{r.reviewed_by_name}: {r.review_comment}</Typography>}
                  </TableCell>
                  <TableCell><StatusChip status={r.status} /></TableCell>
                  <TableCell align="right" sx={{ whiteSpace: 'nowrap' }}>
                    {r.status === 'pending' && can('leave.review') && (
                      <>
                        <Button size="small" onClick={() => setReview({ request: r, status: 'approved' })}>Approve</Button>
                        <Button size="small" color="error" onClick={() => setReview({ request: r, status: 'rejected' })}>Reject</Button>
                      </>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TableContainer>
      )}

      <Dialog open={Boolean(review)} onClose={() => setReview(null)} fullWidth maxWidth="xs">
        <DialogTitle>{review?.status === 'approved' ? 'Approve' : 'Reject'} leave for {review?.request.employee_name}</DialogTitle>
        <DialogContent>
          <Typography sx={{ mb: 2 }}>{review?.request.leave_type}, {Number(review?.request.days)} working day(s),
            {' '}{date(review?.request.start_date)} to {date(review?.request.end_date)}.</Typography>
          <TextField label={review?.status === 'rejected' ? 'Reason (required)' : 'Comment (optional)'} multiline minRows={2}
            value={comment} onChange={(e) => setComment(e.target.value)} />
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setReview(null)}>Cancel</Button>
          <Button variant="contained" color={review?.status === 'rejected' ? 'error' : 'primary'} onClick={submit}
            disabled={busy || (review?.status === 'rejected' && !comment.trim())}>
            {review?.status === 'approved' ? 'Approve leave' : 'Reject leave'}
          </Button>
        </DialogActions>
      </Dialog>
    </>
  )
}
