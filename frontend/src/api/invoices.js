import client from './client'

export const invoices = {
  list: (params) => client.get('/api/invoices', { params }),
  get: (id) => client.get(`/api/invoices/${id}`),
  create: (data) => client.post('/api/invoices', data),
  cancel: (id) => client.post(`/api/invoices/${id}/cancel`),
  payments: (id) => client.get(`/api/invoices/${id}/payments`),
  addPayment: (id, data) => client.post(`/api/invoices/${id}/payments`, data),
}
