import client from './client'

export const enyugtaSettings = {
  get: () => client.get('/api/settings/enyugta'),
  update: (payload) => client.put('/api/settings/enyugta', payload),
  copyFromNav: (params) => client.post('/api/settings/enyugta/copy-from-nav', params),
}

export const enyugtaReports = {
  list: (params) => client.get('/api/enyugta/reports', { params }),
  get: (id) => client.get(`/api/enyugta/reports/${id}`),
  export: (id) => client.get(`/api/enyugta/reports/${id}/export`, { responseType: 'blob' }),
}
