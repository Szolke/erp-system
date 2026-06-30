import client from './client'

export const products = {
  list: (params) => client.get('/api/products', { params }),
  get: (id) => client.get(`/api/products/${id}`),
  create: (data) => client.post('/api/products', data),
  update: (id, data) => client.put(`/api/products/${id}`, data),
  destroy: (id) => client.delete(`/api/products/${id}`),
}

export const vatRates = {
  list: () => client.get('/api/vat-rates'),
}

export const paymentMethods = {
  list: () => client.get('/api/payment-methods'),
}
