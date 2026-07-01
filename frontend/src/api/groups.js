import client from './client'

export const groups = {
  list:            (params)     => client.get('/api/groups', { params }),
  create:          (data)       => client.post('/api/groups', data),
  get:             (id)         => client.get(`/api/groups/${id}`),
  update:          (id, data)   => client.put(`/api/groups/${id}`, data),
  remove:          (id)         => client.delete(`/api/groups/${id}`),
  syncPermissions: (id, ids)    => client.put(`/api/groups/${id}/permissions`, { permission_ids: ids }),
  addMember:       (id, userId) => client.post(`/api/groups/${id}/members`, { user_id: userId }),
  removeMember:    (id, userId) => client.delete(`/api/groups/${id}/members/${userId}`),
}
