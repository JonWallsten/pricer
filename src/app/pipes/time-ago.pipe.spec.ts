import { TimeAgoPipe } from './time-ago.pipe';

const strings = {
    justNow: 'Just now',
    minutesAgo: '{n} min ago',
    hoursAgo: '{n}h ago',
    daysAgo: '{n}d ago',
};

describe('TimeAgoPipe', () => {
    let pipe: TimeAgoPipe;

    beforeEach(() => {
        pipe = new TimeAgoPipe();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('returns empty string for null', () => {
        expect(pipe.transform(null, strings)).toBe('');
    });

    it('returns empty string for undefined', () => {
        expect(pipe.transform(undefined, strings)).toBe('');
    });

    it('returns empty string for empty string', () => {
        expect(pipe.transform('', strings)).toBe('');
    });

    it('returns justNow for a date less than 1 minute ago', () => {
        const now = new Date('2026-04-06T12:00:00Z');
        vi.setSystemTime(now);
        const thirtySecondsAgo = new Date(now.getTime() - 30_000).toISOString();
        expect(pipe.transform(thirtySecondsAgo, strings)).toBe('Just now');
    });

    it('returns minutesAgo for a date 5 minutes ago', () => {
        const now = new Date('2026-04-06T12:00:00Z');
        vi.setSystemTime(now);
        const fiveMinutesAgo = new Date(now.getTime() - 5 * 60_000).toISOString();
        expect(pipe.transform(fiveMinutesAgo, strings)).toBe('5 min ago');
    });

    it('returns minutesAgo for a date 59 minutes ago', () => {
        const now = new Date('2026-04-06T12:00:00Z');
        vi.setSystemTime(now);
        const fiftyNineMinutesAgo = new Date(now.getTime() - 59 * 60_000).toISOString();
        expect(pipe.transform(fiftyNineMinutesAgo, strings)).toBe('59 min ago');
    });

    it('returns hoursAgo for a date 2 hours ago', () => {
        const now = new Date('2026-04-06T12:00:00Z');
        vi.setSystemTime(now);
        const twoHoursAgo = new Date(now.getTime() - 2 * 3_600_000).toISOString();
        expect(pipe.transform(twoHoursAgo, strings)).toBe('2h ago');
    });

    it('returns hoursAgo for a date 23 hours ago', () => {
        const now = new Date('2026-04-06T12:00:00Z');
        vi.setSystemTime(now);
        const twentyThreeHoursAgo = new Date(now.getTime() - 23 * 3_600_000).toISOString();
        expect(pipe.transform(twentyThreeHoursAgo, strings)).toBe('23h ago');
    });

    it('returns daysAgo for a date 3 days ago', () => {
        const now = new Date('2026-04-06T12:00:00Z');
        vi.setSystemTime(now);
        const threeDaysAgo = new Date(now.getTime() - 3 * 86_400_000).toISOString();
        expect(pipe.transform(threeDaysAgo, strings)).toBe('3d ago');
    });

    it('interpolates {n} with the actual number', () => {
        const now = new Date('2026-04-06T12:00:00Z');
        vi.setSystemTime(now);
        const tenMinutesAgo = new Date(now.getTime() - 10 * 60_000).toISOString();
        const result = pipe.transform(tenMinutesAgo, strings);
        expect(result).toContain('10');
        expect(result).not.toContain('{n}');
    });
});
