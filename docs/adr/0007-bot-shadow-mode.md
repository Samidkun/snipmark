# 7. Pertahanan bot: shadow mode sebelum enforcement

- **Status:** accepted
- **Tanggal:** 2026-09-16

## Konteks

Analitik yang mencampur klik bot dengan klik manusia tidak berguna — angkanya
besar tetapi tidak berarti apa-apa. Karena itu bot perlu diidentifikasi dan
dipisahkan.

Masalahnya: heuristik bot **selalu** punya false positive. Setiap metode
(CIDR datacenter, pola User-Agent, rate limit) akan salah menandai sebagian
pengunjung nyata. Menyalakan penindakan sejak awal berarti memblokir orang
nyata **sebelum tahu seberapa sering itu terjadi**.

## Keputusan

**Shadow mode lebih dulu.** Bot dideteksi, dicatat, dan **tidak diblokir**.
Angka "klik manusia" dan "total klik" ditampilkan terpisah, sehingga efeknya
langsung terlihat dan bisa dinilai.

**Kriteria promosi ke enforcement** (dicatat di runbook): setelah **≥ 1.000
event** terkumpul, tinjau rasio false positive pada sampel nyata. Enforcement
baru dipertimbangkan setelah itu — dan keputusannya berbasis data, bukan
perasaan.

Nonce/limit tetap ada, tetapi bertindak sebagai **pencatat**, bukan penghalang.

## Alternatif yang ditolak

**Blokir langsung sejak v1.** Terlihat tegas, tetapi tidak ada cara mengukur
kerusakannya. Setiap pengunjung yang salah diblokir adalah kehilangan yang
senyap: tidak ada error, tidak ada keluhan yang bisa ditindaklanjuti — hanya
angka yang lebih rendah dari seharusnya.

**Blokir berdasarkan ASN.** Awalnya dipertimbangkan (user meminta pertahanan
diperkuat). Diganti dengan heuristik CIDR datacenter karena data ASN
membutuhkan basis data pihak ketiga (MaxMind) dengan lisensi dan proses
pembaruan tersendiri. CIDR menangkap kasus yang sama (VPS/cloud) dengan
daftar yang bisa dirawat sendiri dan diaudit.

**Blokir berdasarkan rate limit saja.** Menangkap scraping agresif, tetapi
kehilangan bot lambat — dan justru bot lambat yang paling merusak angka,
karena ia terlihat seperti manusia.

## Konsekuensi

- Satu sumber kebenaran untuk "kenapa ini dianggap bot": `BotDetector` +
  `BotVerdict`, dengan alasan yang disimpan, bukan hanya boolean.
- `user_agent` mentah disimpan (512 karakter) sehingga klasifikasi bisa
  ditinjau ulang setelah parser diperbaiki.
- Bot **dikeluarkan dari** "klik manusia", tetapi tetap ada di "total klik".
  Dua angka ini sengaja berasal dari sumber berbeda — selisihnya adalah
  informasi, bukan inkonsistensi.
- Selama shadow mode, bot tetap membebani database. Diterima: volume v1 kecil,
  dan menukar sedikit beban dengan kemampuan mengukur jauh lebih baik daripada
  sebaliknya.
