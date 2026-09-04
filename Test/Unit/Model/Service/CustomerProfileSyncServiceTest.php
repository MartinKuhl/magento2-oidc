<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Model\Service;

use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\RegionInterface;
use Magento\Customer\Api\Data\RegionInterfaceFactory;
use Magento\Directory\Model\ResourceModel\Country\Collection as CountryCollection;
use Magento\Directory\Model\ResourceModel\Country\CollectionFactory as CountryCollectionFactory;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Test\Unit\Fixtures\CountryWithId;
use M2Oidc\OAuth\Model\Attribute\CountryResolver;
use M2Oidc\OAuth\Model\Attribute\GenderMapper;
use M2Oidc\OAuth\Model\Provider\MappingRepository;
use M2Oidc\OAuth\Model\Service\CustomerProfileSyncService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CustomerProfileSyncService.
 *
 * Verifies:
 *  - syncProfile() updates firstname, lastname, DOB, gender, email on change
 *  - syncProfile() skips unchanged fields and respects sync_on_sso flag
 *  - formatDob() parses all supported date formats and falls back to strtotime
 *  - mapGender() maps strings/codes to Magento IDs (1=Male, 2=Female, 3=Other)
 *  - resolveCountryId() handles ISO-2/ISO-3 codes and English country names
 *  - syncAddress() creates new address when none exists
 *  - syncAddress() updates existing default billing address when data changes
 *  - syncAddress() returns early when required fields are missing
 *
 * @covers \M2Oidc\OAuth\Model\Service\CustomerProfileSyncService
 */
class CustomerProfileSyncServiceTest extends TestCase
{
    /** @var CustomerRepositoryInterface&MockObject */
    private CustomerRepositoryInterface $customerRepository;

    /** @var AddressInterfaceFactory&MockObject */
    private AddressInterfaceFactory $addressFactory;

    /** @var AddressRepositoryInterface&MockObject */
    private AddressRepositoryInterface $addressRepository;

    /** @var CountryCollectionFactory&MockObject */
    private CountryCollectionFactory $countryCollectionFactory;

    /** @var RegionInterfaceFactory&MockObject */
    private RegionInterfaceFactory $regionFactory;

    /** @var OAuthUtility&MockObject */
    private OAuthUtility $oauthUtility;

    /** @var MappingRepository&MockObject */
    private MappingRepository $mappingRepository;

    /** @var CustomerProfileSyncService */
    private CustomerProfileSyncService $service;

