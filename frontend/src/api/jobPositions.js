import client from './client'

export const jobPositions = {
  list:   (params)   => client.get('/api/job-positions', { params }),
  create: (data)     => client.post('/api/job-positions', data),
  update: (id, data) => client.put(`/api/job-positions/${id}`, data),
  remove: (id)        => client.delete(`/api/job-positions/${id}`),
}
