import client from './client'

export const salesGroups = {
  list:   (params)     => client.get('/api/sales-groups', { params }),
  get:    (id)         => client.get(`/api/sales-groups/${id}`),
  create: (data)       => client.post('/api/sales-groups', data),
  update: (id, data)   => client.put(`/api/sales-groups/${id}`, data),
  remove: (id)         => client.delete(`/api/sales-groups/${id}`),

  // Tagság. Szándékosan külön végpont-pár: a tagság mentése független a
  // csoport-űrlap (name) mentésétől, így a két mentési út nem írhatja felül
  // egymás időközbeni változását.
  listUsers: (id)          => client.get(`/api/sales-groups/${id}/users`),
  syncUsers: (id, userIds) => client.put(`/api/sales-groups/${id}/users`, { user_ids: userIds }),
}
