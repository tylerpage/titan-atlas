import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const hmrHost = env.VITE_HMR_HOST || null;
    const devServerUrl = env.VITE_DEV_SERVER_URL || null;

    return {
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
        tailwindcss(),
    ],
    server: {
        // Binding 0.0.0.0 writes an unusable URL to public/hot and causes a blank page.
        host: hmrHost ? '0.0.0.0' : '127.0.0.1',
        ...(devServerUrl ? { origin: devServerUrl } : {}),
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
        ...(hmrHost
            ? {
                  hmr: {
                      host: hmrHost,
                      clientPort: 443,
                      protocol: 'wss',
                  },
              }
            : {}),
    },
    };
});