    protected function setUp(): void
    {
        $this->customerRepository     = $this->createMock(CustomerRepositoryInterface::class);
        $this->addressFactory         = $this->createMock(AddressInterfaceFactory::class);
        $this->addressRepository      = $this->createMock(AddressRepositoryInterface::class);
        $this->countryCollectionFactory = $this->createMock(CountryCollectionFactory::class);
        $this->regionFactory          = $this->createMock(RegionInterfaceFactory::class);
        $this->oauthUtility           = $this->createMock(OAuthUtility::class);
        $this->mappingRepository      = $this->createMock(MappingRepository::class);

        $this->oauthUtility->method('customlog');

        $this->service = new CustomerProfileSyncService(
            $this->customerRepository,
            $this->addressFactory,
            $this->addressRepository,
            $this->regionFactory,
            $this->oauthUtility,
            $this->mappingRepository,
            new GenderMapper(),
            new CountryResolver($this->countryCollectionFactory, $this->oauthUtility)
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a CustomerInterface mock with preset getter values.
     */
    private function makeCustomerMock(
        int    $id = 1,
        string $firstname = 'Alice',
        string $lastname = 'Smith',
        string $email = 'a@example.com',
        string $dob = '',
        int    $gender = 0,
        ?string $defaultBilling = null
    ): CustomerInterface&MockObject {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn($id);
        $customer->method('getFirstname')->willReturn($firstname);
        $customer->method('getLastname')->willReturn($lastname);
        $customer->method('getEmail')->willReturn($email);
        $customer->method('getDob')->willReturn($dob);
        $customer->method('getGender')->willReturn($gender);
        $customer->method('getDefaultBilling')->willReturn($defaultBilling);
        return $customer;
    }

    /**
     * Build a fluent CountryCollection mock that returns $countryId from getFirstItem().
     * Pass null to simulate "not found".
     * getCountryId() is declared on the CountryWithId test double since it is
     * only reachable via Country's magic __call() on the real class.
     */
    private function makeCountryCollectionMock(?string $countryId): CountryCollection&MockObject
    {
        $country = $this->getMockBuilder(CountryWithId::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCountryId'])
            ->getMock();
        $country->method('getCountryId')->willReturn($countryId);

        $collection = $this->createMock(CountryCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($country);
        $collection->method('getColumnValues')->willReturn([]);
        // CountryResolver's store-locale scan (step 3) iterates the collection;
        // an empty iterator means "no name match", falling through to the intl step.
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        return $collection;
    }

    /**
     * Build a fluent AddressInterface mock.
     *
     * @param array<string> $street
     */
    private function makeAddressMock(
        array  $street = ['123 Main St'],
        string $city = 'Berlin',
        string $postcode = '10115',
        string $countryId = 'DE'
    ): AddressInterface&MockObject {
        $address = $this->createMock(AddressInterface::class);
        $address->method('getStreet')->willReturn($street);
        $address->method('getCity')->willReturn($city);
        $address->method('getPostcode')->willReturn($postcode);
        $address->method('getCountryId')->willReturn($countryId);
        $address->method('getTelephone')->willReturn('');
        $address->method('setStreet')->willReturnSelf();
        $address->method('setCity')->willReturnSelf();
        $address->method('setPostcode')->willReturnSelf();
        $address->method('setCountryId')->willReturnSelf();
        $address->method('setTelephone')->willReturnSelf();
        $address->method('setRegion')->willReturnSelf();
        return $address;
    }

    // =========================================================================
    // syncProfile — firstname
    // =========================================================================

    public function testSyncProfileUpdatesFirstnameWhenChanged(): void
    {
        $customer = $this->makeCustomerMock(firstname: 'OldFirst');
        $customer->expects($this->once())->method('setFirstname')->with('NewFirst');
        $this->customerRepository->expects($this->once())->method('save')->with($customer);

        $this->service->syncProfile($customer, ['given_name' => 'NewFirst'], [], ['firstname' => 'given_name']);
    }

    public function testSyncProfileSkipsFirstnameWhenUnchanged(): void
    {
        $customer = $this->makeCustomerMock(firstname: 'Alice');
        $customer->expects($this->never())->method('setFirstname');
        $this->customerRepository->expects($this->never())->method('save');

        $this->service->syncProfile($customer, ['given_name' => 'Alice'], [], ['firstname' => 'given_name']);
    }

    public function testSyncProfileSkipsFirstnameWhenKeyMissingFromAttrs(): void
    {
        $customer = $this->makeCustomerMock(firstname: 'OldFirst');
        $customer->expects($this->never())->method('setFirstname');
        $this->customerRepository->expects($this->never())->method('save');

        // Key mapped to 'given_name' but that key absent from flat and raw
        $this->service->syncProfile($customer, [], [], ['firstname' => 'given_name']);
    }

    // =========================================================================
    // syncProfile — lastname
    // =========================================================================

    public function testSyncProfileUpdatesLastnameWhenChanged(): void
    {
        $customer = $this->makeCustomerMock(lastname: 'OldLast');
        $customer->expects($this->once())->method('setLastname')->with('Jones');
        $this->customerRepository->expects($this->once())->method('save');

        $this->service->syncProfile($customer, ['family_name' => 'Jones'], [], ['lastname' => 'family_name']);
    }

    public function testSyncProfileSkipsLastnameWhenUnchanged(): void
    {
        $customer = $this->makeCustomerMock(lastname: 'Smith');
        $customer->expects($this->never())->method('setLastname');
        $this->customerRepository->expects($this->never())->method('save');

        $this->service->syncProfile($customer, ['family_name' => 'Smith'], [], ['lastname' => 'family_name']);
    }

    // =========================================================================
    // syncProfile — DOB (exercises formatDob indirectly)
    // =========================================================================

    /** @dataProvider dobFormatProvider */
    public function testSyncProfileUpdatesDobForValidFormats(string $oidcDob, string $expectedFormatted): void
    {
        $customer = $this->makeCustomerMock(dob: '');
        $customer->expects($this->once())->method('setDob')->with($expectedFormatted);
        $this->customerRepository->expects($this->once())->method('save');

        $this->service->syncProfile($customer, ['birthdate' => $oidcDob], [], ['dob' => 'birthdate']);
    }

    /** @return array<string, array{string, string}> */
    public static function dobFormatProvider(): array
    {
        return [
            'Y-m-d (ISO)'      => ['1990-01-15',  '1990-01-15'],
            'd/m/Y (EU)'       => ['15/01/1990',  '1990-01-15'],
            'm/d/Y (US)'       => ['01/15/1990',  '1990-01-15'],
            'd.m.Y (DE)'       => ['15.01.1990',  '1990-01-15'],
            'Y/m/d (Alt ISO)'  => ['1990/01/15',  '1990-01-15'],
        ];
    }

    public function testSyncProfileUpdatesDobViaStrtimeFallback(): void
    {
        $customer = $this->makeCustomerMock(dob: '');
        // "January 15, 1990" is parseable by strtotime
        $customer->expects($this->once())->method('setDob')->with('1990-01-15');
        $this->customerRepository->expects($this->once())->method('save');

        $this->service->syncProfile($customer, ['birthdate' => 'January 15, 1990'], [], ['dob' => 'birthdate']);
    }

    public function testSyncProfileSkipsDobWhenStringIsInvalid(): void
    {
        $customer = $this->makeCustomerMock(dob: '');
        $customer->expects($this->never())->method('setDob');
        $this->customerRepository->expects($this->never())->method('save');

        $this->service->syncProfile($customer, ['birthdate' => 'not-a-date'], [], ['dob' => 'birthdate']);
    }

    public function testSyncProfileSkipsDobWhenAlreadyFormatted(): void
    {
        $customer = $this->makeCustomerMock(dob: '1990-01-15');
        $customer->expects($this->never())->method('setDob');
        $this->customerRepository->expects($this->never())->method('save');

        $this->service->syncProfile($customer, ['birthdate' => '1990-01-15'], [], ['dob' => 'birthdate']);
    }

    // =========================================================================
    // syncProfile — gender (exercises mapGender indirectly)
    // =========================================================================

    /** @dataProvider genderStringProvider */
    public function testSyncProfileMapsGenderStringToId(string $oidcGender, int $expectedId): void
    {
        $customer = $this->makeCustomerMock(gender: 0);
        $customer->expects($this->once())->method('setGender')->with($expectedId);
        $this->customerRepository->expects($this->once())->method('save');

        $this->service->syncProfile($customer, ['gender' => $oidcGender], [], ['gender' => 'gender']);
    }

    /** @return array<string, array{string, int}> */
    public static function genderStringProvider(): array
    {
        return [
            'male (string)'    => ['male',    1],
            'Male (capitalized)' => ['Male',  1],
            'm (short)'        => ['m',        1],
            'M (uppercase)'    => ['M',        1],
            '1 (numeric male)' => ['1',        1],
            'mann (German)'    => ['mann',     1],
            'männlich (German)' => ['männlich', 1],
            'female (string)'  => ['female',   2],
            'Female'           => ['Female',   2],
            'f (short)'        => ['f',        2],
            'F (uppercase)'    => ['F',        2],
            '2 (numeric female)' => ['2',      2],
            'frau (German)'    => ['frau',     2],
            'weiblich (German)' => ['weiblich', 2],
        ];
    }

    public function testSyncProfileSkipsGenderWhenStringUnrecognized(): void
    {
        $customer = $this->makeCustomerMock(gender: 0);
        $customer->expects($this->never())->method('setGender');
        $this->customerRepository->expects($this->never())->method('save');

        $this->service->syncProfile($customer, ['gender' => 'unknown_value'], [], ['gender' => 'gender']);
    }

    /**
     * GenderMapper only recognizes male/female vocabulary; "other"/"diverse"/"3"
     * are no longer special-cased to gender ID 3 (that bucket was CustomerProfileSyncService-
     * only behaviour prior to unification). They now behave like any other unrecognized
     * value: sync is skipped rather than forcing "Not Specified".
     *
     * @dataProvider unspecifiedGenderProvider
     */
    public function testSyncProfileSkipsGenderForUnspecifiedValues(string $oidcGender): void
    {
        $customer = $this->makeCustomerMock(gender: 0);
        $customer->expects($this->never())->method('setGender');
        $this->customerRepository->expects($this->never())->method('save');

        $this->service->syncProfile($customer, ['gender' => $oidcGender], [], ['gender' => 'gender']);
    }

    /** @return array<string, array{string}> */
    public static function unspecifiedGenderProvider(): array
    {
        return [
            'other'        => ['other'],
            'diverse'      => ['diverse'],
            'numeric-3'    => ['3'],
        ];
    }

    public function testSyncProfileSkipsGenderWhenAlreadyMatches(): void
    {
        $customer = $this->makeCustomerMock(gender: 1); // already male
        $customer->expects($this->never())->method('setGender');
        $this->customerRepository->expects($this->never())->method('save');

        $this->service->syncProfile($customer, ['gender' => 'male'], [], ['gender' => 'gender']);
    }

    // =========================================================================
    // syncProfile — email
    // =========================================================================

    public function testSyncProfileUpdatesEmailWhenChanged(): void
    {
        $customer = $this->makeCustomerMock(email: 'old@example.com');
        $customer->expects($this->once())->method('setEmail')->with('new@example.com');
        $this->customerRepository->expects($this->once())->method('save');

        $this->service->syncProfile($customer, ['email' => 'new@example.com'], [], ['email' => 'email']);
    }

    public function testSyncProfileSkipsEmailWhenUnchanged(): void
    {
        $customer = $this->makeCustomerMock(email: 'same@example.com');
        $customer->expects($this->never())->method('setEmail');
        $this->customerRepository->expects($this->never())->method('save');

        $this->service->syncProfile($customer, ['email' => 'same@example.com'], [], ['email' => 'email']);
    }

    // =========================================================================
    // syncProfile — per-attribute sync_on_sso flag
    // =========================================================================

    public function testSyncProfileSkipsFirstnameWhenSyncOnSsoIsZero(): void
    {
        $customer = $this->makeCustomerMock(firstname: 'OldFirst');
        $this->mappingRepository->method('getFullAttributeMap')->with(1)->willReturn([
            'firstname' => ['sync_on_sso' => 0],
        ]);

        $customer->expects($this->never())->method('setFirstname');
        $this->customerRepository->expects($this->never())->method('save');

        $this->service->syncProfile($customer, ['given_name' => 'NewFirst'], [], ['firstname' => 'given_name'], 1);
    }

    public function testSyncProfileSyncsFirstnameWhenSyncOnSsoIsOne(): void
    {
        $customer = $this->makeCustomerMock(firstname: 'OldFirst');
        $this->mappingRepository->method('getFullAttributeMap')->with(2)->willReturn([
            'firstname' => ['sync_on_sso' => 1],
        ]);

        $customer->expects($this->once())->method('setFirstname')->with('NewFirst');
        $this->customerRepository->expects($this->once())->method('save');

        $this->service->syncProfile($customer, ['given_name' => 'NewFirst'], [], ['firstname' => 'given_name'], 2);
    }

    public function testSyncProfileSkipsLastnameWhenSyncOnSsoIsZero(): void
    {
        $customer = $this->makeCustomerMock(lastname: 'OldLast');
        $this->mappingRepository->method('getFullAttributeMap')->with(7)->willReturn([
            'lastname' => ['sync_on_sso' => 0],
        ]);

        $customer->expects($this->never())->method('setLastname');
        $this->customerRepository->expects($this->never())->method('save');

        $this->service->syncProfile($customer, ['family_name' => 'NewLast'], [], ['lastname' => 'family_name'], 7);
    }

    public function testSyncProfileSkipsDobWhenSyncOnSsoIsZero(): void
    {
        $customer = $this->makeCustomerMock(dob: '');
        $this->mappingRepository->method('getFullAttributeMap')->with(3)->willReturn([
            'dob' => ['sync_on_sso' => 0],
        ]);

        $customer->expects($this->never())->method('setDob');
        $this->customerRepository->expects($this->never())->method('save');

        $this->service->syncProfile($customer, ['birthdate' => '1990-01-15'], [], ['dob' => 'birthdate'], 3);
    }

    public function testSyncProfileSkipsGenderWhenSyncOnSsoIsZero(): void
    {
        $customer = $this->makeCustomerMock(gender: 0);
        $this->mappingRepository->method('getFullAttributeMap')->with(4)->willReturn([
            'gender' => ['sync_on_sso' => 0],
        ]);

        $customer->expects($this->never())->method('setGender');
        $this->customerRepository->expects($this->never())->method('save');

        $this->service->syncProfile($customer, ['gender' => 'male'], [], ['gender' => 'gender'], 4);
    }

    public function testSyncProfileSkipsEmailWhenSyncOnSsoIsZero(): void
    {
        $customer = $this->makeCustomerMock(email: 'old@example.com');
        $this->mappingRepository->method('getFullAttributeMap')->with(5)->willReturn([
            'email' => ['sync_on_sso' => 0],
        ]);

        $customer->expects($this->never())->method('setEmail');
        $this->customerRepository->expects($this->never())->method('save');

        $this->service->syncProfile($customer, ['email' => 'new@example.com'], [], ['email' => 'email'], 5);
    }

    // =========================================================================
    // syncProfile — legacy mode (providerId=0)
    // =========================================================================

    public function testSyncProfileLegacyModeDoesNotCallMappingRepository(): void
    {
        $customer = $this->makeCustomerMock(firstname: 'OldFirst');
        $this->mappingRepository->expects($this->never())->method('getFullAttributeMap');

        $customer->expects($this->once())->method('setFirstname');
        $this->customerRepository->expects($this->once())->method('save');

        $this->service->syncProfile($customer, ['given_name' => 'NewFirst'], [], ['firstname' => 'given_name'], 0);
    }

    public function testSyncProfileLegacyModeSyncsWhenNoNormalizedRow(): void
    {
        $customer = $this->makeCustomerMock(firstname: 'OldFirst');
        $this->mappingRepository->method('getFullAttributeMap')->with(6)->willReturn([]);

        $customer->expects($this->once())->method('setFirstname');
        $this->customerRepository->expects($this->once())->method('save');

        $this->service->syncProfile($customer, ['given_name' => 'NewFirst'], [], ['firstname' => 'given_name'], 6);
    }

    // =========================================================================
    // syncProfile — no changes → no save
    // =========================================================================

    public function testSyncProfileDoesNotSaveWhenNothingChanged(): void
    {
        $customer = $this->makeCustomerMock(firstname: 'Alice', lastname: 'Smith', email: 'a@example.com');
        $this->customerRepository->expects($this->never())->method('save');

        // Empty attrs → extract returns null for all fields → no changes
        $this->service->syncProfile($customer, [], [], ['firstname' => 'gn', 'lastname' => 'ln', 'email' => 'em']);
    }

    // =========================================================================
    // syncProfile — extract from raw attrs fallback
    // =========================================================================

    public function testSyncProfileExtractsFromRawWhenNotInFlat(): void
    {
        $customer = $this->makeCustomerMock(firstname: 'OldFirst');
        $customer->expects($this->once())->method('setFirstname')->with('RawFirst');
        $this->customerRepository->expects($this->once())->method('save');

        $this->service->syncProfile($customer, [], ['given_name' => 'RawFirst'], ['firstname' => 'given_name']);
    }

    // =========================================================================
    // syncAddress — early return when required fields missing
    // =========================================================================

    public function testSyncAddressSkipsWhenStreetMissing(): void
    {
        $customer = $this->makeCustomerMock();
        $this->addressRepository->expects($this->never())->method('save');

        $addrKeys = ['street' => 'addr_street', 'city' => 'addr_city', 'country' => 'addr_country'];
        // No 'addr_street' in flat → street is null → early return
        $this->service->syncAddress($customer, ['addr_city' => 'Berlin', 'addr_country' => 'DE'], [], $addrKeys);
    }

    public function testSyncAddressSkipsWhenCityMissing(): void
    {
        $customer = $this->makeCustomerMock();
        $this->addressRepository->expects($this->never())->method('save');

        $addrKeys = ['street' => 'addr_street', 'city' => 'addr_city', 'country' => 'addr_country'];
        $this->service->syncAddress($customer, ['addr_street' => 'Main St', 'addr_country' => 'DE'], [], $addrKeys);
    }

    public function testSyncAddressSkipsWhenCountryMissing(): void
    {
        $customer = $this->makeCustomerMock();
        $this->addressRepository->expects($this->never())->method('save');

        $addrKeys = ['street' => 'addr_street', 'city' => 'addr_city', 'country' => 'addr_country'];
        $this->service->syncAddress($customer, ['addr_street' => 'Main St', 'addr_city' => 'Berlin'], [], $addrKeys);
    }

    // =========================================================================
    // syncAddress — resolveCountryId: early return when country not resolvable
    // =========================================================================

    public function testSyncAddressSkipsWhenCountryNotResolvable(): void
    {
        $customer = $this->makeCustomerMock();

        $this->countryCollectionFactory->method('create')->willReturn($this->makeCountryCollectionMock(null));

        $this->addressRepository->expects($this->never())->method('save');

        $addrKeys = ['street' => 's', 'city' => 'c', 'country' => 'co'];
        $this->service->syncAddress(
            $customer,
            ['s' => 'Main St', 'c' => 'Berlin', 'co' => 'UnknownCountry'],
            [],
            $addrKeys
        );
    }

    // =========================================================================
    // syncAddress — resolveCountryId: ISO-2 passthrough
    // =========================================================================

    public function testSyncAddressResolvesIso2CountryCode(): void
    {
        $customer = $this->makeCustomerMock(defaultBilling: null);

        // ISO-2 code goes straight through; no collection lookup needed
        $newAddress = $this->makeAddressMock();
        $newAddress->method('setCustomerId')->willReturnSelf();
        $newAddress->method('setFirstname')->willReturnSelf();
        $newAddress->method('setLastname')->willReturnSelf();
        $newAddress->method('setIsDefaultBilling')->willReturnSelf();
        $newAddress->method('setTelephone')->willReturnSelf();
        $this->addressFactory->method('create')->willReturn($newAddress);

        // CountryCollection should NOT be called for 'DE' (already ISO-2)
        $this->countryCollectionFactory->expects($this->never())->method('create');
        $this->addressRepository->expects($this->once())->method('save')->with($newAddress);

        $addrKeys = ['street' => 's', 'city' => 'c', 'country' => 'co'];
        $this->service->syncAddress($customer, ['s' => 'Main St', 'c' => 'Berlin', 'co' => 'DE'], [], $addrKeys);
    }

    // =========================================================================
    // syncAddress — creates new address when no default billing exists
    // =========================================================================

    public function testSyncAddressCreatesNewAddressWhenNoneExists(): void
    {
        $customer = $this->makeCustomerMock(defaultBilling: null);

        // countryCollection needed for 3-char ISO lookup
        $this->countryCollectionFactory->expects($this->never())->method('create');

        $newAddress = $this->makeAddressMock();
        $newAddress->method('setCustomerId')->willReturnSelf();
        $newAddress->method('setFirstname')->willReturnSelf();
        $newAddress->method('setLastname')->willReturnSelf();
        $newAddress->method('setIsDefaultBilling')->willReturnSelf();
        $newAddress->method('setTelephone')->willReturnSelf();
        $this->addressFactory->method('create')->willReturn($newAddress);

        $this->addressRepository->expects($this->once())->method('save')->with($newAddress);

        $addrKeys = ['street' => 's', 'city' => 'c', 'country' => 'co'];
        $this->service->syncAddress(
            $customer,
            ['s' => 'Alexanderplatz 1', 'c' => 'Berlin', 'co' => 'DE'],
            [],
            $addrKeys
        );
    }

    public function testSyncAddressNewAddressSetsDefaultBillingFlag(): void
    {
        $customer = $this->makeCustomerMock(defaultBilling: null);

        $newAddress = $this->makeAddressMock();
        $newAddress->method('setCustomerId')->willReturnSelf();
        $newAddress->method('setFirstname')->willReturnSelf();
        $newAddress->method('setLastname')->willReturnSelf();
        $newAddress->method('setTelephone')->willReturnSelf();

        $newAddress->expects($this->once())->method('setIsDefaultBilling')->with(true)->willReturnSelf();
        $this->addressFactory->method('create')->willReturn($newAddress);
        $this->addressRepository->method('save');

        $addrKeys = ['street' => 's', 'city' => 'c', 'country' => 'co'];
        $this->service->syncAddress($customer, ['s' => 'Main St', 'c' => 'Berlin', 'co' => 'DE'], [], $addrKeys);
    }

    // =========================================================================
    // syncAddress — updates existing address when data changes
    // =========================================================================

    public function testSyncAddressUpdatesExistingAddressWhenStreetChanged(): void
    {
        $customer        = $this->makeCustomerMock(defaultBilling: '10');
        $existingAddress = $this->makeAddressMock(street: ['Old St']);

        $this->addressRepository->method('getById')->with(10)->willReturn($existingAddress);

        $existingAddress->expects($this->once())->method('setStreet')->with(['New St'])->willReturnSelf();
        $this->addressRepository->expects($this->once())->method('save')->with($existingAddress);

        $addrKeys = ['street' => 's', 'city' => 'c', 'country' => 'co'];
        $this->service->syncAddress($customer, ['s' => 'New St', 'c' => 'Berlin', 'co' => 'DE'], [], $addrKeys);
    }

    public function testSyncAddressUpdatesExistingAddressWhenCityChanged(): void
    {
        $customer        = $this->makeCustomerMock(defaultBilling: '10');
        $existingAddress = $this->makeAddressMock(city: 'OldCity');

        $this->addressRepository->method('getById')->with(10)->willReturn($existingAddress);

        $existingAddress->expects($this->once())->method('setCity')->with('Berlin')->willReturnSelf();
        $this->addressRepository->expects($this->once())->method('save');

        $addrKeys = ['street' => 's', 'city' => 'c', 'country' => 'co'];
        $this->service->syncAddress($customer, ['s' => '123 Main St', 'c' => 'Berlin', 'co' => 'DE'], [], $addrKeys);
    }

    public function testSyncAddressUpdatesExistingAddressWhenCountryChanged(): void
    {
        $customer        = $this->makeCustomerMock(defaultBilling: '10');
        $existingAddress = $this->makeAddressMock(countryId: 'FR');

        $this->addressRepository->method('getById')->with(10)->willReturn($existingAddress);

        $existingAddress->expects($this->once())->method('setCountryId')->with('DE')->willReturnSelf();
        $this->addressRepository->expects($this->once())->method('save');

        $addrKeys = ['street' => 's', 'city' => 'c', 'country' => 'co'];
        $this->service->syncAddress($customer, ['s' => '123 Main St', 'c' => 'Berlin', 'co' => 'DE'], [], $addrKeys);
    }

    public function testSyncAddressDoesNotSaveWhenExistingAddressIsUnchanged(): void
    {
        $customer = $this->makeCustomerMock(defaultBilling: '10');
        // All fields match the incoming values
        $existingAddress = $this->makeAddressMock(
            street: ['123 Main St'],
            city: 'Berlin',
            postcode: '10115',
            countryId: 'DE'
        );

        $this->addressRepository->method('getById')->with(10)->willReturn($existingAddress);
        $this->addressRepository->expects($this->never())->method('save');

        $addrKeys = ['street' => 's', 'city' => 'c', 'zip' => 'z', 'country' => 'co'];
        $this->service->syncAddress(
            $customer,
            ['s' => '123 Main St', 'c' => 'Berlin', 'z' => '10115', 'co' => 'DE'],
            [],
            $addrKeys
        );
    }

    // =========================================================================
    // syncAddress — optional phone field
    // =========================================================================

    public function testSyncAddressUpdatesPhoneWhenProvided(): void
    {
        $customer        = $this->makeCustomerMock(defaultBilling: '10');
        $existingAddress = $this->makeAddressMock();

        $this->addressRepository->method('getById')->with(10)->willReturn($existingAddress);

        $existingAddress->expects($this->once())->method('setTelephone')->with('+49123456789')->willReturnSelf();
        $this->addressRepository->expects($this->once())->method('save');

        $addrKeys = ['street' => 's', 'city' => 'c', 'country' => 'co', 'phone' => 'ph'];
        $this->service->syncAddress(
            $customer,
            ['s' => '123 Main St', 'c' => 'Berlin', 'co' => 'DE', 'ph' => '+49123456789'],
            [],
            $addrKeys
        );
    }

    public function testSyncAddressSkipsPhoneWhenNotProvided(): void
    {
        $customer        = $this->makeCustomerMock(defaultBilling: '10');
        $existingAddress = $this->makeAddressMock();

        $this->addressRepository->method('getById')->with(10)->willReturn($existingAddress);
        $existingAddress->expects($this->never())->method('setTelephone');

        // No 'phone' key in addrKeys → phone is null → skip
        $addrKeys = ['street' => 's', 'city' => 'c', 'country' => 'co'];
        $this->service->syncAddress($customer, ['s' => '123 Main St', 'c' => 'Berlin', 'co' => 'DE'], [], $addrKeys);
    }

    // =========================================================================
    // syncAddress — state field
    // =========================================================================

    public function testSyncAddressSetsRegionWhenStateProvided(): void
    {
        $customer        = $this->makeCustomerMock(defaultBilling: '10');
        $existingAddress = $this->makeAddressMock();

        $this->addressRepository->method('getById')->with(10)->willReturn($existingAddress);

        $region = $this->createMock(RegionInterface::class);
        $region->method('setRegion')->with('Bavaria')->willReturnSelf();
        $this->regionFactory->method('create')->willReturn($region);

        $existingAddress->expects($this->once())->method('setRegion')->with($region)->willReturnSelf();
        $this->addressRepository->expects($this->once())->method('save');

        $addrKeys = ['street' => 's', 'city' => 'c', 'country' => 'co', 'state' => 'st'];
        $this->service->syncAddress(
            $customer,
            ['s' => '123 Main St', 'c' => 'Munich', 'co' => 'DE', 'st' => 'Bavaria'],
            [],
            $addrKeys
        );
    }

    // =========================================================================
    // syncAddress — fallback when existing address throws exception
    // =========================================================================

    public function testSyncAddressCreatesNewAddressWhenGetByIdThrows(): void
    {
        $customer = $this->makeCustomerMock(defaultBilling: '10');

        $this->addressRepository->method('getById')
            ->willThrowException(new \Magento\Framework\Exception\NoSuchEntityException());

        $newAddress = $this->makeAddressMock();
        $newAddress->method('setCustomerId')->willReturnSelf();
        $newAddress->method('setFirstname')->willReturnSelf();
        $newAddress->method('setLastname')->willReturnSelf();
        $newAddress->method('setIsDefaultBilling')->willReturnSelf();
        $newAddress->method('setTelephone')->willReturnSelf();
        $this->addressFactory->method('create')->willReturn($newAddress);

        $this->addressRepository->expects($this->once())->method('save')->with($newAddress);

        $addrKeys = ['street' => 's', 'city' => 'c', 'country' => 'co'];
        $this->service->syncAddress($customer, ['s' => 'Main St', 'c' => 'Berlin', 'co' => 'DE'], [], $addrKeys);
    }
}
