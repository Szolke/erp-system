import client from './client'

export const receipts = {
  list: (params) => client.get('/api/receipts', { params }),
  get: (id) => client.get(`/api/receipts/${id}`),
  create: (data) => client.post('/api/receipts', data),
  cancel: (id) => client.post(`/api/receipts/${id}/cancel`),
}
