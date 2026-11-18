import { defineConfig, type Plugin } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { spawn, type ChildProcess } from 'node:child_process';
import fs from 'node:fs';
import net from 'node:net';
import path from 'node:path';

function checkPortOpen(host: string, port: number): Promise<boolean> {
  return new Promise((resolve) => {
    const socket = new net.Socket();
    socket.setTimeout(600);
    socket.once('connect', () => {
      socket.destroy();
      resolve(true);
    });
    socket.once('timeout', () => {
      socket.destroy();
      resolve(false);
    });
    socket.once('error', () => {
      socket.destroy();
      resolve(false);
    });
    socket.connect(port, host);
  });
}

function resolvePhpBinary(): string {
  const laragonPhp = 'C:\\laragon\\bin\\php\\php-8.3.36-Win32-vs16-x64\\php.exe';
  if (fs.existsSync(laragonPhp)) {
    return laragonPhp;
  }
  return 'php';
}

function autoStartLaravelBackend(): Plugin {
  let phpProcess: ChildProcess | null = null;

  return {
    name: 'auto-start-laravel-backend',
    configureServer(server) {
      server.httpServer?.once('listening', async () => {
        const isRunning = await checkPortOpen('127.0.0.1', 8000);
        if (isRunning) {
          console.log('  ➜  [SiBotol POS] Backend Laravel API aktif di http://127.0.0.1:8000');
          return;
        }

        const phpBin = resolvePhpBinary();
        const cwd = process.cwd();
        const phpIni = path.join(cwd, 'php.ini');
        const args = fs.existsSync(phpIni)
          ? ['-c', phpIni, 'artisan', 'serve', '--host=127.0.0.1', '--port=8000', '--no-reload']
          : ['artisan', 'serve', '--host=127.0.0.1', '--port=8000', '--no-reload'];

        phpProcess = spawn(phpBin, args, {
          cwd,
          env: {
            ...process.env,
            PHPRC: cwd,
          },
          stdio: 'ignore',
        });

        console.log('  ➜  [SiBotol POS] Otomatis menjalankan Backend API Laravel di http://127.0.0.1:8000');
      });

      const cleanup = () => {
        if (phpProcess && !phpProcess.killed) {
          phpProcess.kill();
          phpProcess = null;
        }
      };

      server.httpServer?.once('close', cleanup);
      process.once('SIGINT', cleanup);
      process.once('SIGTERM', cleanup);
    },
  };
}

export default defineConfig({
  publicDir: false,
  plugins: [react(), tailwindcss(), autoStartLaravelBackend()],
  build: {
    outDir: 'public/build',
    emptyOutDir: true,
    rollupOptions: {
      output: {
        entryFileNames: 'assets/app.js',
        chunkFileNames: 'assets/[name].js',
        assetFileNames: 'assets/app.[ext]',
      },
    },
  },
  server: {
    host: '127.0.0.1',
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
});
