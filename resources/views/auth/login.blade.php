@extends('layouts.app')

@section('title', 'Masuk')
@section('heading', 'Masuk')
@section('subheading', 'Gunakan akun Anda untuk melihat analitik.')

@section('content')
    <div class="card mx-auto max-w-md p-6">
        @if (session('status'))
            <div role="status" class="mb-4 rounded-control border border-accent-600/40 bg-accent-600/10 px-3 py-2 text-sm text-accent-300">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="space-y-4">
            @csrf

            <div>
                <label for="email" class="label">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}"
                       class="field @error('email') border-danger-500 @enderror"
                       required autofocus autocomplete="username"
                       @error('email') aria-invalid="true" aria-describedby="error-email" @enderror>
                @error('email')
                    <p id="error-email" class="error-text" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="label">Kata sandi</label>
                <input id="password" name="password" type="password"
                       class="field @error('password') border-danger-500 @enderror"
                       required autocomplete="current-password"
                       @error('password') aria-invalid="true" aria-describedby="error-password" @enderror>
                @error('password')
                    <p id="error-password" class="error-text" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex items-center justify-between">
                <label class="flex items-center gap-2 text-sm text-fg-300">
                    <input type="checkbox" name="remember"
                           class="h-4 w-4 rounded border-ink-700 bg-ink-900 text-accent-500">
                    Ingat saya
                </label>

                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="text-sm text-accent-300 hover:underline">
                        Lupa sandi?
                    </a>
                @endif
            </div>

            <button type="submit" class="btn btn-primary w-full">Masuk</button>
        </form>

        @if (Route::has('register'))
            <p class="mt-5 text-center text-sm text-fg-500">
                Belum punya akun?
                <a href="{{ route('register') }}" class="text-accent-300 hover:underline">Daftar</a>
            </p>
        @endif
    </div>
@endsection
