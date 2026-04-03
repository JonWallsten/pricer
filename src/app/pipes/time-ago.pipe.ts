import { Pipe, PipeTransform } from '@angular/core';

interface TimeAgoStrings {
    justNow: string;
    minutesAgo: string;
    hoursAgo: string;
    daysAgo: string;
}

@Pipe({ name: 'timeAgo' })
export class TimeAgoPipe implements PipeTransform {
    transform(value: string | null | undefined, strings: TimeAgoStrings): string {
        if (!value) return '';

        const date = new Date(value);
        const now = Date.now();
        const diffMs = now - date.getTime();
        const diffMin = Math.floor(diffMs / 60_000);
        const diffHrs = Math.floor(diffMs / 3_600_000);
        const diffDays = Math.floor(diffMs / 86_400_000);

        if (diffMin < 1) return strings.justNow;
        if (diffMin < 60) return strings.minutesAgo.replace('{n}', String(diffMin));
        if (diffHrs < 24) return strings.hoursAgo.replace('{n}', String(diffHrs));
        return strings.daysAgo.replace('{n}', String(diffDays));
    }
}
