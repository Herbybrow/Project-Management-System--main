import { defineConfig, loadEnv } from 'vite'
import { fileURLToPath } from 'node:url'
import react from '@vitejs/plugin-react'

// https://vite.dev/config/
export default defineConfig(({ command, mode }) => {
  const env = loadEnv(mode, fileURLToPath(new URL('.', import.meta.url)), 'VITE_')
  if (command === 'build' && !env.VITE_API_BASE_URL && !env.VITE_API_URL) {
    throw new Error('Set VITE_API_BASE_URL before building the production frontend.')
  }

  return {
    plugins: [react()],
    server: {
      host: '127.0.0.1',
      port: 5173,
      strictPort: true,
    },
  }
})
