import client from './client'

export const listPreferences = {
  update: (listKey, data) => client.put(`/api/list-preferences/${listKey}`, data),
  remove: (listKey) => client.delete(`/api/list-preferences/${listKey}`),
}
