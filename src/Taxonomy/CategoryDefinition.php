<?php

declare(strict_types=1);

namespace App\Taxonomy;

/**
 * extdir's category taxonomy and the terms that assign it.
 *
 * This is deliberately our own list rather than a mirror of the official Shopware
 * Store tree: copying that would couple the directory to someone else's product
 * decisions and edge closer to the trademark caution.
 *
 * Assignment is deterministic keyword matching, not classification by model. The
 * same principle that governs ranking (the conflict-of-interest rule — the algorithm is public) applies
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
     * key => [label, description, generic terms, strong terms].
     *
     * The two term lists differ only in how much weight one match carries when it is
     * found nowhere but the description. A **strong** term is a proper noun that means
     * exactly one thing — `klarna`, `dhl`, `datev`, `turnstile`. A description that
     * names one of those is about that subject. A **generic** term is an ordinary word
     * that is merely indicative: `payment`, `content`, `monitor`. One of those in a
     * description proves nothing, because "works alongside your payment provider" is
     * not a payment extension.
     *
     * Every term appears in exactly one of the two lists. Deliberately not a separate
     * catalogue of strong terms sitting beside this one: two lists of the same strings
     * drift, and the copy nobody remembers to update is the one that decides.
     *
     * What is kept out is as considered as what is in. `ideal` (the payment method)
     * would match "ideal for developers"; `lightning` (the Bitcoin network) would match
     * "lightning fast"; `oss` is One-Stop-Shop here and open-source software everywhere
     * else; `sage`, `ups`, `otto` and `dynamics` are all ordinary words before they are
     * companies. Those stay generic or stay out.
     *
     * @var array<string, array{string, string, list<string>, list<string>}>
     */
    private const CATEGORIES = [
        'payment' => ['Payment', 'Payment methods and payment service provider integrations', [
            'payment', 'zahlung', 'zahlart', 'lastschrift', 'creditcard', 'kreditkarte', 'bezahl',
        ], [
            'paypal', 'stripe', 'klarna', 'mollie', 'adyen', 'ratepay', 'unzer', 'payone',
            'saferpay', 'sofortueberweisung', 'giropay', 'sepa', 'alipay', 'bitcoin',
        ]],
        'shipping' => ['Shipping', 'Carriers, shipping costs and delivery options', [
            'shipping', 'versand', 'delivery', 'lieferung', 'ups', 'hermes', 'parcel', 'paket',
            'lieferzeit', 'abholung', 'pickup', 'filiale',
            'versandkosten', 'click and collect',
        ], [
            'dhl', 'dpd', 'gls', 'fedex', 'sendcloud', 'shipcloud', 'packstation',
        ]],
        'checkout' => ['Checkout & Cart', 'Cart behaviour and the checkout process', [
            'checkout', 'warenkorb', 'basket', 'kasse', 'bestellabschluss', 'cart', 'surcharge',
            'aufschlag', 'zuschlag', 'bestellung',
        ], []],
        'seo' => ['SEO', 'Search engine optimisation, sitemaps and structured data', [
            'seo', 'sitemap', 'canonical', 'redirect', 'weiterleitung', 'suchmaschine',
            'metatag', 'meta-tag', 'structured data', 'rich snippet', 'schema.org',
        ], [
        ]],
        'marketing' => ['Marketing & Promotions', 'Newsletters, vouchers, discounts and campaigns', [
            'marketing', 'newsletter', 'promotion', 'voucher', 'gutschein', 'discount',
            'rabatt', 'coupon', 'campaign', 'kampagne', 'werbung', 'affiliate', 'reward',
            'loyalty', 'treuepunkt', 'bonuspunkt', 'wishlist', 'merkzettel',
        ], [
            'mailchimp', 'cleverreach', 'klaviyo',
        ]],
        'automation' => ['Automation & Flow', 'Flow Builder actions, scheduled tasks and workflow automation', [
            'automation', 'automatisierung', 'workflow', 'trigger',
        ], [
            'flow builder', 'flowbuilder', 'scheduled task', 'geplante aufgabe', 'rule builder',
            'rulebuilder',
        ]],
        'analytics' => ['Analytics & Tracking', 'Web analytics, tag managers and conversion tracking', [
            'analytics', 'tracking', 'statistik', 'statistics', 'conversion', 'pixel', 'clarity',
        ], [
            'tagmanager', 'tag manager', 'matomo', 'piwik', 'hotjar',
        ]],
        'erp' => ['ERP & Business Systems', 'ERP, PIM and merchandise management integrations', [
            'erp', 'warenwirtschaft', 'sage', 'sap', 'dynamics', 'pim',
        ], [
            'datev', 'jtl', 'plentymarkets', 'xentral', 'weclapp', 'lexoffice', 'odoo',
            'afterbuy', 'billbee',
        ]],
        'product' => ['Products & Catalog', 'Product data, variants, properties and catalog structure', [
            'product', 'artikel', 'catalog', 'katalog', 'variant', 'variante', 'properties',
            'eigenschaften', 'sortiment',
            'crossselling', 'cross-selling', 'produktdaten',
        ], [
        ]],
        'cms' => ['Content & CMS', 'Shopping experiences, blogs and content management', [
            'cms', 'content', 'blog', 'landingpage', 'landing page', 'inhalt',
            // English marketing prose, not the Shopware feature: "creates shopping
            // experiences", "a smooth shopping experience". It filed a financing
            // plugin and a translation plugin under Content before it was demoted.
            'shopping experience',
        ], [
            'erlebniswelt', 'wysiwyg', 'seitenbaukasten',
        ]],
        'search' => ['Search', 'Storefront search, autocomplete and search providers', [
            'search', 'suche', 'autocomplete', 'suchergebnis', 'autosuggest',
        ], [
            'elasticsearch', 'opensearch', 'findologic', 'doofinder', 'algolia',
        ]],
        'customer' => ['Customers & Accounts', 'Registration, customer accounts and address handling', [
            'customer', 'kunde', 'konto', 'registration', 'registrierung', 'address', 'adresse',
            'login', 'anmeldung', 'salutation', 'anrede',
            'kundenkonto', 'customer account', 'newsletteranmeldung',
        ], [
        ]],
        'b2b' => ['B2B', 'Wholesale, business customers and quotes', [
            'b2b', 'wholesale', 'grosshandel', 'großhandel', 'angebot', 'quote',
            'businesscustomer', 'firmenkunde', 'staffelpreis',
        ], [
        ]],
        'admin' => ['Administration', 'Backend tools and administration usability', [
            'administration', 'backend', 'dashboard', 'queue', 'monitor', 'maintenance',
            'wartung', 'logging', 'protokoll',
            'adminui', 'admin-ui', 'healthcheck', 'health check',
        ], [
        ]],
        'developer' => ['Developer Tools', 'Debugging, profiling, migration and developer utilities', [
            'developer', 'debug', 'profiler', 'migration', 'testing', 'entwickler', 'fixture',
            'sandbox', 'example', 'beispiel',
            'boilerplate', 'skeleton', 'scaffold', 'demodata', 'demo data', 'querybuilder', 'query builder',
        ], [
            'devtools', 'phpunit',
        ]],
        'import-export' => ['Import & Export', 'Data import, export and product feeds', [
            'import', 'export', 'feed', 'schnittstelle', 'sync', 'synchronisation',
            'synchronization',
            'datenaustausch', 'productfeed', 'csvimport', 'datenimport', 'datenexport',
        ], [
        ]],
        'legal' => ['Legal & Compliance', 'GDPR, cookie consent and legally required texts', [
            'cookie', 'consent', 'legal', 'compliance',
            'dsgvo', 'gdpr', 'datenschutz',
        ], [
            'impressum', 'widerruf', 'rechtstext', 'agb',
            'trusted shops', 'ccpa',
        ]],
        'tax' => ['Tax & Accounting', 'VAT handling, invoices and accounting exports', [
            'steuer', 'vat', 'ust', 'oss', 'invoice', 'rechnung', 'accounting', 'buchhaltung',
            'taxation',
        ], [
            'gobd', 'umsatzsteuer',
        ]],
        'media' => ['Media & Images', 'Image handling, galleries, video and CDN delivery', [
            'media', 'image', 'bild', 'video', 'gallery', 'galerie', 'thumbnail', 'cdn',
        ], [
            'webp', 'avif', 'lazyload', 'lazy load',
        ]],
        'performance' => ['Performance', 'Caching, asset optimisation and page speed', [
            'performance', 'cache', 'caching', 'optimization', 'optimierung', 'preload',
            'geschwindigkeit',
        ], [
            'varnish', 'redis', 'pagespeed', 'http2',
        ]],
        'notification' => ['Email & Notifications', 'Transactional mail and customer notifications', [
            'email', 'mail', 'notification', 'benachrichtigung', 'sms', 'transactional',
        ], [
            'whatsapp', 'mailtemplate', 'smtp',
        ]],
        'reviews' => ['Reviews & Ratings', 'Product reviews, ratings and review platforms', [
            'review', 'bewertung', 'rating', 'testimonial',
        ], [
            'trustpilot', 'ekomi', 'sternebewertung',
        ]],
        'i18n' => ['Languages & Currencies', 'Translations, multi-language and currency handling', [
            'language', 'sprache', 'translation', 'ubersetzung', 'übersetzung', 'currency',
            'wahrung', 'währung', 'locale',
        ], [
            'multilanguage', 'deepl',
        ]],
        'storefront' => ['Storefront & Themes', 'Themes, layout and storefront presentation', [
            'theme', 'storefront', 'design', 'layout', 'template', 'bootstrap', 'frontend',
            'fonts', 'accessibility', 'barrierefrei',
            'cookiebanner',
        ], [
            'darkmode', 'webfont', 'favicon', 'wcag',
        ]],
        'security' => ['Security', 'Spam protection, captcha and hardening', [
            'security', 'sicherheit', 'spam', 'firewall',
            'fraud',
        ], [
            'captcha', 'recaptcha', 'friendlycaptcha', 'hcaptcha', 'turnstile', 'bruteforce',
            'twofactor', '2fa', 'honeypot',
        ]],
        'marketplace' => ['Marketplaces & Channels', 'Amazon, eBay and other sales channels', [
            'marketplace', 'marktplatz', 'saleschannel', 'channel', 'otto',
        ], [
            'amazon', 'ebay', 'googleshopping', 'idealo', 'kaufland',
        ]],
        'subscription' => ['Subscriptions', 'Recurring orders, subscriptions and memberships', [
            'subscription', 'abonnement', 'recurring', 'membership', 'mitgliedschaft',
        ], []],
        'inventory' => ['Inventory & Stock', 'Stock levels, warehousing and availability', [
            'stock', 'lager', 'inventory', 'bestand', 'warehouse', 'verfugbarkeit',
            'verfügbarkeit', 'lieferbarkeit',
        ], []],
    ];

    /**
     * @return array<string, array{string, string, list<string>, list<string>}>
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
