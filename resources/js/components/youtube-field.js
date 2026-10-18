/**
 * Alpine component: input link YouTube dengan preview.
 * Logika parsing sama dengan App\Models\GuideVideo::parseYoutubeId (server tetap memvalidasi ulang).
 */
const ID_PATTERN = /^[A-Za-z0-9_-]{11}$/;

const HOSTS = [
    'youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com',
    'youtu.be', 'www.youtu.be', 'youtube-nocookie.com', 'www.youtube-nocookie.com',
];

export function parseYoutubeId(input) {
    const value = String(input ?? '').trim();

    if (value === '') return null;
    if (ID_PATTERN.test(value)) return value;

    let url;
    try {
        url = new URL(/^https?:\/\//i.test(value) ? value : `https://${value}`);
    } catch {
        return null;
    }

    const host = url.hostname.toLowerCase();
    if (!HOSTS.includes(host)) return null;

    const path = url.pathname.replace(/^\/+|\/+$/g, '');
    let candidate = '';

    if (host.endsWith('youtu.be')) {
        candidate = path.split('/')[0];
    } else if (path === 'watch') {
        candidate = url.searchParams.get('v') ?? '';
    } else {
        candidate = path.match(/^(?:embed|shorts|live|v)\/([^/]+)/)?.[1] ?? '';
    }

    return ID_PATTERN.test(candidate) ? candidate : null;
}

export default function youtubeField({ url = '' } = {}) {
    return {
        url: url ?? '',
        playing: false,

        get videoId() {
            return parseYoutubeId(this.url);
        },

        get invalid() {
            return this.url.trim() !== '' && this.videoId === null;
        },

        get thumbnail() {
            return this.videoId ? `https://i.ytimg.com/vi/${this.videoId}/hqdefault.jpg` : null;
        },

        get embedUrl() {
            return this.videoId ? `https://www.youtube-nocookie.com/embed/${this.videoId}?autoplay=1` : null;
        },
    };
}
