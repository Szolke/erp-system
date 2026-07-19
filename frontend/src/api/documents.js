import client from './client'

export const documents = {
  list: (params) => client.get('/api/documents', { params }),
  export: (params) => client.get('/api/documents/export', { params, responseType: 'blob' }),
}
