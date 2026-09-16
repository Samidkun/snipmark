<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'Snipmark') · Snipmark</title>

    {{-- Identitas & SEO (spec §9). og:image dibuat statis supaya tidak butuh
         rendering dinamis di v1. --}}
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="canonical" href="{{ url()->current() }}">
    <meta name="description" content="@yield('description', 'Snipmark — pemendek tautan dengan analitik yang jujur.')">
    <meta name="robots" content="@yield('robots', 'noindex, nofollow')">
    <meta property="og:type" content="website">
    <meta property="og:title" content="@yield('title', 'Snipmark')">
    <meta property="og:description" content="@yield('description', 'Pemendek tautan dengan analitik yang jujur.')">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="{{ url('/og-image.png') }}">
    <meta name="twitter:card" content="summary_large_image">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full font-sans antialiased">

{{-- Lewati ke konten: syarat a11y pertama (spec §9). --}}
<a href="#konten"
   class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded focus:bg-accent-500 focus:px-4 focus:py-2 focus:text-ink-950">
    Lewati ke konten
</a>

<div class="min-h-screen lg:flex">

    {{-- ============================ SIDEBAR ============================ --}}
    <aside class="border-b border-ink-700 bg-ink-900 lg:w-60 lg:shrink-0 lg:border-b-0 lg:border-r">
        <div class="flex items-center gap-2 px-5 py-4 lg:py-6">
            <svg viewBox="0 0 24 24" class="h-6 w-6 text-accent-400" aria-hidden="true" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
                <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
            </svg>
            <span class="font-semibold tracking-tight">Snipmark</span>
        </div>

        <nav aria-label="Navigasi utama" class="px-3 pb-4">
            <ul class="flex gap-1 lg:flex-col">
                @php
                    $items = [
                        ['route' => 'dashboard', 'label' => 'Tautan', 'icon' => 'M4 6h16M4 12h16M4 18h10'],
                    ];
                @endphp

                @foreach ($items as $item)
                    @php $aktif = request()->routeIs($item['route']); @endphp
                    <li class="flex-1">
                        <a href="{{ route($item['route']) }}"
                           @if ($aktif) aria-current="page" @endif
                           class="flex min-h-[44px] items-center gap-3 rounded-control px-3 text-sm
                                  {{ $aktif
                                        ? 'bg-ink-800 font-medium text-fg-100'
                                        : 'text-fg-500 hover:bg-ink-800 hover:text-fg-100' }}">
                            <svg viewBox="0 0 24 24" class="h-4 w-4" aria-hidden="true" fill="none"
                                 stroke="currentColor" stroke-width="2" stroke-linecap="round">
                                <path d="{{ $item['icon'] }}"/>
                            </svg>
                            {{ $item['label'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>

        @auth
            <div class="border-t border-ink-700 px-5 py-4">
                <p class="truncate text-sm text-fg-300">{{ auth()->user()->name }}</p>
                <p class="truncate text-xs text-fg-700">{{ auth()->user()->email }}</p>

                <form method="POST" action="{{ route('logout') }}" class="mt-3">
                    @csrf
                    <button type="submit" class="btn btn-ghost w-full">Keluar</button>
                </form>
            </div>
        @endauth
    </aside>

    {{-- ============================= KONTEN ============================= --}}
    <main id="konten" class="min-w-0 flex-1">
        <div class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:py-10">

            {{-- Toast sukses: umpan balik wajib untuk setiap aksi (spec §9). --}}
            @if (session('sukses'))
                <div role="status"
                     class="mb-5 rounded-card border border-accent-600/40 bg-accent-600/10 px-4 py-3 text-sm text-accent-300">
                    {{ session('sukses') }}
                </div>
            @endif

            <header class="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-xl font-semibold tracking-tight sm:text-2xl">@yield('heading', 'Tautan')</h1>
                    @hasSection('subheading')
                        <p class="mt-1 text-sm text-fg-500">@yield('subheading')</p>
                    @endif
                </div>
                @yield('actions')
            </header>

            @yield('content')
        </div>
    </main>
</div>

</body>
</html>
