import client from './client'

export const salesGroups = {
  list:   (params)     => client.get('/api/sales-groups', { params }),
  create: (data)       => client.post('/api/sales-groups', data),
  update: (id, data)   => client.put(`/api/sales-groups/${id}`, data),
  remove: (id)         => client.delete(`/api/sales-groups/${id}`),
}
