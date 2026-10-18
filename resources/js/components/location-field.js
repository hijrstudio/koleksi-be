/**
 * Alpine component: input titik Google Maps (link / koordinat) dengan preview peta.
 * Logika parsing sama dengan App\Support\GeoCoordinates::parse (server tetap memvalidasi ulang).
 */
const NUMBER = '(-?\\d{1,3}(?:\\.\\d+)?)';

const PATTERNS = [
    new RegExp(`^${NUMBER}\\s*,\\s*${NUMBER}$`), // koordinat polos
    new RegExp(`!3d${NUMBER}!4d${NUMBER}`), // titik pin tempat
    new RegExp(`[?&](?:q|query|ll|destination|center)=${NUMBER}\\s*,\\s*${NUMBER}`),
    new RegExp(`@${NUMBER},${NUMBER}`), // posisi tampilan peta
];

export function parseCoordinates(input) {
    let value = String(input ?? '').trim();
    try {
        value = decodeURIComponent(value);
    } catch {
        // biarkan apa adanya bila bukan URL-encoded yang valid
    }

    if (value === '' || value.length > 2000) return null;

    for (const pattern of PATTERNS) {
        const match = value.match(pattern);
        if (!match) continue;

        const lat = Number(match[1]);
        const lng = Number(match[2]);
        if (lat < -90 || lat > 90 || lng < -180 || lng > 180 || (lat === 0 && lng === 0)) return null;

        return { lat: Number(lat.toFixed(7)), lng: Number(lng.toFixed(7)) };
    }

    return null;
}

export default function locationField({ value = '' } = {}) {
    return {
        value: value ?? '',

        get point() {
            return parseCoordinates(this.value);
        },

        get invalid() {
            return this.value.trim() !== '' && this.point === null;
        },

        get isShortLink() {
            return /maps\.app\.goo\.gl|goo\.gl\/maps/i.test(this.value);
        },

        get embedUrl() {
            return this.point ? `https://maps.google.com/maps?q=${this.point.lat},${this.point.lng}&z=16&output=embed` : null;
        },

        get mapsUrl() {
            return this.point ? `https://www.google.com/maps?q=${this.point.lat},${this.point.lng}` : null;
        },
    };
}
