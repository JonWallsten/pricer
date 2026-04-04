import { Injectable, signal, computed } from '@angular/core';

export type Lang = 'en' | 'sv';

interface I18nStrings {
    // General
    appName: string;
    login: string;
    logout: string;
    save: string;
    cancel: string;
    delete: string;
    edit: string;
    add: string;
    back: string;
    loading: string;
    error: string;
    success: string;
    confirm: string;
    yes: string;
    no: string;
    required: string;

    // Auth
    loginWithGoogle: string;
    loginSubtitle: string;
    loggedInAs: string;

    // Products
    products: string;
    addProduct: string;
    editProduct: string;
    productName: string;
    productUrl: string;
    cssSelector: string;
    cssSelectorHint: string;
    currentPrice: string;
    lastChecked: string;
    checkNow: string;
    checking: string;
    noProducts: string;
    noProductsHint: string;
    deleteProductConfirm: string;
    testExtraction: string;
    extractionMethod: string;
    priceFound: string;
    priceNotFound: string;
    statusPending: string;
    statusSuccess: string;
    statusError: string;

    // Alerts
    alerts: string;
    addAlert: string;
    targetPrice: string;
    activeAlerts: string;
    noAlerts: string;
    alertTriggered: string;
    alertActive: string;
    alertInactive: string;
    deleteAlertConfirm: string;
    lastNotified: string;

    // Misc
    toggleTheme: string;
    currency: string;
    neverChecked: string;
    justNow: string;
    minutesAgo: string;
    hoursAgo: string;
    daysAgo: string;
    invalidUrl: string;

    // Price history
    priceHistory: string;
    week: string;
    month: string;
    threeMonths: string;
    year: string;
    all: string;
    noHistory: string;

    // Preview / auto-fetch
    fetchingPrice: string;
    previewPrice: string;
    autoDetected: string;

    // Alerts — discount chips
    notifyWhenDropsTo: string;
    discount: string;
    off: string;

    // Availability
    inStock: string;
    outOfStock: string;
    preorder: string;
    availabilityUnknown: string;
    notifyBackInStock: string;
    backInStock: string;

    // Admin / approval
    pendingApproval: string;
    pendingApprovalMessage: string;
    adminUsers: string;
    approve: string;
    reject: string;
    approved: string;
    pending: string;
    admin: string;
    userEmail: string;
    userJoined: string;
    userLastLogin: string;

    // Multi-URL / Sites
    sites: string;
    nSites: string;
    addUrl: string;
    removeUrl: string;
    checkAll: string;
    lowestPrice: string;
    site: string;

    // Cross-store matches
    otherStores: string;
    findMatches: string;
    refreshMatches: string;
    matchesUpdated: string;
    noMatches: string;
    noMatchesHint: string;
    matchConfidence: string;
    matchVeryLikely: string;
    matchLikely: string;
    matchPossible: string;
    matchWeak: string;
    matchReasons: string;
    queryUsed: string;
    discoveryUnavailable: string;
}

