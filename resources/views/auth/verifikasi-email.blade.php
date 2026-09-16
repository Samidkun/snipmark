@extends('layouts.app')

@section('title', 'Verifikasi email')
@section('heading', 'Verifikasi email')

@section('content')
    <div class="card mx-auto max-w-md p-6">
        <p class="text-sm text-fg-500">
            Kami mengirim tautan verifikasi ke alamat email Anda. Klik tautan itu untuk
            mengaktifkan akun, lalu muat ulang halaman ini.
        </p>

        @if (session('status') === 'verification-link-sent')
            <div role="status" class="mt-4 rounded-control border border-accent-600/40 bg-accent-600/10 px-3 py-2 text-sm text-accent-300">
                Tautan verifikasi baru sudah dikirim.
            </div>
        @endif

        <div class="mt-5 flex flex-wrap gap-2">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <button type="submit" class="btn btn-primary">Kirim ulang tautan</button>
            </form>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn btn-ghost">Keluar</button>
            </form>
        </div>
    </div>
@endsection
