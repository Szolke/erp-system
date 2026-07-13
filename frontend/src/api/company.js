import client from './client'

export const companies = {
  list:   (params) => client.get('/api/companies', { params }),
  create: (data)   => client.post('/api/companies', data),
}

export const company = {
  get: () => client.get('/api/company'),
  update: (data) => client.put('/api/company', data),
  auditLogs: (params) => client.get('/api/audit-logs', { params }),
  uploadLogo: (file) => {
    const fd = new FormData()
    fd.append('logo', file)
    return client.post('/api/company/logo', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
  },
  deleteLogo: () => client.delete('/api/company/logo'),
  settings: {
    getAll: () => client.get('/api/company/settings'),
    set: (key, value) => client.put(`/api/company/settings/${key}`, { value }),
    reset: (key) => client.delete(`/api/company/settings/${key}`),
  },
  simplePay: {
    list: () => client.get('/api/company/simplepay'),
    upsert: (currency, data) => client.put(`/api/company/simplepay/${currency}`, data),
    delete: (currency) => client.delete(`/api/company/simplepay/${currency}`),
  },
  nav: {
    list: () => client.get('/api/company/nav'),
    upsert: (env, data) => client.put(`/api/company/nav/${env}`, data),
    delete: (env) => client.delete(`/api/company/nav/${env}`),
    setActiveEnvironment: (env) => client.patch('/api/company/nav/active-environment', { environment: env }),
  },
}
