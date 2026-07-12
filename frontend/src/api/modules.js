import client from './client'

export const modules = {
  list:   ()           => client.get('/api/modules'),
  update: (key, data)  => client.patch(`/api/modules/${key}`, data),
}
