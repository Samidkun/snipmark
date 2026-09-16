@extends('layouts.app')

@section('title', 'Atur ulang sandi')
@section('heading', 'Atur ulang sandi')

@section('content')
    <div class="card mx-auto max-w-md p-6">
        <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
            @csrf
            <input type="hidden" name="token" value="{{ $request->route('token') }}">

            <div>
                <label for="email" class="label">Email</label>
                <input id="email" name="email" type="email"
                       value="{{ old('email', $request->email) }}"
                       class="field @error('email') border-danger-500 @enderror"
                       required autocomplete="username">
                @error('email')
                    <p class="error-text" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="label">Kata sandi baru</label>
                <input id="password" name="password" type="password"
                       class="field @error('password') border-danger-500 @enderror"
                       required autocomplete="new-password">
                @error('password')
                    <p class="error-text" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password_confirmation" class="label">Ulangi kata sandi baru</label>
                <input id="password_confirmation" name="password_confirmation" type="password"
                       class="field" required autocomplete="new-password">
            </div>

            <button type="submit" class="btn btn-primary w-full">Simpan sandi baru</button>
        </form>
    </div>
@endsection
