import client from './client'

export const reports = {
  invoices: (params) => client.get('/api/reports/invoices', { params }),
  products: (params) => client.get('/api/reports/products', { params }),
  receivablesAging: (params) => client.get('/api/reports/receivables-aging', { params }),
  vatSummary: (params) => client.get('/api/reports/vat-summary', { params }),
  export: (report, params) =>
    client.get(`/api/reports/${report}/export`, { params: { ...params, format: 'csv' }, responseType: 'blob' }),
}
