import client from './client'

export const users = {
  list:          (params)       => client.get('/api/users', { params }),
  get:           (id)           => client.get(`/api/users/${id}`),
  create:        (data)         => client.post('/api/users', data),
  update:        (id, data)     => client.put(`/api/users/${id}`, data),
  remove:        (id)           => client.delete(`/api/users/${id}`),
  syncOverrides: (id, overrides) => client.put(`/api/users/${id}/overrides`, { overrides }),

  listCompanies:  (userId)             => client.get(`/api/users/${userId}/companies`),
  attachCompany:  (userId, companyId)  => client.post(`/api/users/${userId}/companies/${companyId}`),
  detachCompany:  (userId, companyId)  => client.delete(`/api/users/${userId}/companies/${companyId}`),

  listTokens:     (userId)          => client.get(`/api/users/${userId}/tokens`),
  deleteToken:    (userId, tokenId) => client.delete(`/api/users/${userId}/tokens/${tokenId}`),

  changePassword: (userId, password) => client.put(`/api/users/${userId}/password`, { password }),
}
