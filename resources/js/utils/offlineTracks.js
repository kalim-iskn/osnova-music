import { formatSeconds } from './time.js';

// Audio is stored as one IndexedDB record per track; nothing is uploaded to a server.
// The same schema is read by public/sw.js and public/offline.html.
const DATABASE = 'waveflow-offline-v1';
const STORE = 'tracks';
const MAX_AUDIO_BYTES = 150 * 1024 * 1024;
const MAX_ARTWORK_BYTES = 3 * 1024 * 1024;
export const OFFLINE_OWNER_KEY = 'waveflow-offline-owner';

const requestResult = (request) => new Promise((resolve, reject) => {
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
});

const openDatabase = () => new Promise((resolve, reject) => {
    if (!('indexedDB' in window)) {
        reject(new Error('Этот браузер не поддерживает офлайн-хранилище.'));
        return;
    }

    const request = indexedDB.open(DATABASE, 1);
    request.onupgradeneeded = () => {
        const store = request.result.createObjectStore(STORE, { keyPath: 'key' });
        store.createIndex('ownerId', 'ownerId');
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
    request.onblocked = () => reject(new Error('Закройте другие вкладки сайта и попробуйте снова.'));
});

const withStore = async (mode, operation) => {
    const db = await openDatabase();
    try {
        const transaction = db.transaction(STORE, mode);
        const result = await operation(transaction.objectStore(STORE));
        await new Promise((resolve, reject) => {
            transaction.oncomplete = resolve;
            transaction.onerror = () => reject(transaction.error);
            transaction.onabort = () => reject(transaction.error);
        });
        return result;
    } finally {
        db.close();
    }
};

const trackKey = (ownerId, trackId) => `${ownerId}:${trackId}`;

export const getOfflineTracks = (ownerId) => ownerId == null
    ? Promise.resolve([])
    : withStore('readonly', (store) => requestResult(store.index('ownerId').getAll(String(ownerId))));

export const deleteOfflineTrack = (ownerId, trackId) => withStore('readwrite', (store) =>
    requestResult(store.delete(trackKey(ownerId, trackId))));

export const notifyOfflineTracksChanged = () => window.dispatchEvent(new Event('waveflow-offline-changed'));

const fetchArtwork = async (url) => {
    if (!url) return null;

    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 6000);
    try {
        const response = await fetch(url, { mode: 'cors', credentials: 'omit', signal: controller.signal });
        const mime = (response.headers.get('Content-Type') || '').split(';')[0].trim().toLowerCase();
        if (!response.ok || !['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'].includes(mime)) {
            return null;
        }
        const blob = await response.blob();
        return blob.size > 0 && blob.size <= MAX_ARTWORK_BYTES ? blob : null;
    } catch {
        // Cross-origin artwork without CORS is optional; audio remains downloadable.
        return null;
    } finally {
        clearTimeout(timeout);
    }
};

