<div>
    {{-- ============================== RINGKASAN ============================== --}}
    <div class="mb-6 grid gap-3 sm:grid-cols-3">
        <div class="card p-4">
            <p class="text-xs uppercase tracking-wide text-fg-500">Tautan</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($ringkasan['total_tautan'], 0, ',', '.') }}</p>
        </div>
        <div class="card p-4">
            <p class="text-xs uppercase tracking-wide text-fg-500">Total klik</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($ringkasan['total_klik'], 0, ',', '.') }}</p>
        </div>
        <div class="card p-4">
            <p class="text-xs uppercase tracking-wide text-fg-500">Klik manusia</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-accent-300">
                {{ number_format($ringkasan['total_manusia'], 0, ',', '.') }}
            </p>
            <p class="hint">Bot dikeluarkan dari angka ini.</p>
        </div>
    </div>

    {{-- =============================== PENCARIAN ============================= --}}
    <div class="mb-4">
        <label for="cari" class="sr-only">Cari tautan</label>
        <input id="cari" type="search" wire:model.live.debounce.300ms="cari"
               class="field" placeholder="Cari tujuan atau kode…"
               autocomplete="off">
    </div>

    {{-- ============================== DAFTAR ================================ --}}
    @if ($links->isEmpty())
        {{-- Empty state wajib (spec §9): membedakan "belum ada data" dari
             "filter tidak menemukan apa-apa". --}}
        <div class="card px-6 py-12 text-center">
            @if (trim($cari) !== '')
                <p class="text-sm text-fg-300">Tidak ada tautan yang cocok dengan “{{ $cari }}”.</p>
                <button type="button" wire:click="$set('cari', '')" class="btn btn-ghost mt-4">
                    Bersihkan pencarian
                </button>
            @else
                <p class="text-sm text-fg-300">Belum ada tautan.</p>
                <p class="hint">Buat tautan pertama Anda — analitiknya akan langsung mulai bekerja.</p>
                <button type="button" wire:click="bukaBuat" class="btn btn-primary mt-4">
                    Buat tautan
                </button>
            @endif
        </div>
    @else
        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">Daftar tautan Anda</caption>
                    <thead class="border-b border-ink-700 text-xs uppercase tracking-wide text-fg-500">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Tautan</th>
                            <th scope="col" class="px-4 py-3 font-medium text-right">Klik</th>
                            <th scope="col" class="px-4 py-3 font-medium">Status</th>
                            <th scope="col" class="px-4 py-3 font-medium text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($links as $link)
                            <tr wire:key="link-{{ $link->id }}" class="border-b border-ink-700/60 last:border-0">
                                <td class="px-4 py-3">
                                    <a href="{{ route('tautan.analitik', $link) }}"
                                       class="font-mono text-accent-300 hover:underline">/c/{{ $link->code }}</a>
                                    <p class="mt-0.5 max-w-md truncate text-xs text-fg-500" title="{{ $link->destination }}">
                                        {{ $link->destination }}
                                    </p>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ number_format($link->total_clicks, 0, ',', '.') }}
                                </td>
                                <td class="px-4 py-3">
                                    @if (! $link->is_active)
                                        <span class="badge">nonaktif</span>
                                    @elseif ($link->expires_at !== null && $link->expires_at->isPast())
                                        <span class="badge badge-warn">kedaluwarsa</span>
                                    @else
                                        <span class="badge badge-accent">aktif</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-2">
                                        <button type="button" wire:click="bukaUbah({{ $link->id }})"
                                                class="btn btn-ghost">Ubah</button>
                                        <button type="button" wire:click="toggleAktif({{ $link->id }})"
                                                class="btn btn-ghost">
                                            {{ $link->is_active ? 'Matikan' : 'Aktifkan' }}
                                        </button>
                                        <button type="button"
                                                wire:click="hapus({{ $link->id }})"
                                                wire:confirm="Hapus tautan ini? Tautannya akan langsung berhenti bekerja."
                                                class="btn btn-danger">Hapus</button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-4">
            {{ $links->links() }}
        </div>
    @endif

    {{-- =============================== MODAL ================================ --}}
    @if ($modalTerbuka)
        <div class="fixed inset-0 z-40 flex items-end justify-center bg-ink-950/80 p-4 sm:items-center"
             role="dialog" aria-modal="true" aria-labelledby="judul-modal"
             x-data
             x-on:keydown.escape.window="$wire.tutupModal()">
            <div class="card w-full max-w-lg p-5">
                <h2 id="judul-modal" class="text-lg font-semibold">
                    {{ $mengubah !== null ? 'Ubah tautan' : 'Buat tautan' }}
                </h2>

                <form wire:submit="simpan" class="mt-4">
                    <label for="destination" class="label">Tujuan</label>
                    <input id="destination" type="url" wire:model="destination"
                           class="field @error('destination') border-danger-500 @enderror"
                           placeholder="https://contoh.com/halaman"
                           autocomplete="url" required
                           @if ($errors->has('destination')) aria-invalid="true"
                           aria-describedby="error-destination" @endif>

                    @error('destination')
                        <p id="error-destination" class="error-text" role="alert">{{ $message }}</p>
                    @else
                        <p class="hint">Wajib http:// atau https://. Alamat internal ditolak.</p>
                    @enderror

                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" wire:click="tutupModal" class="btn btn-ghost">Batal</button>
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled"
                                wire:target="simpan">
                            <span wire:loading.remove wire:target="simpan">
                                {{ $mengubah !== null ? 'Simpan perubahan' : 'Buat' }}
                            </span>
                            <span wire:loading wire:target="simpan">Menyimpan…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
