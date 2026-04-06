export type FetchErrorKind = 'cloudflare' | 'blocked' | 'rate_limited' | 'fetch_failed' | null;

export function classifyFetchError(error: string | null | undefined): FetchErrorKind {
    if (!error) {
        return null;
    }

    const normalized = error.toLowerCase();

    if (normalized.includes('cloudflare')) {
        return 'cloudflare';
    }

    if (normalized.includes('rate limited') || normalized.includes('http 429')) {
        return 'rate_limited';
    }

    if (normalized.includes('blocked by the site') || normalized.includes('http 403')) {
        return 'blocked';
    }

    if (normalized.includes('failed to fetch page')) {
        return 'fetch_failed';
    }

    return null;
}
