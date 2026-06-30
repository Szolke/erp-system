import client, { initCsrf } from './client'

export async function login(email, password) {
  await initCsrf()
  return client.post('/api/login', { email, password })
}

export async function logout() {
  return client.post('/api/logout')
}

export async function me() {
  return client.get('/api/me')
}

export async function switchCompany(companyId) {
  return client.put('/api/active-company', { company_id: companyId })
}
