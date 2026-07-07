import client from './client'

export const apiTester = {
  getSpec: () => client.get('/api/api-tester/openapi'),
  send: (method, url, { params, data } = {}) => client.request({ method, url, params, data }),
}
