<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Model\Attribute;

use Magento\Directory\Model\Country;
use Magento\Directory\Model\ResourceModel\Country\Collection as CountryCollection;
use Magento\Directory\Model\ResourceModel\Country\CollectionFactory as CountryCollectionFactory;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Attribute\CountryResolver;
use M2Oidc\OAuth\Test\Unit\Fixtures\CountryWithId;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CountryResolver.
 *
 * Verifies the unified country-resolution chain shared by CustomerAttributeMapper
 * (customer creation) and CustomerProfileSyncService (address re-sync):
 *   1. 2-letter ISO code → uppercase passthrough
 *   2. filtered collection query on country_id / iso3_code
 *   3. store-locale country-name scan
 *   4. intl-based en_US country-name lookup (Authelia-style English names)
 *   5. deny (null)
 * plus per-request memoization.
 *
 * @covers \M2Oidc\OAuth\Model\Attribute\CountryResolver
 */
class CountryResolverTest extends TestCase
{
    /** @var CountryCollectionFactory&MockObject */
    private CountryCollectionFactory $countryCollectionFactory;

    /** @var OAuthUtility&MockObject */
    private OAuthUtility $oauthUtility;

    protected function setUp(): void
    {
        $this->countryCollectionFactory = $this->createMock(CountryCollectionFactory::class);
        $this->oauthUtility             = $this->createMock(OAuthUtility::class);
        $this->oauthUtility->method('customlog');
    }

    private function makeResolver(): CountryResolver
    {
        return new CountryResolver($this->countryCollectionFactory, $this->oauthUtility);
    }

