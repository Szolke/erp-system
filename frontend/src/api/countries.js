import client from './client'

export const countries = {
  adminList:   ()      => client.get('/api/admin/countries'),
  adminUpdate: (codes) => client.put('/api/admin/countries', { codes }),
}
