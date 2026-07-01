import client from './client'

export const documentSeries = {
  list:   ()         => client.get('/api/settings/document-series'),
  update: (id, data) => client.put(`/api/settings/document-series/${id}`, data),
}