    /**
     * Build a fluent collection mock: addFieldToFilter/getFirstItem for the ID/ISO3
     * query step, getIterator for the name-scan step, getColumnValues for the intl step.
     *
     * @param  string|null           $filteredMatchId Country ID returned by the filtered query (null = no match)
     * @param  array<int,Country&MockObject> $iterationItems  Items returned by the name-scan iterator
     * @param  string[]              $activeCodes     Codes returned by getColumnValues (intl step)
     */
    private function makeCollectionMock(
        ?string $filteredMatchId,
        array $iterationItems,
        array $activeCodes
    ): CountryCollection&MockObject {
        // getCountryId() is declared on the CountryWithId test double since it is
        // only reachable via Country's magic __call() on the real class.
        $filteredItem = $this->getMockBuilder(CountryWithId::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCountryId'])
            ->getMock();
        $filteredItem->method('getCountryId')->willReturn($filteredMatchId);

        $collection = $this->createMock(CountryCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($filteredItem);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($iterationItems));
        $collection->method('getColumnValues')->willReturn($activeCodes);

        return $collection;
    }

    private function makeCountryNameItem(string $name, string $id): Country&MockObject
    {
        $item = $this->createMock(Country::class);
        $item->method('getName')->willReturn($name);
        $item->method('getId')->willReturn($id);
        return $item;
    }

    // -------------------------------------------------------------------------
    // Empty / blank input
    // -------------------------------------------------------------------------

    public function testResolveReturnsNullForEmptyString(): void
    {
        $this->countryCollectionFactory->expects($this->never())->method('create');

        $this->assertNull($this->makeResolver()->resolve(''));
    }

    public function testResolveReturnsNullForWhitespaceOnlyString(): void
    {
        $this->assertNull($this->makeResolver()->resolve('   '));
    }

    // -------------------------------------------------------------------------
    // Step 1: 2-letter ISO code passthrough
    // -------------------------------------------------------------------------

    public function testResolveUppercasesTwoLetterCode(): void
    {
        $this->countryCollectionFactory->expects($this->never())->method('create');

        $this->assertSame('DE', $this->makeResolver()->resolve('de'));
    }

    public function testResolveTrimsBeforeCheckingTwoLetterCode(): void
    {
        $this->countryCollectionFactory->expects($this->never())->method('create');

        $this->assertSame('US', $this->makeResolver()->resolve('  us  '));
    }

    // -------------------------------------------------------------------------
    // Step 2: filtered collection query on country_id / iso3_code
    // -------------------------------------------------------------------------

    public function testResolveMatchesViaFilteredIdOrIso3Query(): void
    {
        $collection = $this->makeCollectionMock('DE', [], []);
        $this->countryCollectionFactory->method('create')->willReturn($collection);

        $this->assertSame('DE', $this->makeResolver()->resolve('DEU'));
    }

    // -------------------------------------------------------------------------
    // Step 3: store-locale country-name scan
    // -------------------------------------------------------------------------

    public function testResolveMatchesViaLocaleNameScanWhenFilteredQueryMisses(): void
    {
        $collection = $this->makeCollectionMock(
            null,
            [$this->makeCountryNameItem('Deutschland', 'DE')],
            []
        );
        $this->countryCollectionFactory->method('create')->willReturn($collection);

        $this->assertSame('DE', $this->makeResolver()->resolve('Deutschland'));
    }

    public function testResolveNameScanIsCaseInsensitive(): void
    {
        $collection = $this->makeCollectionMock(
            null,
            [$this->makeCountryNameItem('Deutschland', 'DE')],
            []
        );
        $this->countryCollectionFactory->method('create')->willReturn($collection);

        $this->assertSame('DE', $this->makeResolver()->resolve('deutschland'));
    }

    // -------------------------------------------------------------------------
    // Step 4: intl-based English-name lookup (Authelia sends English names)
    // -------------------------------------------------------------------------

    public function testResolveMatchesViaIntlEnglishNameWhenOtherStepsMiss(): void
    {
        if (!extension_loaded('intl')) {
            $this->markTestSkipped('ext-intl not loaded');
        }

        $collection = $this->makeCollectionMock(null, [], ['DE', 'US', 'FR']);
        $this->countryCollectionFactory->method('create')->willReturn($collection);

        // Store locale doesn't have "Germany" as a matching name, but intl's en_US lookup does
        $this->assertSame('DE', $this->makeResolver()->resolve('Germany'));
    }

    // -------------------------------------------------------------------------
    // Step 5: deny (null) when nothing matches
    // -------------------------------------------------------------------------

    public function testResolveReturnsNullWhenNoStepMatches(): void
    {
        $collection = $this->makeCollectionMock(null, [], ['DE', 'US']);
        $this->countryCollectionFactory->method('create')->willReturn($collection);

        $this->assertNull($this->makeResolver()->resolve('Wakanda'));
    }

    public function testResolveDoesNotFatalWhenCollectionThrows(): void
    {
        // A collection whose only mocked method is getIterator (returning an empty
        // iterator) simulates a partially-stubbed/broken collection double; the
        // filtered-query and getColumnValues steps must degrade gracefully rather
        // than propagate an \Error (regression guard for the earlier design that
        // only caught \Exception).
        $collection = $this->getMockBuilder(CountryCollection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getIterator'])
            ->getMock();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->countryCollectionFactory->method('create')->willReturn($collection);

        $this->assertNull($this->makeResolver()->resolve('Nowhereland'));
    }

    // -------------------------------------------------------------------------
    // Per-request memoization
    // -------------------------------------------------------------------------

    public function testResolveMemoizesResultForSameInputWithinOneRequest(): void
    {
        $collection = $this->makeCollectionMock('DE', [], []);
        // Only the FIRST resolve() call for "DEU" should touch the collection factory;
        // the second call must be served from the per-request memo cache.
        $this->countryCollectionFactory->expects($this->once())->method('create')->willReturn($collection);

        $resolver = $this->makeResolver();
        $this->assertSame('DE', $resolver->resolve('DEU'));
        $this->assertSame('DE', $resolver->resolve('DEU'));
    }

    public function testResolveMemoizationIsCaseInsensitiveByKey(): void
    {
        $collection = $this->makeCollectionMock('DE', [], []);
        $this->countryCollectionFactory->expects($this->once())->method('create')->willReturn($collection);

        $resolver = $this->makeResolver();
        $this->assertSame('DE', $resolver->resolve('DEU'));
        $this->assertSame('DE', $resolver->resolve('deu'));
    }
}
