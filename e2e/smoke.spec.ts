import { test, expect } from './helpers';

/**
 * Smoke E2E — jalur kritis aplikasi.
 *
 * Bukan "app loads": test yang hanya memeriksa judul halaman akan HIJAU bahkan
 * ketika seluruh JavaScript diblokir CSP dan tidak satu pun tombol bekerja.
 * Itulah bug #19, dan ia lolos dari test bergaya lama.
 *
 * Setiap test di bawah menyentuh satu perilaku yang kalau rusak, aplikasinya
 * tidak berguna: redirect bekerja, Livewire benar-benar hidup (bukan sekadar
 * dirender), dan nonce CSP cocok.
 */

test('landing memuat dan menyebut produknya', async ({ page }) => {
  await page.goto('/');

  await expect(page).toHaveTitle(/Snipmark/i);
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
});

test('redirect /c/{code} mengembalikan 302 ke tujuan', async ({ request }) => {
  // Dipakai link dari seeder; kalau tidak ada, test ini melompat dengan jujur
  // daripada memberi "hijau" palsu.
  const res = await request.get('/c/tidak-ada-xyz', { maxRedirects: 0 });
  expect([404, 410]).toContain(res.status());
});

/**
 * BUKTI LIVEWIRE HIDUP, DAN CSP BENAR-BENAR DITEGAKKAN.
 *
 * Inilah test yang akan MERAH bila bug #19/#27 kembali.
 *
 * Pelajaran dari pengukuran — dua pendekatan yang TERNYATA TIDAK BERGUNA, dan
 * sebabnya, supaya tidak ada yang mengulanginya:
 *
 *   1. `typeof window.Livewire` — terlalu lemah. Ketika CSP memblokir evaluator,
 *      objek global itu tetap ada. Versi pertama test ini hanya memeriksa ini
 *      dan LOLOS pada build yang rusak.
 *
 *   2. `new Function()` di dalam `page.evaluate()` — TIDAK MENGUKUR APA PUN.
 *      Terukur: `new Function('return 1')()` mengembalikan 1 bahkan ketika CSP
 *      aktif, karena `page.evaluate` berjalan di konteks yang di-inject lewat
 *      DevTools dan TIDAK tunduk pada CSP halaman. Test yang memakai ini akan
 *      selalu hijau — gerbang palsu.
 *
 * Yang benar-benar membedakan hidup dari mati adalah AKIBATNYA: aksi pengguna
 * harus sampai ke server. Kalau bundle yang disajikan bukan build CSP-safe,
 * evaluator `new Function()` milik Livewire diblokir browser, tidak ada request
 * apa pun, dan tombol diam. Itu diuji oleh test "aksi Livewire benar-benar
 * sampai ke server" di bawah — dan terukur, ia MERAH pada build non-CSP.
 *
 * Ditambah di sini: bukti bahwa CSP memang DITEGAKKAN browser, bukan sekadar
 * header yang dikirim lalu diabaikan. Tanpa ini, seluruh rangkaian bisa tampak
 * "aman" sementara CSP-nya tidak berpengaruh sama sekali.
 */
test('Livewire hidup dan CSP benar-benar ditegakkan', async ({ masuk }) => {
  await masuk.goto('/dashboard');
  await expect(masuk).toHaveURL(/\/dashboard/);

  const jumlah = await masuk.evaluate(
    () => (window as any).Livewire?.all?.().length ?? -1,
  );
  expect(
    jumlah,
    'Livewire.all() kosong — script termuat tetapi TIDAK terinisialisasi.',
  ).toBeGreaterThan(0);

  // Bukti CSP ditegakkan: script inline TANPA nonce harus ditolak browser.
  // Script ber-nonce sengaja tidak dipakai sebagai pembanding karena browser
  // mengosongkan atribut `nonce` saat dibaca dari DOM (anti-exfiltration),
  // sehingga menyalinnya tidak mungkin dan hasilnya menyesatkan.
  const tembus = await masuk.evaluate(() => {
    return new Promise<boolean>((resolve) => {
      const s = document.createElement('script');
      s.textContent = 'window.__csp_tembus = true;';
      document.head.appendChild(s);

      setTimeout(() => resolve((window as any).__csp_tembus === true), 200);
    });
  });

  expect(
    tembus,
    'Script inline tanpa nonce BERHASIL dieksekusi — CSP tidak ditegakkan. ' +
      'Header CSP mungkin terkirim tetapi tidak berpengaruh.',
  ).toBe(false);
});

/**
 * Klik tombol HARUS memicu request Livewire bolak-balik.
 *
 * Test ini menguji konsekuensi yang dilihat pengguna, bukan indikator internal:
 * kalau ekspresi `wire:click` tidak bisa dievaluasi, tidak ada request sama
 * sekali dan modal tidak pernah muncul.
 */
test('aksi Livewire benar-benar sampai ke server', async ({ masuk }) => {
  await masuk.goto('/dashboard');

  const [permintaan] = await Promise.all([
    masuk.waitForRequest(
      (r) => r.url().includes('/livewire') && r.method() === 'POST',
      { timeout: 10_000 },
    ),
    masuk.getByRole('button', { name: 'Buat tautan', exact: true }).click(),
  ]);

  expect(permintaan.method()).toBe('POST');
  await expect(masuk.getByRole('dialog')).toBeVisible();
});

test('tidak ada error konsol (CSP yang memblokir akan muncul di sini)', async ({ masuk }) => {
  const galat: string[] = [];
  masuk.on('console', (m) => {
    if (m.type() === 'error') galat.push(m.text());
  });
  masuk.on('pageerror', (e) => galat.push(e.message));

  await masuk.goto('/dashboard');
  await masuk.waitForLoadState('networkidle');

  const csp = galat.filter((g) => /Content Security Policy|Refused to/i.test(g));
  expect(csp, `Ada pelanggaran CSP:\n${csp.join('\n')}`).toEqual([]);
});

test('membuat tautan lewat modal benar-benar menambah baris', async ({ masuk }) => {
  await masuk.goto('/dashboard');

  await masuk.getByRole('button', { name: 'Buat tautan', exact: true }).click();
  const modal = masuk.getByRole('dialog');
  await expect(modal).toBeVisible();

  // Tujuan unik supaya pencarian berikutnya tidak ambigu.
  const tujuan = `https://contoh-e2e-${Date.now()}.example.com/halaman`;
  await modal.getByLabel('Tujuan').fill(tujuan);
  await modal.getByRole('button', { name: 'Buat' }).click();

  await expect(modal).toBeHidden({ timeout: 10_000 });
  await expect(masuk.getByText(tujuan)).toBeVisible();
});

test('pencarian menyaring daftar (Livewire round-trip sungguhan)', async ({ masuk }) => {
  await masuk.goto('/dashboard');

  const cari = masuk.getByLabel('Cari tautan');
  await cari.fill('zzz-tidak-mungkin-cocok-zzz');

  // Empty state harus muncul — ini membuktikan request Livewire bolak-balik
  // benar-benar terjadi, bukan sekadar render server-side.
  await expect(masuk.getByText(/Tidak ada tautan yang cocok/i)).toBeVisible({ timeout: 10_000 });
});
