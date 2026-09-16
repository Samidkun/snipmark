import { chromium, type FullConfig } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

/**
 * globalSetup — SATU kali login untuk SELURUH suite, sebelum worker apa pun
 * dijalankan.
 *
 * Kenapa tidak di fixture: fixture berjalan di DALAM setiap worker, dan dengan
 * 7 test paralel ada 7 worker yang memeriksa "apakah file sesi ada?" secara
 * bersamaan. Semuanya melihat "belum ada", lalu semuanya login — dan Fortify
 * membatasi 5 percobaan login per menit per IP. Percobaan ke-6 menerima 429.
 *
 * Sudah diukur, bukan diasumsikan:
 *   POST /login berturut-turut -> 1..5 = 302, 6..8 = 429
 *
 * globalSetup berjalan sekali, berurutan, sebelum test apa pun — jadi tidak ada
 * balapan dan hanya satu percobaan login.
 *
 * Kredensial di sini adalah AKUN DEMO di database pengembangan lokal (dibuat
 * seeder), bukan kredensial produksi. CI menyuplai lewat E2E_EMAIL/E2E_PASSWORD.
 */
export const FILE_SESI = path.join(process.cwd(), 'test-results', '.auth', 'pengguna.json');

export default async function globalSetup(config: FullConfig): Promise<void> {
  const baseURL = config.projects[0]?.use?.baseURL ?? 'http://127.0.0.1:8899';

  const email = process.env.E2E_EMAIL || 'demo@snipmark.test';
  const sandi = process.env.E2E_PASSWORD || 'password';

  fs.mkdirSync(path.dirname(FILE_SESI), { recursive: true });

  const browser = await chromium.launch();
  const page = await browser.newPage({ baseURL });

  await page.goto('/login');
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Kata sandi').fill(sandi);

  await Promise.all([
    page.waitForURL(/\/dashboard/, { timeout: 20_000 }),
    page.getByRole('button', { name: /masuk/i }).click(),
  ]);

  await page.context().storageState({ path: FILE_SESI });
  await browser.close();

  // Gagal keras bila sesi tidak terbentuk. Tanpa pemeriksaan ini, suite akan
  // melanjutkan dengan sesi kosong dan melaporkan puluhan kegagalan yang
  // menyesatkan (seolah-olah UI rusak), bukan satu penyebab yang jelas.
  if (!fs.existsSync(FILE_SESI)) {
    throw new Error(`Login berhasil tetapi file sesi tidak dibuat: ${FILE_SESI}`);
  }
}
