@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    @include('pages.cms.forum-discussions.partials.flash')

    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="flex items-center justify-between gap-4 border-b border-gray-100 px-5 py-4 dark:border-gray-800">
            <p class="text-theme-sm text-gray-500 dark:text-gray-400">Kategori nonaktif tidak bisa dipilih saat membuat diskusi baru.</p>
            @can('create', App\Models\ForumCategory::class)
                <a href="{{ route('forum-categories.create') }}"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex h-11 shrink-0 items-center rounded-lg px-4 text-sm font-medium text-white transition">
                    + Tambah Kategori
                </a>
            @endcan
        </div>

        <div class="max-w-full overflow-x-auto">
            <table class="min-w-full">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        @foreach (['Urutan', 'Kategori', 'Diskusi', 'Status', ''] as $heading)
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($categories as $category)
                        <tr>
                            <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{{ $category->sort_order }}</td>
                            <td class="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $category->name }}</td>
                            <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                <a href="{{ route('forum-discussions.index', ['category' => $category->id]) }}" class="text-brand-500 hover:underline">
                                    {{ number_format($category->discussions_count) }} diskusi
                                </a>
                            </td>
                            <td class="px-5 py-4">
                                <span class="rounded-full px-2.5 py-0.5 text-theme-xs font-medium {{ $category->is_active ? 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-500' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400' }}">
                                    {{ $category->is_active ? 'Aktif' : 'Nonaktif' }}
                                </span>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center justify-end gap-2">
                                    @can('update', $category)
                                        <a href="{{ route('forum-categories.edit', $category) }}"
                                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.03]">Edit</a>
                                    @endcan
                                    @can('delete', $category)
                                        <form method="POST" action="{{ route('forum-categories.destroy', $category) }}"
                                            x-data @submit.prevent="if (confirm('Hapus kategori ini?')) $el.submit()">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" @disabled($category->discussions_count > 0)
                                                title="{{ $category->discussions_count > 0 ? 'Kategori masih dipakai diskusi' : 'Hapus' }}"
                                                class="border-error-300 text-error-600 hover:bg-error-50 dark:border-error-500/40 dark:text-error-500 dark:hover:bg-error-500/10 rounded-lg border px-3 py-1.5 text-theme-sm font-medium disabled:cursor-not-allowed disabled:opacity-40">
                                                Hapus
                                            </button>
                                        </form>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">Belum ada kategori.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
