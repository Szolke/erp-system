import client from './client'

export const documents = {
  list: (params) => client.get('/api/documents', { params }),
}
