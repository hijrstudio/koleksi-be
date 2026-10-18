{{-- Notifikasi sukses / gagal setelah simpan atau hapus (dipakai modul Merek & Tipe kendaraan). --}}
@if (session('success'))
    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)"
        class="border-success-500 bg-success-50 dark:border-success-500/30 dark:bg-success-500/15 mb-6 rounded-xl border p-4">
        <p class="text-sm text-gray-700 dark:text-gray-300">{{ session('success') }}</p>
    </div>
@endif

@if (session('error'))
    <div class="border-error-500 bg-error-50 dark:border-error-500/30 dark:bg-error-500/15 mb-6 rounded-xl border p-4">
        <p class="text-sm text-gray-700 dark:text-gray-300">{{ session('error') }}</p>
    </div>
@endif
