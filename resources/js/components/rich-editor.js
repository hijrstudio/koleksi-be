/**
 * Alpine component untuk rich text editor (TipTap).
 * Library TipTap dimuat lazy, jadi hanya terunduh di halaman yang memakai editor.
 * HTML hasil editor tetap disanitasi ulang di server (App\Support\RichTextSanitizer).
 *
 * Gambar di dalam konten hanya aktif bila `imageUpload` diberikan ({ url, maxKb, mimes }):
 * file diupload ke server dulu, lalu <img src> diisi URL publik hasil upload (bukan base64).
 */
const ACTIVE_CHECKS = {
    bold: ['bold'],
    italic: ['italic'],
    underline: ['underline'],
    strike: ['strike'],
    h2: ['heading', { level: 2 }],
    h3: ['heading', { level: 3 }],
    bulletList: ['bulletList'],
    orderedList: ['orderedList'],
    blockquote: ['blockquote'],
    link: ['link'],
    image: ['image'],
};

const imageFiles = (fileList) => [...(fileList ?? [])].filter((file) => file.type.startsWith('image/'));

export default function richEditor({ content = '', editable = true, imageUpload = null } = {}) {
    // Sengaja di luar state Alpine: proxy reaktif Alpine merusak instance TipTap
    let editor = null;

    return {
        html: content ?? '',
        ready: false,
        active: {},
        canUploadImage: Boolean(imageUpload?.url) && editable,
        uploading: 0,
        uploadError: null,

        async init() {
            const imports = [import('@tiptap/core'), import('@tiptap/starter-kit')];
            if (this.canUploadImage) imports.push(import('@tiptap/extension-image'));

            const [{ Editor }, { default: StarterKit }, imageModule] = await Promise.all(imports);

            const extensions = [
                StarterKit.configure({
                    heading: { levels: [2, 3, 4] },
                    code: false,
                    codeBlock: false,
                    link: {
                        openOnClick: false,
                        autolink: true,
                        defaultProtocol: 'https',
                        protocols: ['http', 'https', 'mailto', 'tel'],
                    },
                }),
            ];

            if (imageModule) {
                extensions.push(imageModule.default.configure({ inline: false, allowBase64: false }));
            }

            editor = new Editor({
                element: this.$refs.editor,
                editable,
                content: this.html,
                extensions,
                editorProps: {
                    attributes: { class: 'rich-editor-content' },
                    // Paste / drag-drop file gambar langsung diupload
                    handlePaste: (view, event) => this.handleFiles(imageFiles(event.clipboardData?.files)),
                    handleDrop: (view, event, slice, moved) => {
                        if (moved) return false;
                        const pos = view.posAtCoords({ left: event.clientX, top: event.clientY })?.pos;
                        return this.handleFiles(imageFiles(event.dataTransfer?.files), pos);
                    },
                },
                onUpdate: ({ editor }) => {
                    this.html = editor.isEmpty ? '' : editor.getHTML();
                },
                onTransaction: () => this.refresh(),
            });

            this.ready = true;
            this.refresh();
        },

        destroy() {
            editor?.destroy();
            editor = null;
        },

        refresh() {
            if (!editor) return;
            this.active = Object.fromEntries(
                Object.entries(ACTIVE_CHECKS).map(([key, [name, attrs]]) => [key, editor.isActive(name, attrs)]),
            );
        },

        run(command, ...args) {
            editor?.chain().focus()[command](...args).run();
        },

        setLink() {
            if (!editor) return;

            const previous = editor.getAttributes('link').href ?? '';
            const url = window.prompt('URL link (kosongkan untuk menghapus link)', previous || 'https://');

            if (url === null) return;

            if (url.trim() === '') {
                editor.chain().focus().extendMarkRange('link').unsetLink().run();
                return;
            }

            editor.chain().focus().extendMarkRange('link').setLink({ href: url.trim() }).run();
        },

        // ---- Gambar ----

        pickImage() {
            this.$refs.imageInput?.click();
        },

        onImagePicked(event) {
            this.handleFiles(imageFiles(event.target.files));
            event.target.value = ''; // supaya file yang sama bisa dipilih lagi
        },

        setImageAlt() {
            if (!editor?.isActive('image')) return;
            const alt = window.prompt('Teks alternatif gambar (untuk aksesibilitas & SEO)', editor.getAttributes('image').alt ?? '');
            if (alt !== null) editor.chain().focus().updateAttributes('image', { alt: alt.trim() }).run();
        },

        /**
         * @returns {boolean} true bila event sudah ditangani (file gambar diupload)
         */
        handleFiles(files, pos = null) {
            if (!this.canUploadImage || files.length === 0) return false;

            files.forEach((file) => this.uploadImage(file, pos));

            return true;
        },

        async uploadImage(file, pos = null) {
            this.uploadError = null;

            if (!imageUpload.mimes.includes(file.type)) {
                this.uploadError = `Format ${file.name} tidak didukung.`;
                return;
            }

            if (file.size > imageUpload.maxKb * 1024) {
                this.uploadError = `${file.name} melebihi ${imageUpload.maxKb / 1024} MB.`;
                return;
            }

            const data = new FormData();
            data.append('image', file);
            this.uploading++;

            try {
                const response = await window.axios.post(imageUpload.url, data);
                const node = { type: 'image', attrs: { src: response.data.url, alt: file.name.replace(/\.[^.]+$/, '') } };
                const chain = editor.chain().focus();
                (pos != null ? chain.insertContentAt(pos, node) : chain.insertContent(node)).run();
            } catch (error) {
                this.uploadError = error.response?.data?.errors?.image?.[0]
                    ?? error.response?.data?.message
                    ?? `Gagal mengupload ${file.name}.`;
            } finally {
                this.uploading--;
            }
        },
    };
}
