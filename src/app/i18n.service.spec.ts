import { TestBed } from '@angular/core/testing';
import { I18nService } from './i18n.service';

describe('I18nService', () => {
    let service: I18nService;

    beforeEach(() => {
        TestBed.configureTestingModule({});
        service = TestBed.inject(I18nService);
    });

    afterEach(() => {
        TestBed.resetTestingModule();
    });

    describe('setLang / lang signal', () => {
        it('switches to English', () => {
            service.setLang('en');
            expect(service.lang()).toBe('en');
        });

        it('switches to Swedish', () => {
            service.setLang('sv');
            expect(service.lang()).toBe('sv');
        });
    });

    describe('locale computed', () => {
        it('returns en-US for English', () => {
            service.setLang('en');
            expect(service.locale()).toBe('en-US');
        });

        it('returns sv-SE for Swedish', () => {
            service.setLang('sv');
            expect(service.locale()).toBe('sv-SE');
        });
    });

    describe('strings computed – English', () => {
        beforeEach(() => service.setLang('en'));

        it('returns correct appName', () => {
            expect(service.strings().appName).toBe('Pricer');
        });

        it('returns correct save label', () => {
            expect(service.strings().save).toBe('Save');
        });

        it('returns correct currency', () => {
            expect(service.strings().currency).toBe('SEK');
        });

        it('contains {n} placeholder in minutesAgo', () => {
            expect(service.strings().minutesAgo).toContain('{n}');
        });

        it('contains {n} placeholder in hoursAgo', () => {
            expect(service.strings().hoursAgo).toContain('{n}');
        });

        it('contains {n} placeholder in daysAgo', () => {
            expect(service.strings().daysAgo).toContain('{n}');
        });

        it('contains {count} placeholder in selectorMatches', () => {
            expect(service.strings().selectorMatches).toContain('{count}');
        });
    });

    describe('strings computed – Swedish', () => {
        beforeEach(() => service.setLang('sv'));

        it('returns translated save label', () => {
            expect(service.strings().save).toBe('Spara');
        });

        it('returns translated cancel label', () => {
            expect(service.strings().cancel).toBe('Avbryt');
        });

        it('returns translated delete label', () => {
            expect(service.strings().delete).toBe('Ta bort');
        });

        it('contains {n} placeholder in minutesAgo', () => {
            expect(service.strings().minutesAgo).toContain('{n}');
        });
    });

    describe('strings completeness', () => {
        it('has all required keys in English', () => {
            service.setLang('en');
            const s = service.strings();
            expect(typeof s.appName).toBe('string');
            expect(typeof s.login).toBe('string');
            expect(typeof s.logout).toBe('string');
            expect(typeof s.products).toBe('string');
            expect(typeof s.alerts).toBe('string');
            expect(typeof s.priceHistory).toBe('string');
        });

        it('has all required keys in Swedish', () => {
            service.setLang('sv');
            const s = service.strings();
            expect(typeof s.appName).toBe('string');
            expect(typeof s.login).toBe('string');
            expect(typeof s.logout).toBe('string');
            expect(typeof s.products).toBe('string');
            expect(typeof s.alerts).toBe('string');
            expect(typeof s.priceHistory).toBe('string');
        });
    });
});

describe('I18nService – detectLang', () => {
    afterEach(() => {
        TestBed.resetTestingModule();
        Object.defineProperty(navigator, 'language', {
            value: 'en',
            configurable: true,
        });
    });

    it('detects Swedish when navigator.language is sv-SE', () => {
        Object.defineProperty(navigator, 'language', {
            value: 'sv-SE',
            configurable: true,
        });
        TestBed.configureTestingModule({});
        const service = TestBed.inject(I18nService);
        expect(service.lang()).toBe('sv');
    });

    it('defaults to English when navigator.language is en-US', () => {
        Object.defineProperty(navigator, 'language', {
            value: 'en-US',
            configurable: true,
        });
        TestBed.configureTestingModule({});
        const service = TestBed.inject(I18nService);
        expect(service.lang()).toBe('en');
    });

    it('defaults to English for unknown locales', () => {
        Object.defineProperty(navigator, 'language', {
            value: 'fr-FR',
            configurable: true,
        });
        TestBed.configureTestingModule({});
        const service = TestBed.inject(I18nService);
        expect(service.lang()).toBe('en');
    });
});
