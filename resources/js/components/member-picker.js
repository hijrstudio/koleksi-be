/**
 * Alpine component: pilih member lewat pencarian server-side (debounce), supaya tetap ringan walau member ribuan.
 * Endpoint mengembalikan { results: [{ id, label }] }.
 */
export default function memberPicker({ url, selected = null } = {}) {
    let timer = null;
    let controller = null;

    return {
        selected, // { id, label } | null
        query: '',
        results: [],
        open: false,
        loading: false,
        failed: false,

        search() {
            clearTimeout(timer);
            timer = setTimeout(() => this.fetch(), 300);
        },

        async fetch() {
            controller?.abort(); // batalkan request lama agar hasil tidak tertukar
            controller = new AbortController();
            this.loading = true;
            this.failed = false;

            try {
                const response = await window.axios.get(url, { params: { q: this.query }, signal: controller.signal });
                this.results = response.data.results;
                this.open = true;
            } catch (error) {
                if (error.name !== 'CanceledError') this.failed = true;
            } finally {
                this.loading = false;
            }
        },

        choose(member) {
            this.selected = member;
            this.query = '';
            this.results = [];
            this.open = false;
        },

        clear() {
            this.selected = null;
            this.$nextTick(() => this.$refs.search?.focus());
        },
    };
}
