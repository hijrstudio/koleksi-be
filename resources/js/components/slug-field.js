/**
 * Alpine component: slug otomatis dari judul.
 * Selama user belum mengetik slug sendiri (auto = true), slug mengikuti judul.
 * Server tetap menormalisasi & memastikan slug unik (App\Http\Requests\Cms\GuideArticleRequest).
 */
export function slugify(text) {
    return String(text ?? '')
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '') // buang aksen: é -> e
        .toLowerCase()
        .replace(/&/g, ' and ')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 240)
        .replace(/-+$/g, '');
}

export default function slugField({ slug = '', auto = true } = {}) {
    return {
        slug: slug ?? '',
        auto,

        fromTitle(title) {
            if (this.auto) this.slug = slugify(title);
        },

        edited() {
            this.auto = this.slug === '';
        },

        regenerate(title) {
            this.auto = true;
            this.slug = slugify(title);
        },
    };
}
