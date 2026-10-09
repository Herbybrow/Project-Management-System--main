// Vite embeds this value at build time. Keep the old name working for
// existing development environments while matching the deployment guide.
export const apiBaseUrl = (
  import.meta.env.VITE_API_BASE_URL
  || import.meta.env.VITE_API_URL
  || (import.meta.env.DEV ? 'http://localhost:8000/api' : '')
).replace(/\/$/, '')

if (!apiBaseUrl) {
  throw new Error('Set VITE_API_BASE_URL before building the production frontend.')
}
