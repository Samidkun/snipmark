@extends('layouts.app')

@section('title', 'Pemendek tautan dengan analitik yang jujur')
@section('description', 'Snipmark memendekkan tautan dan menunjukkan angka yang bisa dipercaya: bot disaring, identitas pengunjung di-hash, dan setiap angka bisa dihitung ulang.')
@section('robots', 'index, follow')
@section('heading', 'Analitik yang tidak berbohong')
@section('subheading', 'Setiap angka di halaman ini bisa dihitung ulang dari data mentah.')

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="card p-5">
            <h2 class="text-sm font-medium">Bot disaring dari angka manusia</h2>
            <p class="mt-2 text-sm text-fg-500">
                Scanner, crawler, dan monitor dicatat terpisah sehingga “pengunjung”
                berarti manusia. Toggle-nya bisa dimatikan untuk melihat trafik total.
            </p>
        </div>

        <div class="card p-5">
            <h2 class="text-sm font-medium">Tanpa alamat IP tersimpan</h2>
            <p class="mt-2 text-sm text-fg-500">
                Identitas pengunjung disimpan sebagai HMAC yang berotasi setiap hari.
                Tidak ada kolom IP di basis data, jadi tidak ada yang bisa bocor.
            </p>
        </div>

        <div class="card p-5">
            <h2 class="text-sm font-medium">Angka yang bisa diaudit</h2>
            <p class="mt-2 text-sm text-fg-500">
                Agregat harian dihitung ulang dari peristiwa mentah dan dapat dijalankan
                berkali-kali dengan hasil sama. Ada perintah untuk menemukan dan
                memperbaiki selisih.
            </p>
        </div>
    </div>

    <div class="card mt-6 p-6">
        <h2 class="text-base font-semibold">Mulai</h2>
        <p class="mt-2 text-sm text-fg-500">
            Masuk untuk melihat dasbor, membuat tautan, dan membaca analitiknya.
        </p>

        <div class="mt-4 flex flex-wrap gap-2">
            @auth
                <a href="{{ route('dashboard') }}" class="btn btn-primary">Buka dasbor</a>
            @else
                <a href="{{ route('login') }}" class="btn btn-primary">Masuk</a>
                <a href="{{ route('register') }}" class="btn btn-ghost">Daftar</a>
            @endauth
        </div>

        @guest
            <p class="hint mt-4">
                Proyek portofolio — akun demo tersedia bila Anda memintanya lewat
                repositori.
            </p>
        @endguest
    </div>

    <div class="mt-6 grid gap-3 sm:grid-cols-2">
        <div class="card p-5">
            <h3 class="text-sm font-medium">Apa yang diukur</h3>
            <ul class="mt-2 space-y-1 text-sm text-fg-500">
                <li>Klik per hari, dipisah manusia dan bot</li>
                <li>Perangkat, peramban, sistem operasi</li>
                <li>Sumber rujukan (host saja, tanpa query string)</li>
                <li>Pengunjung unik per hari</li>
            </ul>
        </div>

        <div class="card p-5">
            <h3 class="text-sm font-medium">Apa yang tidak dilakukan</h3>
            <ul class="mt-2 space-y-1 text-sm text-fg-500">
                <li>Tidak menyimpan alamat IP</li>
                <li>Tidak melacak pengunjung lintas hari</li>
                <li>Tidak mengklaim mendeteksi bot dengan sempurna</li>
                <li>Tidak memuat skrip pihak ketiga</li>
            </ul>
        </div>
    </div>
@endsection
