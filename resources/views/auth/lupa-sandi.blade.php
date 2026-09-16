@extends('layouts.app')

@section('title', 'Lupa sandi')
@section('heading', 'Lupa sandi')
@section('subheading', 'Kami akan mengirim tautan untuk mengatur ulang sandi Anda.')

@section('content')
    <div class="card mx-auto max-w-md p-6">
        @if (session('status'))
            <div role="status" class="mb-4 rounded-control border border-accent-600/40 bg-accent-600/10 px-3 py-2 text-sm text-accent-300">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
            @csrf

            <div>
                <label for="email" class="label">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}"
                       class="field @error('email') border-danger-500 @enderror"
                       required autofocus autocomplete="username">
                @error('email')
                    <p class="error-text" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="btn btn-primary w-full">Kirim tautan</button>
        </form>

        <p class="mt-5 text-center text-sm text-fg-500">
            <a href="{{ route('login') }}" class="text-accent-300 hover:underline">Kembali ke halaman masuk</a>
        </p>
    </div>
@endsection
