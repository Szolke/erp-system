import client from './client'

export const company = {
  get: () => client.get('/api/company'),
  update: (data) => client.put('/api/company', data),
  auditLogs: (params) => client.get('/api/audit-logs', { params }),
  settings: {
    getAll: () => client.get('/api/company/settings'),
    set: (key, value) => client.put(`/api/company/settings/${key}`, { value }),
    reset: (key) => client.delete(`/api/company/settings/${key}`),
  },
  simplePay: {
    list: () => client.get('/api/company/simplepay'),
    upsert: (currency, data) => client.put(`/api/company/simplepay/${currency}`, data),
    delete: (currency) => client.delete(`/api/company/simplepay/${currency}`),
  },
}
