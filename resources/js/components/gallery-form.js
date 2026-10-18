/**
 * Alpine component: repeater foto album galeri.
 * Tiap foto diupload sendiri-sendiri (AJAX) begitu dipilih, lalu form hanya mengirim path / id-nya.
 * Dengan begitu album besar tidak terbentur batas PHP max_file_uploads / post_max_size.
 * Urutan foto di grid = urutan tampil (sort_order) di website.
 */
const CONCURRENCY = 3;

const newKey = () => 'p' + Math.random().toString(36).slice(2, 10);

export default function galleryForm({ photos = [], upload, maxPhotos = 200, errors = {} } = {}) {
    const queue = [];
    let running = 0;

    return {
        photos: photos.map((photo) => ({ ...photo, key: newKey(), uploading: false, progress: 0, error: null })),
        errors,
        dragging: false,

        get uploadingCount() {
            return this.photos.filter((photo) => photo.uploading).length;
        },

        get failedCount() {
            return this.photos.filter((photo) => photo.error).length;
        },

        addFiles(fileList) {
            const files = [...(fileList ?? [])].filter((file) => file.type.startsWith('image/'));
            const room = maxPhotos - this.photos.length;

            if (files.length > room) {
                window.alert(`Maksimal ${maxPhotos} foto per album. ${files.length - Math.max(room, 0)} foto tidak ditambahkan.`);
            }

            files.slice(0, Math.max(room, 0)).forEach((file) => {
                const photo = { key: newKey(), id: null, path: null, url: URL.createObjectURL(file), uploading: false, progress: 0, error: null };

                if (!upload.mimes.includes(file.type)) {
                    photo.error = 'Format tidak didukung';
                } else if (file.size > upload.maxKb * 1024) {
                    photo.error = `Maks ${upload.maxKb / 1024} MB`;
                } else {
                    photo.uploading = true;
                    queue.push({ key: photo.key, file });
                }

                this.photos.push(photo);
            });

            this.pump();
        },

        onPick(event) {
            this.addFiles(event.target.files);
            event.target.value = ''; // supaya file yang sama bisa dipilih lagi
        },

        onDrop(event) {
            this.dragging = false;
            this.addFiles(event.dataTransfer?.files);
        },

        // Jalankan antrean upload dengan batas paralel
        pump() {
            while (running < CONCURRENCY && queue.length) {
                const job = queue.shift();
                running++;
                this.uploadOne(job).finally(() => {
                    running--;
                    this.pump();
                });
            }
        },

        async uploadOne({ key, file }) {
            const data = new FormData();
            data.append('image', file);

            try {
                const response = await window.axios.post(upload.url, data, {
                    onUploadProgress: (event) => {
                        const photo = this.find(key);
                        if (photo && event.total) photo.progress = Math.round((event.loaded / event.total) * 100);
                    },
                });

                const photo = this.find(key);
                if (!photo) return; // sudah dihapus user saat upload berjalan
                photo.path = response.data.path;
                photo.uploading = false;
            } catch (error) {
                const photo = this.find(key);
                if (!photo) return;
                photo.uploading = false;
                photo.error = error.response?.data?.errors?.image?.[0] ?? error.response?.data?.message ?? 'Gagal upload';
            }
        },

        find(key) {
            return this.photos.find((photo) => photo.key === key);
        },

        remove(index) {
            const [photo] = this.photos.splice(index, 1);
            const queued = queue.findIndex((job) => job.key === photo.key);
            if (queued !== -1) queue.splice(queued, 1);
            if (photo.url?.startsWith('blob:')) URL.revokeObjectURL(photo.url);
        },

        move(index, step) {
            const target = index + step;
            if (target < 0 || target >= this.photos.length) return;
            this.photos.splice(target, 0, this.photos.splice(index, 1)[0]);
        },

        error(index, field) {
            return this.errors[`photos.${index}.${field}`]?.[0] ?? null;
        },

        submit(event) {
            if (this.uploadingCount > 0) {
                event.preventDefault();
                window.alert('Tunggu sampai semua foto selesai diupload.');
            } else if (this.failedCount > 0) {
                event.preventDefault();
                window.alert('Ada foto yang gagal diupload. Hapus foto bertanda merah terlebih dahulu.');
            }
        },
    };
}
