import client from './client'

export const partners = {
  list: (params) => client.get('/api/partners', { params }),
  get: (id) => client.get(`/api/partners/${id}`),
  create: (data) => client.post('/api/partners', data),
  update: (id, data) => client.put(`/api/partners/${id}`, data),
  destroy: (id) => client.delete(`/api/partners/${id}`),
}
