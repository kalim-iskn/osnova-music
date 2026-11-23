const neutral = ['#e7ebf5', '#f3f1f8'];
const pending = new Map();
const key = 'waveflow-artwork-palettes-v1';
const ttl = 7 * 24 * 60 * 60 * 1000;

export function paletteFromPixels(pixels) {
    const bins = Array(24).fill(0);
    let weight = 0;
    for (let i = 0; i < pixels.length; i += 4) {
        const [r, g, b] = [pixels[i] / 255, pixels[i + 1] / 255, pixels[i + 2] / 255];
        const hi = Math.max(r, g, b), lo = Math.min(r, g, b), delta = hi - lo, light = (hi + lo) / 2;
        if (!delta || light < 0.15 || light > 0.83) continue;
        const saturation = delta / (1 - Math.abs(2 * light - 1));
        if (saturation < 0.25) continue;
        let hue = hi === r ? ((g - b) / delta) % 6 : hi === g ? (b - r) / delta + 2 : (r - g) / delta + 4;
        hue = (hue * 60 + 360) % 360;
        const score = saturation ** 2 * (1 - Math.abs(light - 0.5));
        bins[Math.floor(hue / 15)] += score;
        weight += score;
    }
    if (!pixels.length || weight / (pixels.length / 4) <= 0.006) return [...neutral];
    const first = bins.indexOf(Math.max(...bins));
    const second = bins.map((score, index) => ({ score, index }))
        .filter(({ index }) => Math.min(Math.abs(index - first), 24 - Math.abs(index - first)) >= 4)
        .sort((a, b) => b.score - a.score)[0];
    const hue = first * 15 + 7;
    const other = second?.score > weight * 0.06 ? second.index * 15 + 7 : (hue + 38) % 360;
    return [`hsl(${hue} 92% 85%)`, `hsl(${other} 95% 92%)`];
}

const validPalette = value => Array.isArray(value) && value.length === 2 && value.every(color => typeof color === 'string' && /^(#[a-f\d]{6}|hsl\(\d{1,3} 9[25]% (85|92)%\))$/i.test(color));

export async function artworkPalette(url) {
    if (!url || typeof window === 'undefined') return null;
    // Genius explicitly allows anonymous canvas sampling. Other origins retain the CSS aura.
    try { if (new URL(url, window.location.href).hostname !== 'images.genius.com') return null; } catch { return null; }
    let cache = {};
    try {
        cache = JSON.parse(localStorage.getItem(key) || '{}') || {};
        const entry = cache[url];
        if (entry?.at > Date.now() - ttl && validPalette(entry.colors)) return entry.colors;
    } catch { cache = {}; }
    if (pending.has(url)) return pending.get(url);
    const request = new Promise(resolve => {
        const image = new Image();
        let timer;
        const finish = colors => { clearTimeout(timer); image.onload = image.onerror = null; resolve(colors); };
        image.crossOrigin = 'anonymous';
        image.onload = () => {
            try {
                const canvas = document.createElement('canvas');
                canvas.width = canvas.height = 32;
                const context = canvas.getContext('2d', { willReadFrequently: true });
                context.drawImage(image, 0, 0, 32, 32);
                const colors = paletteFromPixels(context.getImageData(0, 0, 32, 32).data);
                try {
                    const entries = Object.entries(cache).filter(([name, entry]) => name !== url && entry?.at > Date.now() - ttl && validPalette(entry.colors)).sort((a, b) => b[1].at - a[1].at).slice(0, 63);
                    localStorage.setItem(key, JSON.stringify({ ...Object.fromEntries(entries), [url]: { at: Date.now(), colors } }));
                } catch { /* Storage is optional, including private browsing. */ }
                finish(colors);
            } catch { finish(null); }
        };
        image.onerror = () => finish(null);
        timer = setTimeout(() => finish(null), 5000);
        image.src = url;
    });
    pending.set(url, request);
    if (pending.size > 64) pending.delete(pending.keys().next().value);
    const result = await request;
    if (!result) pending.delete(url);
    return result;
}
