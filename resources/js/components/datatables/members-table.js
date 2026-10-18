import DataTable from 'datatables.net-dt';
import 'datatables.net-dt/css/dataTables.dataTables.css';
import { escapeHtml, languageId } from './shared';

const STATUS_BADGE = {
    active: '<span class="dt-badge dt-badge-success">Aktif</span>',
    inactive: '<span class="dt-badge dt-badge-error">Tidak Aktif</span>',
};

const dash = '<span class="dt-muted">—</span>';

/** Badge sisa hari menuju daftar ulang: "H-12", "Hari ini", "Lewat 3 hari" */
function renewalBadge(daysLeft) {
    if (daysLeft === null || daysLeft === undefined) return '';
    if (daysLeft < 0) return `<span class="dt-badge dt-badge-error">Lewat ${Math.abs(daysLeft)} hari</span>`;
    if (daysLeft === 0) return '<span class="dt-badge dt-badge-warning">Hari ini</span>';
    if (daysLeft <= 30) return `<span class="dt-badge dt-badge-warning">H-${daysLeft}</span>`;
    return `<span class="dt-sub">${daysLeft} hari lagi</span>`;
}

export function initMembersTable() {
    const el = document.querySelector('#membersTable');
    const filters = {
        status: document.querySelector('#filterStatus'),
        contact: document.querySelector('#filterContact'),
        renewal: document.querySelector('#filterRenewal'),
    };

    const table = new DataTable(el, {
        serverSide: true,
        processing: true,
        ajax: {
            url: el.dataset.url,
            data: (d) => {
                d.status = filters.status.value;
                d.contact = filters.contact.value;
                d.renewal = filters.renewal.value;
            },
            error: (xhr) => {
                // Session habis / tidak berhak → muat ulang supaya diarahkan ke halaman login
                if ([401, 403, 419].includes(xhr.status)) {
                    window.location.reload();
                    return;
                }
                el.closest('[data-table-wrapper]')?.querySelector('[data-table-error]')?.classList.remove('hidden');
            },
        },
        // Urutan kolom harus sama dengan MemberController::SORTABLE_COLUMNS
        columns: [
            {
                data: 'no_lambung',
                render: (v) => `<span class="dt-strong">${escapeHtml(v)}</span>`,
            },
            {
                data: 'nama_lengkap',
                render: (v, type, row) =>
                    `<p class="dt-strong">${escapeHtml(v)}</p>` +
                    (row.nama_panggilan ? `<p class="dt-sub">${escapeHtml(row.nama_panggilan)}</p>` : ''),
            },
            {
                data: 'email',
                orderable: false,
                render: (v, type, row) =>
                    `<p>${v ? escapeHtml(v) : dash}</p><p class="dt-sub">${row.no_hp ? escapeHtml(row.no_hp) : dash}</p>`,
            },
            { data: 'tgl_bergabung', render: (v) => (v ? escapeHtml(v) : dash), className: 'dt-nowrap' },
            {
                data: 'status',
                className: 'dt-nowrap',
                render: (v, type, row) =>
                    (STATUS_BADGE[v] ?? escapeHtml(v)) +
                    (row.status_manual ? ' <span class="dt-badge dt-badge-gray" title="Ditetapkan manual oleh admin">Manual</span>' : ''),
            },
            {
                data: 'renewal_date',
                className: 'dt-nowrap',
                render: (v, type, row) =>
                    v ? `<p>${escapeHtml(v)}</p><p style="margin-top:2px">${renewalBadge(row.renewal_days_left)}</p>` : dash,
            },
            {
                data: 'show_url',
                orderable: false,
                searchable: false,
                className: 'dt-right',
                render: (v) => `<a href="${escapeHtml(v)}" class="dt-btn">Detail</a>`,
            },
        ],
        order: [[0, 'asc']],
        pageLength: Number(el.dataset.pageLength) || 25,
        lengthMenu: JSON.parse(el.dataset.lengthMenu || '[10,25,50,100]'),
        searchDelay: 400,
        autoWidth: false,
        language: { ...languageId, searchPlaceholder: 'Nama, email, No. HP, #lambung' },
        layout: {
            topStart: 'pageLength',
            topEnd: 'search',
            bottomStart: 'info',
            bottomEnd: 'paging',
        },

        // Simpan halaman, pencarian, sorting & filter → tetap sama saat kembali dari halaman detail
        stateSave: true,
        stateDuration: 60 * 60,
        stateSaveParams: (settings, data) => {
            data.filters = Object.fromEntries(Object.entries(filters).map(([key, select]) => [key, select.value]));
        },
        stateLoadParams: (settings, data) => {
            Object.entries(filters).forEach(([key, select]) => (select.value = data.filters?.[key] ?? ''));
        },
    });

    Object.values(filters).forEach((select) => select.addEventListener('change', () => table.ajax.reload()));

    // Kartu ringkasan → set filter
    document.querySelectorAll('[data-member-filter]').forEach((card) => {
        card.addEventListener('click', () => {
            const filter = JSON.parse(card.dataset.memberFilter);
            Object.entries(filters).forEach(([key, select]) => (select.value = filter[key] ?? ''));
            table.search('').page(0).ajax.reload();
        });
    });

    document.querySelector('#resetMemberFilters')?.addEventListener('click', () => {
        Object.values(filters).forEach((select) => (select.value = ''));
        table.search('').order([[0, 'asc']]).page(0).ajax.reload();
    });

    return table;
}
