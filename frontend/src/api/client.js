import axios from 'axios'

const client = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? 'http://localhost',
  withCredentials: true,
  headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
})

// Reads the XSRF-TOKEN cookie Sanctum sets and attaches it to every mutating request.
client.interceptors.request.use((config) => {
  if (!['get', 'head', 'options'].includes(config.method)) {
    const xsrf = document.cookie
      .split('; ')
      .find((c) => c.startsWith('XSRF-TOKEN='))
      ?.split('=')[1]
    if (xsrf) {
      config.headers['X-XSRF-TOKEN'] = decodeURIComponent(xsrf)
    }
  }
  return config
})

// Bootstrap Sanctum SPA auth: must be called once before the first login attempt.
export async function initCsrf() {
  await client.get('/sanctum/csrf-cookie')
}

export default client
