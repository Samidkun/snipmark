import { defineConfig, devices } from '@playwright/test';

/**
 * Konfigurasi E2E untuk aplikasi LARAVEL — bukan SPA Vite.
 *
 * Scaffold awal menunjuk `npm run dev` di port 3000. Itu keliru untuk proyek ini:
 * `npm run dev` hanya menjalankan server aset Vite; aplikasinya sendiri dilayani
 * PHP. Menjalankan E2E terhadap server Vite berarti menguji halaman kosong dan
 * memberi hasil "hijau" yang tidak membuktikan apa pun.
 *
 * Port 8899 dipakai agar tidak bentrok dengan `php artisan serve` default (8000)
 * maupun dev server Vite (5173/3000).
 */
const PORT = Number(process.env.E2E_PORT ?? 8899);

/**
 * `||` — bukan `??` — dipakai dengan sengaja: variabel environment yang di-set
 * tapi KOSONG (`E2E_BASE_URL=`) harus diperlakukan sebagai "tidak di-set".
 * `??` hanya menggantikan null/undefined, sehingga string kosong akan lolos dan
 * menghasilkan `baseURL: ''` — dan Playwright gagal dengan
 * "Cannot navigate to invalid URL" pada SETIAP test sekaligus.
 */
const BASE_URL = process.env.E2E_BASE_URL || `http://127.0.0.1:${PORT}`;
const PAKAI_SERVER_EKSTERNAL = Boolean(process.env.E2E_BASE_URL);

export default defineConfig({
  testDir: './e2e',

  /**
   * Login SEKALI untuk seluruh suite, sebelum worker mana pun berjalan.
   * Login di dalam fixture akan balapan antar-worker dan kena throttle Fortify
   * (5 percobaan/menit per IP) — lihat e2e/global-setup.ts.
   */
  globalSetup: './e2e/global-setup.ts',

  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: process.env.CI ? [['list'], ['json', { outputFile: 'e2e-results.json' }]] : 'list',

  use: {
    baseURL: BASE_URL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
  },

  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],

  /**
   * Server dijalankan otomatis kecuali E2E_BASE_URL diberikan (mode rehearsal:
   * menguji server yang sudah berjalan, mis. build produksi).
   *
   * `--no-reload` penting: tanpa itu, Vite dev server menyuntikkan klien HMR
   * yang menambah <script> tanpa nonce dan membuat pengujian CSP memberi
   * kesimpulan palsu.
   */
  webServer: PAKAI_SERVER_EKSTERNAL
    ? undefined
    : {
        command: `php artisan serve --port=${PORT}`,
        url: BASE_URL,
        reuseExistingServer: !process.env.CI,
        timeout: 30_000,
      },
});
