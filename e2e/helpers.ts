import { test as base, expect, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

/**
 * Fixture autentikasi E2E.
 *
 * Sesi login dibuat SEKALI oleh `e2e/global-setup.ts` (sebelum worker apa pun
 * berjalan), lalu setiap test memuatnya.
 *
 * Login TIDAK boleh dilakukan di dalam fixture ini. Terukur: dengan test
 * paralel, beberapa worker memeriksa "file sesi belum ada" secara bersamaan,
 * semuanya login, dan Fortify menolak percobaan ke-6 ke atas (batas 5/menit per
 * IP). Kegagalannya menyesatkan — tampak seperti login rusak, padahal aplikasi
 * benar dan yang menahan adalah throttle.
 *
 * Kredensial sengaja TIDAK didefinisikan di sini. Satu tempat saja: globalSetup.
 * Menduplikasinya membuat dua sumber kebenaran yang bisa berbeda diam-diam.
 */
const FILE_SESI = path.join(process.cwd(), 'test-results', '.auth', 'pengguna.json');

export const test = base.extend<{ masuk: Page }>({
  masuk: async ({ browser }, use) => {
    if (!fs.existsSync(FILE_SESI)) {
      throw new Error(
        `File sesi tidak ada: ${FILE_SESI}. Pastikan globalSetup berjalan ` +
          '(lihat e2e/global-setup.ts dan opsi globalSetup di playwright.config.ts).',
      );
    }

    const konteks = await browser.newContext({ storageState: FILE_SESI });
    const page = await konteks.newPage();

    await use(page);

    await konteks.close();
  },
});

export { expect };
