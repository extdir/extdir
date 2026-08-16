<?php

declare(strict_types=1);

namespace App\Taxonomy;

/**
 * extdir's category taxonomy and the terms that assign it.
 *
 * This is deliberately our own list rather than a mirror of the official Shopware
 * Store tree: copying that would couple the directory to someone else's product
 * decisions and edge closer to the trademark caution in docs/brief.md §4.5.
 *
 * Assignment is deterministic keyword matching, not classification by model. The
 * same principle that governs ranking (§4.6 — the algorithm is public) applies
 * here: a maintainer who disagrees with their category should be able to read the
 * rule, see exactly which term matched, and send a one-line pull request. That is
 * not possible with a probabilistic classifier, and category disputes are support
 * load we would rather answer with a link than a shrug.
 *
 * German terms sit alongside English throughout. The Shopware ecosystem is heavily
 * German and a large share of plugins carry German-only metadata, so an
 * English-only rule set would leave much of the corpus uncategorised.
 */
final class CategoryDefinition
{
    /**
     * key => [label, description, terms].
     *
     * @var array<string, array{string, string, list<string>}>
     */
    private const CATEGORIES = [
        'payment' => ['Payment', 'Payment methods and payment service provider integrations', [
            'payment', 'zahlung', 'zahlart', 'paypal', 'stripe', 'klarna', 'mollie', 'adyen',
            'sepa', 'lastschrift', 'ratepay', 'unzer', 'payone', 'saferpay', 'sofortueberweisung',
            'creditcard', 'kreditkarte', 'giropay', 'bezahl',
        ]],
        'shipping' => ['Shipping', 'Carriers, shipping costs and delivery options', [
            'shipping', 'versand', 'delivery', 'lieferung', 'dhl', 'dpd', 'hermes', 'gls',
            'ups', 'fedex', 'parcel', 'paket', 'sendcloud', 'shipcloud', 'packstation',
            'lieferzeit', 'versandkosten', 'click and collect', 'abholung', 'pickup', 'filiale',
        ]],
        'checkout' => ['Checkout & Cart', 'Cart behaviour and the checkout process', [
            'checkout', 'warenkorb', 'basket', 'kasse', 'bestellabschluss', 'cart', 'surcharge', 'aufschlag', 'zuschlag', 'bestellung',
        ]],
        'seo' => ['SEO', 'Search engine optimisation, sitemaps and structured data', [
            'seo', 'sitemap', 'canonical', 'metatag', 'meta-tag', 'structured data',
            'rich snippet', 'schema.org', 'redirect', 'weiterleitung', 'suchmaschine',
        ]],
        'marketing' => ['Marketing & Promotions', 'Newsletters, vouchers, discounts and campaigns', [
            'marketing', 'newsletter', 'promotion', 'voucher', 'gutschein', 'discount',
            'rabatt', 'coupon', 'campaign', 'kampagne', 'mailchimp', 'cleverreach', 'klaviyo',
            'werbung', 'affiliate', 'reward', 'loyalty', 'treuepunkt', 'bonuspunkt', 'wishlist',
            'merkzettel',
        ]],
        'automation' => ['Automation & Flow', 'Flow Builder actions, scheduled tasks and workflow automation', [
            'flow builder', 'flowbuilder', 'automation', 'automatisierung', 'workflow',
            'scheduled task', 'geplante aufgabe', 'trigger', 'rule builder', 'rulebuilder',
        ]],
        'analytics' => ['Analytics & Tracking', 'Web analytics, tag managers and conversion tracking', [
            'analytics', 'tracking', 'tagmanager', 'tag manager', 'matomo', 'piwik',
            'statistik', 'statistics', 'conversion', 'pixel', 'hotjar', 'clarity',
        ]],
        'erp' => ['ERP & Business Systems', 'ERP, PIM and merchandise management integrations', [
            'erp', 'warenwirtschaft', 'datev', 'sage', 'jtl', 'plentymarkets', 'xentral',
            'weclapp', 'lexoffice', 'sap', 'dynamics', 'odoo', 'pim', 'afterbuy', 'billbee',
        ]],
        'product' => ['Products & Catalog', 'Product data, variants, properties and catalog structure', [
            'product', 'artikel', 'catalog', 'katalog', 'variant', 'variante', 'properties',
            'eigenschaften', 'crossselling', 'cross-selling', 'produktdaten', 'sortiment',
        ]],
        'cms' => ['Content & CMS', 'Shopping experiences, blogs and content management', [
            'cms', 'shopping experience', 'erlebniswelt', 'content', 'blog', 'landingpage',
            'landing page', 'inhalt', 'wysiwyg', 'seitenbaukasten',
        ]],
        'search' => ['Search', 'Storefront search, autocomplete and search providers', [
            'search', 'suche', 'elasticsearch', 'opensearch', 'autocomplete', 'findologic',
            'doofinder', 'algolia', 'suchergebnis', 'autosuggest',
        ]],
        'customer' => ['Customers & Accounts', 'Registration, customer accounts and address handling', [
            'customer', 'kunde', 'konto', 'registration', 'registrierung',
            'address', 'adresse', 'login', 'anmeldung', 'kundenkonto', 'customer account', 'salutation', 'anrede', 'newsletteranmeldung', ]],
        'b2b' => ['B2B', 'Wholesale, business customers and quotes', [
            'b2b', 'wholesale', 'grosshandel', 'großhandel', 'businesscustomer',
            'firmenkunde', 'angebot', 'quote', 'staffelpreis',
        ]],
        'admin' => ['Administration', 'Backend tools and administration usability', [
            'administration', 'backend', 'dashboard', 'adminui', 'admin-ui', 'queue', 'monitor', 'healthcheck', 'health check', 'maintenance', 'wartung', 'logging', 'protokoll', ]],
        'developer' => ['Developer Tools', 'Debugging, profiling, migration and developer utilities', [
            'developer', 'debug', 'profiler', 'devtools', 'migration', 'testing', 'phpunit',
            'entwickler', 'boilerplate', 'skeleton', 'scaffold', 'fixture', 'demodata', 'demo data', 'querybuilder', 'query builder', 'sandbox', 'example', 'beispiel', ]],
        'import-export' => ['Import & Export', 'Data import, export and product feeds', [
            'import', 'export', 'feed', 'datenaustausch', 'productfeed', 'csvimport',
            'schnittstelle', 'sync', 'synchronisation', 'synchronization', 'datenimport', 'datenexport', ]],
        'legal' => ['Legal & Compliance', 'GDPR, cookie consent and legally required texts', [
            'dsgvo', 'gdpr', 'cookie', 'consent', 'datenschutz', 'impressum', 'widerruf',
            'rechtstext', 'agb', 'legal', 'compliance', 'trusted shops', 'ccpa',
        ]],
        'tax' => ['Tax & Accounting', 'VAT handling, invoices and accounting exports', [
            'steuer', 'vat', 'ust', 'oss', 'invoice', 'rechnung', 'accounting',
            'buchhaltung', 'gobd', 'umsatzsteuer', 'taxation',
        ]],
        'media' => ['Media & Images', 'Image handling, galleries, video and CDN delivery', [
            'media', 'image', 'bild', 'video', 'gallery', 'galerie', 'thumbnail', 'webp',
            'avif', 'cdn', 'lazyload', 'lazy load',
        ]],
        'performance' => ['Performance', 'Caching, asset optimisation and page speed', [
            'performance', 'cache', 'caching', 'varnish', 'redis', 'pagespeed', 'optimization',
            'optimierung', 'preload', 'geschwindigkeit', 'http2',
        ]],
        'notification' => ['Email & Notifications', 'Transactional mail and customer notifications', [
            'email', 'mail', 'notification', 'benachrichtigung', 'sms', 'whatsapp',
            'mailtemplate', 'transactional', 'smtp',
        ]],
        'reviews' => ['Reviews & Ratings', 'Product reviews, ratings and review platforms', [
            'review', 'bewertung', 'rating', 'testimonial', 'trustpilot', 'ekomi',
            'sternebewertung',
        ]],
        'i18n' => ['Languages & Currencies', 'Translations, multi-language and currency handling', [
            'language', 'sprache', 'translation', 'ubersetzung', 'übersetzung', 'currency',
            'wahrung', 'währung', 'locale', 'multilanguage', 'deepl',
        ]],
        'storefront' => ['Storefront & Themes', 'Themes, layout and storefront presentation', [
            'theme', 'storefront', 'design', 'layout', 'template', 'darkmode', 'bootstrap',
            'frontend', 'fonts', 'webfont', 'favicon', 'accessibility', 'barrierefrei', 'wcag', 'cookiebanner', ]],
        'security' => ['Security', 'Spam protection, captcha and hardening', [
            'security', 'sicherheit', 'captcha', 'recaptcha', 'friendlycaptcha', 'spam',
            'firewall', 'bruteforce', 'twofactor', '2fa', 'honeypot',
        ]],
        'marketplace' => ['Marketplaces & Channels', 'Amazon, eBay and other sales channels', [
            'amazon', 'ebay', 'marketplace', 'marktplatz', 'salesChannel', 'googleshopping',
            'idealo', 'kaufland', 'otto', 'channel',
        ]],
        'subscription' => ['Subscriptions', 'Recurring orders, subscriptions and memberships', [
            'subscription', 'abonnement', 'recurring', 'membership', 'mitgliedschaft',
        ]],
        'inventory' => ['Inventory & Stock', 'Stock levels, warehousing and availability', [
            'stock', 'lager', 'inventory', 'bestand', 'warehouse', 'verfugbarkeit',
            'verfügbarkeit', 'lieferbarkeit',
        ]],
    ];

    /**
     * @return array<string, array{string, string, list<string>}>
     */
    public static function all(): array
    {
        return self::CATEGORIES;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::CATEGORIES);
    }
}
