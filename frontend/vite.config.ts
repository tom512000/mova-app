import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import path from 'node:path'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': path.resolve(import.meta.dirname, './src'),
    },
  },
  server: {
    watch: {
      // Docker Desktop on Windows doesn't propagate native filesystem events through
      // bind mounts, so chokidar's default watcher never sees edits made on the host.
      usePolling: true,
    },
  },
})
