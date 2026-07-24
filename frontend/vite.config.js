/// <reference types="vitest/config" />
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { fileURLToPath, URL } from 'node:url'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@wiki': fileURLToPath(new URL('./wiki', import.meta.url)),
    },
  },
  server: {
    fs: {
      allow: ['.', fileURLToPath(new URL('./wiki', import.meta.url))],
    },
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.js'],
  },
})
