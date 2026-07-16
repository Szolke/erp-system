import client from './client'

export const jobPositions = {
  list: (params) => client.get('/api/job-positions', { params }),
}
