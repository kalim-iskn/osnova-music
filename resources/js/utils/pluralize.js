export const pluralize = (count, forms) => {
    const absolute = Math.abs(Number(count)) % 100;
    const remainder = absolute % 10;

    if (absolute > 10 && absolute < 20) {
        return forms[2];
    }

    if (remainder > 1 && remainder < 5) {
        return forms[1];
    }

    if (remainder === 1) {
        return forms[0];
    }

    return forms[2];
};

export const formatNumber = (count) => new Intl.NumberFormat('ru-RU').format(Number(count) || 0);

export const formatCount = (count, forms) => `${count} ${pluralize(count, forms)}`;

export const formatNumberedCount = (count, forms) => `${formatNumber(count)} ${pluralize(count, forms)}`;