const en: I18nStrings = {
    appName: 'Pricer',
    login: 'Log in',
    logout: 'Log out',
    save: 'Save',
    cancel: 'Cancel',
    delete: 'Delete',
    edit: 'Edit',
    add: 'Add',
    back: 'Back',
    loading: 'Loading…',
    error: 'Something went wrong',
    success: 'Done!',
    confirm: 'Confirm',
    yes: 'Yes',
    no: 'No',
    required: 'Required',

    loginWithGoogle: 'Sign in with Google',
    loginSubtitle: 'Track prices and get notified when they drop',
    loggedInAs: 'Logged in as',

    products: 'Products',
    addProduct: 'Add product',
    editProduct: 'Edit product',
    productName: 'Product name',
    productUrl: 'Product URL',
    cssSelector: 'CSS selector',
    cssSelectorHint: 'Optional. Used as fallback if automatic price detection fails.',
    currentPrice: 'Current price',
    lastChecked: 'Last checked',
    checkNow: 'Check now',
    checking: 'Checking…',
    noProducts: 'No products yet',
    noProductsHint: 'Add a product URL to start tracking its price.',
    deleteProductConfirm: 'Delete this product and all its alerts?',
    testExtraction: 'Test price extraction',
    extractionMethod: 'Detection method',
    priceFound: 'Price found',
    priceNotFound: 'No price found',
    statusPending: 'Pending',
    statusSuccess: 'OK',
    statusError: 'Error',

    alerts: 'Alerts',
    addAlert: 'Add alert',
    targetPrice: 'Target price',
    activeAlerts: 'active alerts',
    noAlerts: 'No alerts yet',
    alertTriggered: 'Triggered',
    alertActive: 'Active',
    alertInactive: 'Paused',
    deleteAlertConfirm: 'Delete this alert?',
    lastNotified: 'Last notified',

    toggleTheme: 'Toggle theme',
    currency: 'SEK',
    neverChecked: 'Never checked',
    justNow: 'Just now',
    minutesAgo: '{n} min ago',
    hoursAgo: '{n}h ago',
    daysAgo: '{n}d ago',
    invalidUrl: 'Enter a valid URL',

    priceHistory: 'Price history',
    week: 'Week',
    month: 'Month',
    threeMonths: '3 months',
    year: 'Year',
    all: 'All',
    noHistory: 'No price history yet',

    fetchingPrice: 'Fetching price…',
    previewPrice: 'Preview',
    autoDetected: 'Auto-detected',

    notifyWhenDropsTo: 'Notify when price drops to',
    discount: 'discount',
    off: 'off',

    inStock: 'In stock',
    outOfStock: 'Out of stock',
    preorder: 'Pre-order',
    availabilityUnknown: 'Unknown',
    notifyBackInStock: 'Notify when back in stock',
    backInStock: 'Back in stock',

    pendingApproval: 'Pending approval',
    pendingApprovalMessage:
        'Your account is waiting for admin approval. You will be able to use Pricer once approved.',
    adminUsers: 'Manage users',
    approve: 'Approve',
    reject: 'Reject',
    approved: 'Approved',
    pending: 'Pending',
    admin: 'Admin',
    userEmail: 'Email',
    userJoined: 'Joined',
    userLastLogin: 'Last login',

    sites: 'Sites',
    nSites: '{n} sites',
    addUrl: 'Add URL',
    removeUrl: 'Remove URL',
    checkAll: 'Check all',
    lowestPrice: 'Lowest price',
    site: 'Site',
    otherStores: 'Also sold at',
    findMatches: 'Find matching products',
    refreshMatches: 'Refresh matches',
    matchesUpdated: 'Updated',
    noMatches: 'No matching products found yet',
    noMatchesHint: 'Run discovery to search for likely matches at other stores.',
    matchConfidence: 'Match',
    matchVeryLikely: 'Very likely',
    matchLikely: 'Likely',
    matchPossible: 'Possible',
    matchWeak: 'Weak',
    matchReasons: 'Why this matched',
    queryUsed: 'Query',
    discoveryUnavailable: 'Discovery is unavailable until SerpApi is configured.',
};

