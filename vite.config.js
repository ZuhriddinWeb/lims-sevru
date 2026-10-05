import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig({
  plugins: [
    laravel({
      input: ['resources/css/app.css', 'resources/js/app.js'],
      refresh: true,
    }),
    vue({
      template: {
        transformAssetUrls: {
          base: null,
          includeAbsolute: false,
        },
      },
    }),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
    },
  },
  server: {
  host: '0.0.0.0',          // tarmoqdagi barcha interfeyslarda tinglaydi
  port: 5173,
  cors: true,               // CORS sarlavhalarini qo'shadi
  hmr: {
    host: '192.168.14.82',  // HMR uchun sizning IP
  },
},
})
