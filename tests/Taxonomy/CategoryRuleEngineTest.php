<?php

declare(strict_types=1);

namespace App\Tests\Taxonomy;

use App\Taxonomy\CategoryDefinition;
use App\Taxonomy\CategoryRuleEngine;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CategoryRuleEngine::class)]
#[CoversClass(CategoryDefinition::class)]
final class CategoryRuleEngineTest extends TestCase
{
    private CategoryRuleEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new CategoryRuleEngine();
    }

    public function testKeywordsCarryTheStrongestSignal(): void
    {
        $categories = $this->engine->categorise(['shopware', 'payment', 'stripe'], [], []);

        self::assertContains('payment', $categories);
    }

    /**
     * German compounds are why matching is substring-based rather than
     * word-boundary. "Versandkostenrechner" contains "versandkosten" but is not
     * that word, and a boundary match would miss every compound in the corpus.
     */
    #[DataProvider('germanCompoundProvider')]
    public function testGermanCompoundsMatchTheirStem(string $label, string $expected): void
    {
        $categories = $this->engine->categorise([], ['de-DE' => $label], []);

        self::assertContains($expected, $categories, \sprintf('"%s" should be %s', $label, $expected));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function germanCompoundProvider(): iterable
    {
        yield 'shipping cost calculator' => ['Versandkostenrechner', 'shipping'];
        yield 'payment method surcharge' => ['Zahlungsartenaufschlag', 'payment'];
        yield 'cart notice' => ['Warenkorbhinweis', 'checkout'];
        yield 'stock display' => ['Lagerbestandsanzeige', 'inventory'];
        yield 'invoice generator' => ['Rechnungserstellung', 'tax'];
    }

    /**
     * Umlauts are folded on both sides, so rules need only one spelling.
     */
    public function testUmlautsAreFolded(): void
    {
        self::assertContains('i18n', $this->engine->categorise([], ['de-DE' => 'Übersetzungen'], []));
        self::assertContains('i18n', $this->engine->categorise([], ['de-DE' => 'Ubersetzungen'], []));
        self::assertContains('inventory', $this->engine->categorise([], ['de-DE' => 'Verfügbarkeit'], []));
    }

    /**
     * The reason short terms are held to a word boundary. "abo" is a real term for
     * the subscription category, and substring matching would file every extension
     * whose description says "about" under Subscriptions.
     */
    public function testShortTermsDoNotMatchInsideLongerWords(): void
    {
        $categories = $this->engine->categorise(
            [],
            ['en-GB' => 'About this shop'],
            ['en-GB' => 'Information about the storefront'],
        );

        self::assertNotContains('subscription', $categories);
    }

    /**
     * A description mentioning a neighbouring concept must not create a category.
     * Descriptions constantly say things like "works alongside your payment
     * provider", and every wrong assignment is a support email.
     *
     * This is the case the whole description rule is built around: one ordinary word,
     * and nothing else, stays below the bar.
     */
    public function testOneGenericTermInADescriptionIsStillNotEnough(): void
    {
        $categories = $this->engine->categorise(
            [],
            ['en-GB' => 'Widget Toolbox'],
            ['en-GB' => 'Compatible with any payment provider you already use.'],
        );

        self::assertNotContains('payment', $categories);
    }

    /**
     * A term that means only one thing is enough on its own.
     *
     * Nobody writes "Turnstile" in a plugin description by accident, so the
     * corroboration a generic word needs would be ceremony here. 520 of the 595
     * indexed extensions declare no keywords at all, and this is how the ones that
     * name their subject plainly get categorised at all.
     */
    public function testAStrongTermInADescriptionAssignsOnItsOwn(): void
    {
        $categories = $this->engine->categorise(
            [],
            ['en-GB' => 'Simple Turnstile'],
            ['en-GB' => 'Adds Cloudflare Turnstile as a captcha solution for Shopware 6.'],
        );

        self::assertContains('security', $categories);
    }

    /**
     * Several generic terms are still not corroboration.
     *
     * Counting two of them as evidence was tried and measured, and it was wrong: the
     * term lists carry English and German side by side, so `customer` and `kunde` are
     * one concept written twice, not two independent signals. On the live corpus that
     * filed a CDN plugin under Shipping on "delivery, lieferung" and a search-suggest
     * plugin under Customers on "customer, kunde" — about a third of the new
     * assignments were wrong that way.
     */
    public function testSeveralGenericTermsAreStillNotEnough(): void
    {
        $translations = $this->engine->categorise(
            [],
            ['en-GB' => 'Bunny CDN Storage'],
            ['en-GB' => 'Speeds up delivery of media. Beschleunigt die Lieferung.'],
        );

        self::assertNotContains('shipping', $translations, 'delivery and Lieferung are one signal.');

        $sameConcept = $this->engine->categorise(
            [],
            ['en-GB' => 'Turbo Suggest'],
            ['en-GB' => 'Shows suggestions to the customer. Für jeden Kunde nützlich.'],
        );

        self::assertNotContains('customer', $sameConcept);
    }

    /**
     * From the live catalogue, verbatim: uncategorised before this rule existed.
     */
    public function testTheMultisafepayDescriptionCategorisesAsPayment(): void
    {
        $categories = $this->engine->categorise(
            [],
            ['en-GB' => 'MultiSafepay'],
            ['en-GB' => 'MultiSafepay online payments for Shopware (iDEAL | Wero, Cards, Klarna, Alipay etc.)'],
        );

        self::assertContains('payment', $categories);
    }

    /**
     * Also from the live catalogue: a description whose only payment word is the
     * generic one must still not be enough, even now.
     */
    public function testABitcoinPaymentDescriptionNeedsItsStrongTerm(): void
    {
        $withBrand = $this->engine->categorise(
            [],
            ['en-GB' => 'Coinsnap'],
            ['en-GB' => 'Accept Bitcoin and Lightning payment in Shopware with Coinsnap.'],
        );

        self::assertContains('payment', $withBrand, 'bitcoin is unambiguous.');

        $withoutBrand = $this->engine->categorise(
            [],
            ['en-GB' => 'Some Widget'],
            ['en-GB' => 'Works with the payment method you already accept.'],
        );

        self::assertNotContains('payment', $withoutBrand);
    }

    /**
     * There is deliberately no "best guess" fallback, even when the weak match is
     * correct. "Adds a sitemap for better indexing" really is an SEO extension, and
     * we still decline to categorise it from the description alone — because
     * nothing distinguishes it from the payment-provider sentence above, and a
     * rule that cannot tell true from false positives should not fire at all.
     *
     * The fix for this case is a rule addition (or a maintainer adding a keyword),
     * not a heuristic. Uncategorised extensions stay searchable meanwhile.
     */
    public function testACorrectWeakMatchIsStillNotEnough(): void
    {
        $categories = $this->engine->categorise(
            [],
            ['en-GB' => 'Toolbox'],
            ['en-GB' => 'Adds a sitemap for better indexing.'],
        );

        self::assertSame([], $categories);
    }

    /**
     * The same extension categorises correctly as soon as a stronger source carries
     * the signal — which is what the rules are meant to key on.
     */
    public function testTheSameExtensionCategorisesFromLabelOrKeywords(): void
    {
        self::assertContains('seo', $this->engine->categorise([], ['en-GB' => 'Sitemap Toolbox'], []));
        self::assertContains('seo', $this->engine->categorise(['sitemap'], ['en-GB' => 'Toolbox'], []));
    }

    public function testNoSignalYieldsNoCategory(): void
    {
        self::assertSame([], $this->engine->categorise([], ['en-GB' => 'Zzz'], []));
        self::assertSame([], $this->engine->categorise([], [], []));
    }

    /**
     * A category cannot win by listing more synonyms than its neighbours; each
     * counts at most once per source.
     */
    public function testSynonymStuffingDoesNotInflateScore(): void
    {
        $many = $this->engine->explain(['payment', 'zahlung', 'paypal', 'stripe', 'klarna'], [], []);
        $one = $this->engine->explain(['payment'], [], []);

        // explain() lists every term that matched, so the stuffed one legitimately
        // reports more of them — that is its job, showing why an assignment happened.
        self::assertGreaterThan(
            \count($one['payment']['keywords']),
            \count($many['payment']['keywords']),
        );

        // categorise() must not care: five payment synonyms score exactly what one does.
        self::assertSame(['payment'], $this->engine->categorise(['payment', 'zahlung', 'paypal'], [], []));
    }

    public function testAtMostThreeCategoriesAreAssigned(): void
    {
        $categories = $this->engine->categorise(
            ['payment', 'shipping', 'seo', 'analytics', 'cache', 'newsletter'],
            [],
            [],
        );

        self::assertLessThanOrEqual(3, \count($categories));
    }

    /**
     * Every key the engine can emit must exist in the definition list, or the
     * classify command would silently drop assignments.
     */
    public function testEveryEmittedKeyIsDefined(): void
    {
        $defined = CategoryDefinition::keys();

        $emitted = $this->engine->categorise(
            ['payment', 'shipping', 'seo'],
            ['en-GB' => 'Everything plugin'],
            ['de-DE' => 'Versand, Zahlung und Suchmaschinenoptimierung'],
        );

        foreach ($emitted as $key) {
            self::assertContains($key, $defined);
        }
    }

    /**
     * Guards the definition list itself: duplicate keys would silently overwrite
     * each other, and an empty term list would make a category unreachable.
     */
    public function testCategoryDefinitionsAreWellFormed(): void
    {
        $keys = CategoryDefinition::keys();

        self::assertSame($keys, array_unique($keys), 'category keys must be unique');
        self::assertGreaterThanOrEqual(20, \count($keys), 'taxonomy should be granular enough to browse');

        foreach (CategoryDefinition::all() as $key => [$label, $description, $terms]) {
            self::assertNotSame('', $label, $key);
            self::assertNotSame('', $description, $key);
            self::assertNotEmpty($terms, \sprintf('category "%s" has no terms and can never match', $key));
        }
    }
}
