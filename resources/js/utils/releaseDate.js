export const formatAlbumReleaseDate = (album) => {
    const year = Number(album?.release_date_components?.year);
    const month = Number(album?.release_date_components?.month);
    const day = Number(album?.release_date_components?.day);

    if (!Number.isInteger(year) || year <= 0) {
        return null;
    }

    if (Number.isInteger(month) && month >= 1 && month <= 12) {
        if (Number.isInteger(day) && day >= 1 && day <= 31) {
            return new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' })
                .format(new Date(Date.UTC(year, month - 1, day)));
        }
        return new Intl.DateTimeFormat('ru-RU', { month: 'long', year: 'numeric', timeZone: 'UTC' })
            .format(new Date(Date.UTC(year, month - 1, 1)));
    }

    return `${year} г.`;
};
