import client from './client'

export const navSubmissions = {
  forInvoice: (invoiceId) => client.get(`/api/invoices/${invoiceId}/nav-submissions`),
  list: (params) => client.get('/api/nav-submissions', { params }),
  get: (id) => client.get(`/api/nav-submissions/${id}`),
}
