<div>
    <div class="mb-5 flex flex-wrap items-center gap-3">
        <a href="{{ route('dashboard') }}" class="btn btn-ghost">← Kembali</a>
        <code class="font-mono text-sm text-accent-300">{{ url('/c/'.$link->code) }}</code>

        <button type="button"
                x-data="{ disalin: false }"
                x-on:click="navigator.clipboard.writeText('{{ url('/c/'.$link->code) }}').then(() => { disalin = true; setTimeout(() => disalin = false, 1600); })"
                class="btn btn-ghost">
            <span x-show="! disalin">Salin</span>
            <span x-show="disalin" x-cloak>Tersalin</span>
        </button>

        @if (! $link->is_active)
            <span class="badge">nonaktif</span>
        @endif
    </div>

    <p class="mb-6 truncate text-sm text-fg-500" title="{{ $link->destination }}">
        Mengarah ke <span class="text-fg-300">{{ $link->destination }}</span>
    </p>

    {{-- ============================== RINGKASAN ============================== --}}
    <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="card p-4">
            <p class="text-xs uppercase tracking-wide text-fg-500">Total klik</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($angka['total'], 0, ',', '.') }}</p>
        </div>
        <div class="card p-4">
            <p class="text-xs uppercase tracking-wide text-fg-500">Manusia</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-accent-300">{{ number_format($angka['human'], 0, ',', '.') }}</p>
        </div>
        <div class="card p-4">
            <p class="text-xs uppercase tracking-wide text-fg-500">Bot tersaring</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-warn-400">{{ number_format($angka['bots'], 0, ',', '.') }}</p>
            <p class="hint">Lalu lintas otomatis yang tidak mencemari angka manusia.</p>
        </div>
        <div class="card p-4">
            <p class="text-xs uppercase tracking-wide text-fg-500">Pengunjung unik</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($angka['unik'], 0, ',', '.') }}</p>
            <p class="hint">Dihitung per hari; identitas di-hash &amp; berotasi harian.</p>
        </div>
    </div>

    {{-- ================================ GRAFIK =============================== --}}
    <div class="card mb-6 p-5">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-sm font-medium">Klik per hari</h2>

            <div class="flex items-center gap-3">
                {{-- Filter rentang --}}
                <div role="group" aria-label="Rentang waktu" class="flex gap-1">
                    @foreach (\App\Livewire\AnalitikTautan::RENTANG as $r)
                        <button type="button" wire:click="setRentang({{ $r }})"
                                @if ($hari === $r) aria-pressed="true" @else aria-pressed="false" @endif
                                class="btn {{ $hari === $r ? 'btn-primary' : 'btn-ghost' }} px-3 text-xs">
                            {{ $r }} hari
                        </button>
                    @endforeach
                </div>

                {{-- Toggle bot: inti dari nilai analitik ini --}}
                <label class="flex cursor-pointer items-center gap-2 text-xs text-fg-300">
                    <input type="checkbox" wire:model.live="sertakanBot"
                           class="h-4 w-4 rounded border-ink-700 bg-ink-900 text-accent-500">
                    Sertakan bot
                </label>
            </div>
        </div>

        @if (array_sum($deret['nilai']) === 0)
            {{-- Empty state: membedakan "belum ada klik" dari "grafik rusak". --}}
            <div class="py-12 text-center">
                <p class="text-sm text-fg-300">Belum ada klik pada rentang ini.</p>
                <p class="hint">Bagikan tautan <span class="font-mono">{{ url('/c/'.$link->code) }}</span> lalu muat ulang halaman ini.</p>
            </div>
        @else
            <div class="text-accent-400">
                {!! $deret['svg'] !!}
            </div>

            <div class="mt-2 flex justify-between text-xs text-fg-700">
                <span>{{ \Carbon\CarbonImmutable::parse($deret['label'][0])->translatedFormat('j M') }}</span>
                <span>{{ $labelHariIni }} <span class="text-fg-500">(hari berjalan)</span></span>
            </div>
        @endif
    </div>

    {{-- =============================== RINCIAN =============================== --}}
    @php
        $judul = [
            'device' => 'Perangkat',
            'browser' => 'Peramban',
            'os' => 'Sistem operasi',
            'referrer' => 'Sumber rujukan',
        ];
    @endphp

    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ($judul as $kunci => $label)
            <div class="card p-5">
                <h2 class="mb-3 text-sm font-medium">{{ $label }}</h2>

                @if (($rincian[$kunci] ?? []) === [])
                    <p class="text-sm text-fg-500">Belum ada data.</p>
                @else
                    @php $maks = max($rincian[$kunci]); @endphp
                    <ul class="space-y-2">
                        @foreach ($rincian[$kunci] as $nama => $jumlah)
                            <li class="text-sm">
                                <div class="flex items-baseline justify-between gap-3">
                                    <span class="truncate text-fg-300">{{ $nama }}</span>
                                    <span class="tabular-nums text-fg-100">{{ number_format($jumlah, 0, ',', '.') }}</span>
                                </div>
                                {{-- Batang proporsi: divisualkan tanpa pustaka chart. --}}
                                <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-ink-800"
                                     role="presentation">
                                    <div class="h-full rounded-full bg-accent-500"
                                         style="width: {{ $maks > 0 ? round($jumlah / $maks * 100) : 0 }}%"></div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    </div>

    <p class="mt-6 text-xs text-fg-700">
        Angka hari berjalan diambil langsung dari peristiwa mentah; hari-hari sebelumnya
        dibaca dari agregat harian. Karena itu nilai hari ini dapat berbeda tipis setelah
        hari berakhir.
    </p>
</div>
