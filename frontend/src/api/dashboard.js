import client from './client'

export const dashboard = {
  get: () => client.get('/api/dashboard'),
}
