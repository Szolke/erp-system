import client from './client'

export const assets = {
  list:    (params)   => client.get('/api/assets', { params }),
  get:     (id)       => client.get(`/api/assets/${id}`),
  create:  (data)     => client.post('/api/assets', data),
  update:  (id, data) => client.put(`/api/assets/${id}`, data),
  destroy: (id)       => client.delete(`/api/assets/${id}`),
}

export const assetTypes = {
  list:   (params) => client.get('/api/asset-types', { params }),
  create: (data)   => client.post('/api/asset-types', data),
}