export const downloadOfflineTrack = async (ownerId, track) => {
    if (ownerId == null || !track?.id || track.is_playable === false || !track.playback_url) {
        throw new Error('Трек недоступен для скачивания.');
    }

    const url = new URL(track.playback_url, window.location.origin);
    if (url.origin !== window.location.origin) {
        throw new Error('Скачивание возможно только через сервер WaveFlow.');
    }

    const response = await fetch(url.href, { credentials: 'same-origin', cache: 'no-store' });
    if (!response.ok || response.status !== 200) {
        throw new Error('Сервер не отдал полный аудиофайл.');
    }

    const contentType = (response.headers.get('Content-Type') || '').split(';')[0].toLowerCase();
    if (contentType && !contentType.startsWith('audio/') && !['application/octet-stream', 'binary/octet-stream'].includes(contentType)) {
        throw new Error('Вместо аудио сервер вернул другой тип файла.');
    }
    if (Number(response.headers.get('Content-Length') || 0) > MAX_AUDIO_BYTES) {
        throw new Error('Файл слишком большой для офлайн-хранилища (лимит 150 МБ).');
    }

    const downloaded = await response.blob();
    if (!downloaded.size || downloaded.size > MAX_AUDIO_BYTES) {
        throw new Error('Аудиофайл пустой или превышает лимит 150 МБ.');
    }

    // Some upstream servers incorrectly report application/octet-stream.
    const audio = new Blob([downloaded], { type: contentType.startsWith('audio/') ? contentType : 'audio/mpeg' });
    const artwork = await fetchArtwork(track.cover_image_url);
    const record = {
        key: trackKey(ownerId, track.id),
        ownerId: String(ownerId),
        id: track.id,
        title: track.title,
        artist: track.artists?.map((artist) => artist.name).join(', ') || track.artist?.name || 'Неизвестный исполнитель',
        album: track.album?.title || '',
        // Keep the flat labels for the standalone offline player and structured
        // credits for the library. JSON also unwraps Vue proxies for IndexedDB.
        catalog: JSON.parse(JSON.stringify({
            artist: typeof track.artist === 'object' ? track.artist : null,
            artists: Array.isArray(track.artists) ? track.artists : [],
            album: typeof track.album === 'object' ? track.album : null,
        })),
        duration_seconds: Number(track.duration_seconds || 0),
        cover_image_url: track.cover_image_url || '',
        audio,
        artwork,
        size: audio.size,
        savedAt: Date.now(),
    };

    // A single transaction ensures the audio and metadata cannot get out of sync.
    await withStore('readwrite', (store) => requestResult(store.put(record)));
    try { await navigator.storage?.persist?.(); } catch { /* Best effort on iOS. */ }
    notifyOfflineTracksChanged();
    return record;
};

export const offlineMediaUrl = (ownerId, trackId, kind = 'audio') =>
    `/__waveflow_offline__/${encodeURIComponent(ownerId)}/${encodeURIComponent(trackId)}/${kind}`;

// Older downloads contain artist/album labels, not the catalog's objects.
// Adapt on read so existing audio and the standalone player's schema stay intact.
export const offlineTrackForLibrary = (record, ownerId, { catalogTrack, artists = [] } = {}) => {
    const knownArtists = new Map(artists.filter(artist => typeof artist?.name === 'string')
        .map(artist => [artist.name.trim().toLocaleLowerCase('ru'), artist]));
    const credits = [catalogTrack?.artists, record.catalog?.artists, record.artists]
        .find(value => Array.isArray(value) && value.length)
        ?? (record.catalog?.artist ? [record.catalog.artist]
            : typeof record.artist === 'string' ? record.artist.split(',') : [record.artist]);
    const resolvedArtists = credits.map(artist => {
        const label = typeof artist === 'string' ? artist : artist?.name;
        const name = typeof label === 'string' ? label.trim() : '';
        if (!name) return null;
        return { ...knownArtists.get(name.toLocaleLowerCase('ru')), ...(typeof artist === 'object' ? artist : {}), name };
    }).filter(Boolean);
    const album = catalogTrack?.album ?? record.catalog?.album ?? record.album;
    const duration = Number(record.duration_seconds ?? catalogTrack?.duration_seconds ?? 0) || 0;
    const playable = ownerId != null && Number(record.audio?.size) > 0;

    return {
        id: record.id,
        title: record.title,
        show_url: catalogTrack?.show_url ?? `/tracks/${record.id}`,
        artist: resolvedArtists[0] ?? null,
        artists: resolvedArtists,
        album: typeof album === 'string' ? (album ? { title: album } : null) : album ?? null,
        duration_seconds: duration,
        duration_human: formatSeconds(duration),
        is_playable: playable,
        playback_url: playable ? offlineMediaUrl(ownerId, record.id) : null,
        cover_image_url: record.artwork && ownerId != null ? offlineMediaUrl(ownerId, record.id, 'artwork')
            : record.cover_image_url || catalogTrack?.cover_image_url || '/track-placeholder.svg',
    };
};
