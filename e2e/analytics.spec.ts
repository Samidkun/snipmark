import { test, expect } from './helpers';

/**
 * E2E halaman ANALYTICS (`/dashboard/{code}`).
 *
 * Kenapa file terpisah dari smoke.spec.ts: halaman ini adalah tempat tiga hal
 * bertemu sekaligus — Livewire, SVG yang dirender server, dan toggle yang
 * memicu round-trip. Bug #26 (layout membuang slot) dan #27 (CSP memblokir
 * evaluator) muncul di halaman DASHBOARD, dan halaman analytics memakai layout
 * serta mekanisme yang sama. Jadi halaman ini kemungkinan besar rusak dengan
 * cara yang sama, tetapi tidak pernah diuji sampai sekarang.
 *
 * Test ini membuat tautannya sendiri supaya tidak bergantung pada data yang
 * kebetulan ada di database — test yang bergantung pada keadaan awal akan
 * gagal secara acak dan mengajari orang untuk mengabaikannya.
 */

/** Buat satu tautan lewat UI dan kembalikan tujuan uniknya. */
async function buatTautan(masuk: import('@playwright/test').Page): Promise<string> {
  await masuk.goto('/dashboard');
  await masuk.getByRole('button', { name: 'Buat tautan', exact: true }).click();

  const modal = masuk.getByRole('dialog');
  await expect(modal).toBeVisible();

  const tujuan = `https://contoh-analitik-${Date.now()}.example.com/halaman`;
  await modal.getByLabel('Tujuan').fill(tujuan);
  await modal.getByRole('button', { name: 'Buat', exact: true }).click();

  await expect(modal).toBeHidden({ timeout: 10_000 });
  await expect(masuk.getByText(tujuan)).toBeVisible();

  return tujuan;
}

test('halaman analytics terbuka dan menampilkan judul tautan', async ({ masuk }) => {
  await buatTautan(masuk);

  // Klik kode tautan pertama di tabel untuk membuka analytics.
  await masuk.getByRole('link', { name: /^\/c\// }).first().click();
  await masuk.waitForURL(/\/dashboard\/[A-Za-z0-9]+$/);

  // h1 harus ADA di DOM. Dipasang sr-only (tidak terlihat mata) karena kode
  // tautannya sendiri sudah ditampilkan sebagai elemen visual di header —
  // mengulanginya sebagai teks besar akan jadi duplikasi. Tetap wajib ada
  // supaya struktur dokumen punya tingkatan teratas dan pembaca layar punya
  // penanda halaman. Karena itu `toBeAttached`, bukan `toBeVisible`.
  await expect(masuk.getByRole('heading', { level: 1 })).toBeAttached();

  await expect(masuk.getByRole('link', { name: /kembali/i })).toBeVisible();
});

test('analytics menampilkan empat breakdown dan grafik', async ({ masuk }) => {
  await buatTautan(masuk);
  await masuk.getByRole("link", { name: /^\/c\// }).first().click();
  await masuk.waitForURL(/\/dashboard\/[A-Za-z0-9]+$/);

  // Empat breakdown dari spec §8. Kalau salah satu hilang, itu regresi diam.
  for (const judul of [/perangkat/i, /peramban/i, /sistem operasi/i, /sumber rujukan/i]) {
    await expect(
      masuk.getByRole('heading', { name: judul }).first(),
      `Breakdown "${judul}" tidak ditemukan di halaman analytics`,
    ).toBeVisible();
  }

  // Grafik dirender sebagai SVG di sisi server — tidak butuh JS untuk tampil.
  // Itu keputusan desain (ADR-0008), dan test ini menjaganya.
  await expect(masuk.locator('svg').first()).toBeVisible();
});

test('toggle "sertakan bot" memicu round-trip Livewire', async ({ masuk }) => {
  await buatTautan(masuk);
  await masuk.getByRole("link", { name: /^\/c\// }).first().click();
  await masuk.waitForURL(/\/dashboard\/[A-Za-z0-9]+$/);

  const toggle = masuk.getByLabel(/sertakan bot/i);
  await expect(toggle).toBeVisible();

  // Perubahan pada toggle harus SAMPAI KE SERVER. Ini yang membedakan Livewire
  // yang hidup dari Livewire yang hanya ter-render (bug #27).
  const [req] = await Promise.all([
    masuk.waitForRequest(
      (r) => r.url().includes('/livewire') && r.method() === 'POST',
      { timeout: 10_000 },
    ),
    toggle.check(),
  ]);

  expect(req.method()).toBe('POST');
});

test('analytics tidak menghasilkan pelanggaran CSP', async ({ masuk }) => {
  await buatTautan(masuk);

  const galat: string[] = [];
  masuk.on('console', (m) => {
    if (m.type() === 'error') galat.push(m.text());
  });

  await masuk.getByRole("link", { name: /^\/c\// }).first().click();
  await masuk.waitForURL(/\/dashboard\/[A-Za-z0-9]+$/);
  await masuk.waitForLoadState('networkidle');

  const csp = galat.filter((g) => /Content Security Policy|Refused to/i.test(g));
  expect(csp, `Pelanggaran CSP di halaman analytics:\n${csp.join('\n')}`).toEqual([]);
});
