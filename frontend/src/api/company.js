import client from './client'

export const company = {
  get: () => client.get('/api/company'),
  update: (data) => client.put('/api/company', data),
  auditLogs: (params) => client.get('/api/audit-logs', { params }),
}