const sv: I18nStrings = {
    appName: 'Pricer',
    login: 'Logga in',
    logout: 'Logga ut',
    save: 'Spara',
    cancel: 'Avbryt',
    delete: 'Ta bort',
    edit: 'Redigera',
    add: 'Lägg till',
    back: 'Tillbaka',
    loading: 'Laddar…',
    error: 'Något gick fel',
    success: 'Klart!',
    confirm: 'Bekräfta',
    yes: 'Ja',
    no: 'Nej',
    required: 'Obligatoriskt',

    loginWithGoogle: 'Logga in med Google',
    loginSubtitle: 'Bevaka priser och bli notifierad när de sjunker',
    loggedInAs: 'Inloggad som',

    products: 'Produkter',
    addProduct: 'Lägg till produkt',
    editProduct: 'Redigera produkt',
    productName: 'Produktnamn',
    productUrl: 'Produktlänk',
    cssSelector: 'CSS-selektor',
    cssSelectorHint: 'Valfritt. Används som reserv om automatisk prisdetektering misslyckas.',
    currentPrice: 'Nuvarande pris',
    lastChecked: 'Senast kontrollerat',
    checkNow: 'Kontrollera nu',
    checking: 'Kontrollerar…',
    noProducts: 'Inga produkter ännu',
    noProductsHint: 'Lägg till en produktlänk för att börja bevaka priset.',
    deleteProductConfirm: 'Ta bort denna produkt och alla dess bevakningar?',
    testExtraction: 'Testa prisextraktion',
    extractionMethod: 'Detekteringsmetod',
    priceFound: 'Pris hittat',
    priceNotFound: 'Inget pris hittat',
    statusPending: 'Väntar',
    statusSuccess: 'OK',
    statusError: 'Fel',

    alerts: 'Bevakningar',
    addAlert: 'Lägg till bevakning',
    targetPrice: 'Målpris',
    activeAlerts: 'aktiva bevakningar',
    noAlerts: 'Inga bevakningar ännu',
    alertTriggered: 'Utlöst',
    alertActive: 'Aktiv',
    alertInactive: 'Pausad',
    deleteAlertConfirm: 'Ta bort denna bevakning?',
    lastNotified: 'Senast notifierad',

    toggleTheme: 'Byt tema',
    currency: 'SEK',
    neverChecked: 'Aldrig kontrollerat',
    justNow: 'Precis nu',
    minutesAgo: '{n} min sedan',
    hoursAgo: '{n}t sedan',
    daysAgo: '{n}d sedan',
    invalidUrl: 'Ange en giltig URL',

    priceHistory: 'Prishistorik',
    week: 'Vecka',
    month: 'Månad',
    threeMonths: '3 månader',
    year: 'År',
    all: 'Alla',
    noHistory: 'Ingen prishistorik ännu',

    fetchingPrice: 'Hämtar pris…',
    previewPrice: 'Förhandsvisning',
    autoDetected: 'Automatiskt hämtat',

    notifyWhenDropsTo: 'Meddela när priset sjunker till',
    discount: 'rabatt',
    off: 'av',

    inStock: 'I lager',
    outOfStock: 'Slut i lager',
    preorder: 'Förbeställning',
    availabilityUnknown: 'Okänt',
    notifyBackInStock: 'Meddela när åter i lager',
    backInStock: 'Åter i lager',

    pendingApproval: 'Väntar på godkännande',
    pendingApprovalMessage:
        'Ditt konto väntar på adminens godkännande. Du kan använda Pricer när kontot godkänts.',
    adminUsers: 'Hantera användare',
    approve: 'Godkänn',
    reject: 'Avvisa',
    approved: 'Godkänd',
    pending: 'Väntar',
    admin: 'Admin',
    userEmail: 'E-post',
    userJoined: 'Registrerad',
    userLastLogin: 'Senaste inloggning',

    sites: 'Sidor',
    nSites: '{n} sidor',
    addUrl: 'Lägg till URL',
    removeUrl: 'Ta bort URL',
    checkAll: 'Kontrollera alla',
    lowestPrice: 'Lägsta pris',
    site: 'Sida',
    otherStores: 'Finns även hos',
    findMatches: 'Hitta matchande produkter',
    refreshMatches: 'Uppdatera matcher',
    matchesUpdated: 'Uppdaterad',
    noMatches: 'Inga matchande produkter hittades ännu',
    noMatchesHint: 'Kör sökningen för att leta efter troliga matcher hos andra butiker.',
    matchConfidence: 'Match',
    matchVeryLikely: 'Mycket trolig',
    matchLikely: 'Trolig',
    matchPossible: 'Möjlig',
    matchWeak: 'Svag',
    matchReasons: 'Varför den matchar',
    queryUsed: 'Sökfråga',
    discoveryUnavailable: 'Matchsökning är inte tillgänglig förrän SerpApi är konfigurerat.',
};

const translations: Record<Lang, I18nStrings> = { en, sv };

@Injectable({ providedIn: 'root' })
export class I18nService {
    readonly lang = signal<Lang>(this.detectLang());
    readonly strings = computed(() => translations[this.lang()]);

    setLang(lang: Lang) {
        this.lang.set(lang);
    }

    private detectLang(): Lang {
        const nav = navigator.language?.toLowerCase() ?? '';
        return nav.startsWith('sv') ? 'sv' : 'en';
    }
}
