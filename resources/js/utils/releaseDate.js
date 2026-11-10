export const formatAlbumReleaseDate = (album) => {
    if (album?.release_date) {
        return album.release_date;
    }

    const year = Number(album?.release_date_components?.year);
    const month = Number(album?.release_date_components?.month);

    if (!Number.isInteger(year) || year <= 0) {
        return null;
    }

    if (Number.isInteger(month) && month >= 1 && month <= 12) {
        return new Intl.DateTimeFormat('ru-RU', { month: 'long', year: 'numeric', timeZone: 'UTC' })
            .format(new Date(Date.UTC(year, month - 1, 1)));
    }

    return `${year} г.`;
};
