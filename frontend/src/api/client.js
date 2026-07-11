import axios from 'axios'

export const apiBase = import.meta.env.VITE_API_URL ?? 'http://localhost'

const client = axios.create({
  baseURL: apiBase,
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

// Lejárt session (401) → automatikus kidobás a login oldalra.
// A /api/login és /sanctum/* kérések ki vannak zárva (végtelen hurok elkerülése).
client.interceptors.response.use(
  (response) => response,
  (error) => {
    if (
      error.response?.status === 401 &&
      !window.location.pathname.startsWith('/login') &&
      !error.config?.url?.includes('/login') &&
      !error.config?.url?.includes('/sanctum/')
    ) {
      window.location.href = '/login'
    }
    return Promise.reject(error)
  }
)

// Bootstrap Sanctum SPA auth: must be called once before the first login attempt.
export async function initCsrf() {
  await client.get('/sanctum/csrf-cookie')
}

export default client
