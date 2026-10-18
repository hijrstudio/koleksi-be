/**
 * Alpine component untuk form Kepengurusan: repeater pengurus per grup (penasihat / bidang).
 * Urutan baris di form = sort_order yang disimpan server.
 */
const newKey = () => 'n' + Math.random().toString(36).slice(2, 10);

export default function managementForm({ rows = {}, errors = {}, maxUploads = 20 } = {}) {
    return {
        groups: rows,
        errors,

        add(group) {
            this.groups[group].push({
                key: newKey(),
                id: null,
                name: '',
                position: { id: '', en: '' },
                photo_url: null,
                preview: null,
                remove_photo: false,
            });
        },

        remove(group, index) {
            const row = this.groups[group][index];
            if ((row.name || row.position.id) && !window.confirm('Hapus baris pengurus ini?')) return;
            if (row.preview) URL.revokeObjectURL(row.preview);
            this.groups[group].splice(index, 1);
        },

        move(group, index, step) {
            const list = this.groups[group];
            const target = index + step;
            if (target < 0 || target >= list.length) return;
            list.splice(target, 0, list.splice(index, 1)[0]);
        },

        pick(row, event) {
            const file = event.target.files[0];
            if (row.preview) URL.revokeObjectURL(row.preview);
            row.preview = file ? URL.createObjectURL(file) : null;
            if (file) row.remove_photo = false;
        },

        photo(row) {
            if (row.preview) return row.preview;
            return row.remove_photo ? null : row.photo_url;
        },

        error(row, field) {
            return this.errors[`members.${row.key}.${field}`]?.[0] ?? null;
        },

        // PHP membatasi jumlah file per request (max_file_uploads); file di atas batas akan hilang diam-diam
        submit(event) {
            const selected = [...this.$root.querySelectorAll('input[type=file]')].filter((input) => input.files.length).length;

            if (selected > maxUploads) {
                event.preventDefault();
                window.alert(`Maksimal ${maxUploads} foto baru per sekali simpan. Simpan dulu, lalu upload sisanya.`);
            }
        },
    };
}
