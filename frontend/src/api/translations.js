import client from './client'

export const translationsApi = {
  forLocale:  (locale)              => client.get(`/api/translations/${locale}`),
  list:       (params)              => client.get('/api/translations', { params }),
  upsert:     (namespace, key, data)=> client.put(`/api/translations/${namespace}/${key}`, data),
  setLocale:  (locale)              => client.put('/api/me/locale', { locale }),
}
