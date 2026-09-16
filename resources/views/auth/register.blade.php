@extends('layouts.app')

@section('title', 'Daftar')
@section('heading', 'Daftar')
@section('subheading', 'Buat akun untuk mulai memendekkan tautan.')

@section('content')
    <div class="card mx-auto max-w-md p-6">
        <form method="POST" action="{{ route('register') }}" class="space-y-4">
            @csrf

            <div>
                <label for="name" class="label">Nama</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}"
                       class="field @error('name') border-danger-500 @enderror"
                       required autofocus autocomplete="name">
                @error('name')
                    <p class="error-text" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="email" class="label">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}"
                       class="field @error('email') border-danger-500 @enderror"
                       required autocomplete="username">
                @error('email')
                    <p class="error-text" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="label">Kata sandi</label>
                <input id="password" name="password" type="password"
                       class="field @error('password') border-danger-500 @enderror"
                       required autocomplete="new-password">
                @error('password')
                    <p class="error-text" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password_confirmation" class="label">Ulangi kata sandi</label>
                <input id="password_confirmation" name="password_confirmation" type="password"
                       class="field" required autocomplete="new-password">
            </div>

            <button type="submit" class="btn btn-primary w-full">Daftar</button>
        </form>

        <p class="mt-5 text-center text-sm text-fg-500">
            Sudah punya akun?
            <a href="{{ route('login') }}" class="text-accent-300 hover:underline">Masuk</a>
        </p>
    </div>
@endsection
