import client from './client'

export const customFields = {
  list: (entityType) =>
    client.get('/api/custom-fields', { params: entityType ? { entity_type: entityType } : {} }),
  create: (data) => client.post('/api/custom-fields', data),
  update: (id, data) => client.put(`/api/custom-fields/${id}`, data),
  delete: (id) => client.delete(`/api/custom-fields/${id}`),
}
