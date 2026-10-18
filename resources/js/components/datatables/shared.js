import './datatables.css';

/** Escape teks sebelum dimasukkan ke HTML (data dari server tetap dianggap tidak aman). */
export function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

/** Teks DataTables dalam Bahasa Indonesia. */
export const languageId = {
    processing: 'Memuat data...',
    search: '',
    searchPlaceholder: 'Cari...',
    lengthMenu: '_MENU_ per halaman',
    info: 'Menampilkan _START_–_END_ dari _TOTAL_ data',
    infoEmpty: 'Tidak ada data',
    infoFiltered: '(disaring dari _MAX_ data)',
    zeroRecords: 'Tidak ada data yang cocok',
    emptyTable: 'Belum ada data',
    loadingRecords: 'Memuat...',
    paginate: {
        first: '«',
        last: '»',
        next: '›',
        previous: '‹',
    },
    aria: {
        orderable: 'Urutkan kolom ini',
        orderableReverse: 'Balik urutan kolom ini',
    },
};
