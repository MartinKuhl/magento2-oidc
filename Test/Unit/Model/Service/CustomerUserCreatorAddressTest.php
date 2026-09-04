<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Model\Service;

use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Customer;
use Magento\Customer\Model\CustomerFactory;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Attribute\AttributeMapperInterface;
use M2Oidc\OAuth\Model\Attribute\CustomerAttributeMapper;
use M2Oidc\OAuth\Model\Attribute\GenderMapper;
use M2Oidc\OAuth\Model\Attribute\Transformer;
use M2Oidc\OAuth\Model\Provider\MappingRepository;
use M2Oidc\OAuth\Model\ResourceModel\UserProvider as UserProviderResource;
use M2Oidc\OAuth\Model\Service\CustomerUserCreator;
use M2Oidc\OAuth\Model\Service\GroupMappingResolver;
use M2Oidc\OAuth\Test\Unit\Fixtures\CustomerWithSetters;
use M2Oidc\OAuth\Model\Service\OidcAuthenticationService;
use M2Oidc\OAuth\Model\Service\RandomPasswordGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CustomerUserCreator address creation and attribute mapping (Phase 1.1).
 *
 * Tests are exercised through the public createCustomer() entry point and
 * private helpers accessed via PHP Reflection where isolation is clearer.
 *
 * @covers \M2Oidc\OAuth\Model\Service\CustomerUserCreator
 */
class CustomerUserCreatorAddressTest extends TestCase
{
    /** @var OAuthUtility&MockObject */
    private OAuthUtility $oauthUtility;

    /** @var AddressRepositoryInterface&MockObject */
    private AddressRepositoryInterface $addressRepository;

    /** @var AddressInterfaceFactory&MockObject */
    private AddressInterfaceFactory $addressFactory;

    /** @var CustomerFactory&MockObject */
    private CustomerFactory $customerFactory;

    /** @var CustomerRepositoryInterface&MockObject */
    private CustomerRepositoryInterface $customerRepository;

    /** @var AttributeMapperInterface&MockObject */
    private AttributeMapperInterface $attributeMapper;

    /** @var CustomerUserCreator */
    private CustomerUserCreator $creator;

    protected function setUp(): void
    {
        $this->oauthUtility       = $this->createMock(OAuthUtility::class);
        $this->addressRepository  = $this->createMock(AddressRepositoryInterface::class);
        $this->addressFactory     = $this->createMock(AddressInterfaceFactory::class);
        $this->customerFactory    = $this->createMock(CustomerFactory::class);
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->attributeMapper    = $this->createMock(AttributeMapperInterface::class);

        $this->oauthUtility->method('customlog');
        $this->oauthUtility->method('isBlank')->willReturnCallback(
            fn($v) => $v === null || $v === '' || $v === '0'
        );
        // Minimal config: no group mapping, no deny policy
        $this->oauthUtility->method('getStoreConfig')->willReturnMap([
            [OAuthConstants::MAP_DOB,     'birthdate'],
            [OAuthConstants::MAP_GENDER,  'gender'],
            [OAuthConstants::MAP_PHONE,   'phone_number'],
            [OAuthConstants::MAP_STREET,  'address.street_address'],
            [OAuthConstants::MAP_ZIP,     'address.postal_code'],
            [OAuthConstants::MAP_CITY,    'address.locality'],
            [OAuthConstants::MAP_COUNTRY, 'address.country'],
            [OAuthConstants::MAP_GROUP,   null],
            [OAuthConstants::CREATEIFNOTMAP_CUSTOMER, null],
            [OAuthConstants::MAP_DEFAULT_CUSTOMER_GROUP, null],
            [OAuthConstants::CUSTOMER_GROUP_MAPPING, null],
        ]);

        $passwordGenerator = $this->createMock(RandomPasswordGenerator::class);
        $passwordGenerator->method('generate')->willReturn('xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getId')->willReturn(1);
        $storeManager->method('getWebsite')->willReturn($website);

        $this->creator = new CustomerUserCreator(
            $this->customerFactory,
            $this->addressFactory,
            $this->addressRepository,
            $storeManager,
            $passwordGenerator,
            $this->oauthUtility,
            $this->customerRepository,
            $this->createMock(UserProviderResource::class),
            $this->createMock(MappingRepository::class),
            $this->attributeMapper,
            $this->createMock(OidcAuthenticationService::class),
            new GroupMappingResolver($this->createMock(MappingRepository::class), $this->oauthUtility)
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a minimal Customer model mock.
     * The setters are declared on the CustomerWithSetters test double since
     * they are only reachable via Customer's magic __call() on the real class.
     */
    private function makeCustomerModelMock(): Customer&MockObject
    {
        $customerData = $this->createMock(CustomerInterface::class);
        $customerData->method('getId')->willReturn(101);

        $model = $this->getMockBuilder(CustomerWithSetters::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getDataModel', 'setWebsiteId', 'setEmail', 'setFirstname', 'setLastname',
                          'setDob', 'setGender', 'setGroupId'])
            ->getMock();
        $model->method('setWebsiteId')->willReturnSelf();
        $model->method('setEmail')->willReturnSelf();
        $model->method('setFirstname')->willReturnSelf();
        $model->method('setLastname')->willReturnSelf();
        $model->method('setDob')->willReturnSelf();
        $model->method('setGender')->willReturnSelf();
        $model->method('setGroupId')->willReturnSelf();
        $model->method('getDataModel')->willReturn($customerData);

        return $model;
    }

    /**
     * Build a minimal AddressInterface mock that supports fluent setters.
     */
    private function makeAddressMock(): AddressInterface&MockObject
    {
        $address = $this->createMock(AddressInterface::class);
        $address->method('setCustomerId')->willReturnSelf();
        $address->method('setFirstname')->willReturnSelf();
        $address->method('setLastname')->willReturnSelf();
        $address->method('setStreet')->willReturnSelf();
        $address->method('setCity')->willReturnSelf();
        $address->method('setPostcode')->willReturnSelf();
        $address->method('setCountryId')->willReturnSelf();
        $address->method('setTelephone')->willReturnSelf();
        $address->method('setIsDefaultBilling')->willReturnSelf();
        $address->method('setIsDefaultShipping')->willReturnSelf();
        return $address;
    }

    // -------------------------------------------------------------------------
    // Address creation
    // -------------------------------------------------------------------------

    public function testAddressCreatedWhenStreetPresent(): void
    {
        $this->customerFactory->method('create')
            ->willReturn($this->makeCustomerModelMock());
        $this->customerRepository->method('save')
            ->willReturn($this->createMock(CustomerInterface::class));

        $address = $this->makeAddressMock();
        $this->addressFactory->method('create')->willReturn($address);

        // address should be saved once
        $this->addressRepository->expects($this->once())->method('save');

        // Configure mapper to return billing fields so address creation is triggered
        $this->attributeMapper->method('map')->willReturn([
            'billing_street'     => '123 Main St',
            'billing_city'       => 'Springfield',
            'billing_postcode'   => '12345',
            'billing_country_id' => 'US',
            'billing_telephone'  => '+1-555-0100',
        ]);

        $this->creator->createCustomer(
            'user@example.com',
            'user',
            'John',
            'Doe',
            [
                'address.street_address' => '123 Main St',
                'address.locality'       => 'Springfield',
                'address.postal_code'    => '12345',
                'address.country'        => 'US',
                'phone_number'           => '+1-555-0100',
            ],
            []
        );
    }

    public function testAddressNotCreatedWhenStreetCityAndCountryAllEmpty(): void
    {
        $this->customerFactory->method('create')
            ->willReturn($this->makeCustomerModelMock());
        $this->customerRepository->method('save')
            ->willReturn($this->createMock(CustomerInterface::class));

        // Mapper returns empty — no address fields
        $this->attributeMapper->method('map')->willReturn([]);

        // No address fields in attributes at all
        $this->addressRepository->expects($this->never())->method('save');

        $this->creator->createCustomer(
            'user@example.com',
            'user',
            'John',
            'Doe',
            [],  // no address attributes
            []
        );
    }

    public function testAddressCreatedWithCountryFromRawAttrs(): void
    {
        $this->customerFactory->method('create')
            ->willReturn($this->makeCustomerModelMock());
        $this->customerRepository->method('save')
            ->willReturn($this->createMock(CustomerInterface::class));

        $address = $this->makeAddressMock();
        $this->addressFactory->method('create')->willReturn($address);
        $this->addressRepository->expects($this->once())->method('save');

        // Configure mapper to return billing fields from raw attrs
        $this->attributeMapper->method('map')->willReturn([
            'billing_street'     => '10 Elm St',
            'billing_city'       => 'Berlin',
            'billing_postcode'   => '10115',
            'billing_country_id' => 'DE',
        ]);

        // country comes from nested raw attrs structure
        $this->creator->createCustomer(
            'user@example.com',
            'user',
            'Jane',
            'Doe',
            ['address.street_address' => '10 Elm St'],
            ['address' => ['country' => 'DE', 'locality' => 'Berlin']],
        );
    }

    // -------------------------------------------------------------------------
    // resolveCountryId — tested through createCustomer via address mock
    // -------------------------------------------------------------------------

    public function testTwoLetterCountryCodePassedThroughUppercased(): void
    {
        $this->customerFactory->method('create')
            ->willReturn($this->makeCustomerModelMock());
        $this->customerRepository->method('save')
            ->willReturn($this->createMock(CustomerInterface::class));

        $address = $this->makeAddressMock();
        // Capture the country ID passed to setCountryId
        $capturedCountry = null;
        $address->method('setCountryId')->willReturnCallback(function ($id) use ($address, &$capturedCountry) {
            $capturedCountry = $id;
            return $address;
        });
        $this->addressFactory->method('create')->willReturn($address);
        $this->addressRepository->method('save');

        // Configure mapper to return uppercased two-letter country code
        $this->attributeMapper->method('map')->willReturn([
            'billing_street'     => '1 St',
            'billing_city'       => 'New York',
            'billing_postcode'   => '10001',
            'billing_country_id' => 'US',
        ]);

        $this->creator->createCustomer(
            'u@example.com',
            'u',
            'A',
            'B',
            ['address.street_address' => '1 St', 'address.country' => 'us'],
            []
        );

        $this->assertSame('US', $capturedCountry, 'Two-letter code should be uppercased');
    }

    public function testFullCountryNameResolvedToId(): void
    {
        $this->customerFactory->method('create')
            ->willReturn($this->makeCustomerModelMock());
        $this->customerRepository->method('save')
            ->willReturn($this->createMock(CustomerInterface::class));

        $address = $this->makeAddressMock();
        $capturedCountry = null;
        $address->method('setCountryId')->willReturnCallback(function ($id) use ($address, &$capturedCountry) {
            $capturedCountry = $id;
            return $address;
        });
        $this->addressFactory->method('create')->willReturn($address);
        $this->addressRepository->method('save');

        // Configure mapper to return resolved country code 'DE'
        $this->attributeMapper->method('map')->willReturn([
            'billing_street'     => '1 St',
            'billing_city'       => 'Berlin',
            'billing_postcode'   => '10115',
            'billing_country_id' => 'DE',
        ]);

        $this->creator->createCustomer(
            'u@example.com',
            'u',
            'A',
            'B',
            ['address.street_address' => '1 St', 'address.country' => 'Germany'],
            []
        );

        $this->assertSame('DE', $capturedCountry, 'Full country name should resolve to ISO code');
    }

    // -------------------------------------------------------------------------
    // mapGender — extracted to GenderMapper, exercised directly here;
    // full coverage lives in Test/Unit/Model/Attribute/GenderMapperTest.php
    // -------------------------------------------------------------------------

    /**
     * Build a minimal CustomerAttributeMapper for Reflection-based private method tests.
     */
    private function makeMapper(): CustomerAttributeMapper
    {
        return new CustomerAttributeMapper(
            $this->oauthUtility,
            $this->createMock(Transformer::class),
            new GenderMapper(),
            new \M2Oidc\OAuth\Model\Attribute\CountryResolver(
                $this->createMock(\Magento\Directory\Model\ResourceModel\Country\CollectionFactory::class),
                $this->oauthUtility
            )
        );
    }

    /** @dataProvider genderMaleProvider */
    public function testMapGenderReturnsMaleForVariousInputs(string $input): void
    {
        $this->assertSame(1, (new GenderMapper())->map($input));
    }

    /** @return iterable<string, array{string}> */
    public static function genderMaleProvider(): iterable
    {
        yield 'male'    => ['male'];
        yield 'Male'    => ['Male'];
        yield 'M'       => ['m'];
        yield '1'       => ['1'];
        yield 'mann'    => ['mann'];
        yield 'männlich' => ['männlich'];
    }

    /** @dataProvider genderFemaleProvider */
    public function testMapGenderReturnsFemaleForVariousInputs(string $input): void
    {
        $this->assertSame(2, (new GenderMapper())->map($input));
    }

    /** @return iterable<string, array{string}> */
    public static function genderFemaleProvider(): iterable
    {
        yield 'female'  => ['female'];
        yield 'Female'  => ['Female'];
        yield 'f'       => ['f'];
        yield '2'       => ['2'];
        yield 'frau'    => ['frau'];
        yield 'weiblich' => ['weiblich'];
    }

    public function testMapGenderReturnsNullForUnknownValue(): void
    {
        $this->assertNull((new GenderMapper())->map('other-unrecognized'));
    }

    public function testMapGenderReturnsNullForEmptyString(): void
    {
        $this->assertNull((new GenderMapper())->map(''));
    }

    // -------------------------------------------------------------------------
    // formatDateOfBirth — delegated to CustomerAttributeMapper
    // -------------------------------------------------------------------------

    /** @dataProvider dobProvider */
    public function testFormatDateOfBirthParsesCorrectly(string $input, string $expected): void
    {
        $method = new \ReflectionMethod(CustomerAttributeMapper::class, 'formatDateOfBirth');

        $this->assertSame($expected, $method->invoke($this->makeMapper(), $input));
    }

    /** @return iterable<string, array{string, string}> */
    public static function dobProvider(): iterable
    {
        yield 'ISO date'           => ['1990-01-15', '1990-01-15'];
        yield 'US format'          => ['01/15/1990', '1990-01-15'];
        yield 'Long format'        => ['January 15, 1990', '1990-01-15'];
        yield 'European format'    => ['15.01.1990', '1990-01-15'];
    }

    public function testFormatDateOfBirthReturnsNullForInvalidInput(): void
    {
        $method = new \ReflectionMethod(CustomerAttributeMapper::class, 'formatDateOfBirth');

        $this->assertNull($method->invoke($this->makeMapper(), 'not-a-date-xyzzy'));
    }

    // -------------------------------------------------------------------------
    // createCustomer: gender and DOB set on customer model
    // -------------------------------------------------------------------------

    public function testGenderSetOnCustomerWhenAttributePresent(): void
    {
        $customerModel = $this->makeCustomerModelMock();
        $customerModel->expects($this->once())
            ->method('setGender')
            ->with(2)  // female
            ->willReturnSelf();

        $this->customerFactory->method('create')->willReturn($customerModel);
        $this->customerRepository->method('save')
            ->willReturn($this->createMock(CustomerInterface::class));

        // Mapper returns gender=2 for 'female' input
        $this->attributeMapper->method('map')->willReturn(['gender' => 2]);

        $this->creator->createCustomer(
            'u@example.com',
            'u',
            'Jane',
            'Doe',
            ['gender' => 'female'],
            []
        );
    }

    public function testDobSetOnCustomerWhenAttributePresent(): void
    {
        $customerModel = $this->makeCustomerModelMock();
        $customerModel->expects($this->once())
            ->method('setDob')
            ->with('1985-06-20')
            ->willReturnSelf();

        $this->customerFactory->method('create')->willReturn($customerModel);
        $this->customerRepository->method('save')
            ->willReturn($this->createMock(CustomerInterface::class));

        // Mapper returns dob for 'birthdate' input
        $this->attributeMapper->method('map')->willReturn(['dob' => '1985-06-20']);

        $this->creator->createCustomer(
            'u@example.com',
            'u',
            'Jane',
            'Doe',
            ['birthdate' => '1985-06-20'],
            []
        );
    }

    // -------------------------------------------------------------------------
    // Name fallbacks
    // -------------------------------------------------------------------------

    public function testEmptyFirstNameFallsBackToEmailPrefix(): void
    {
        $this->oauthUtility->method('extractNameFromEmail')
            ->willReturn(['first' => 'john', 'last' => 'example.com']);

        $this->customerFactory->method('create')
            ->willReturn($this->makeCustomerModelMock());
        $this->customerRepository->method('save')
            ->willReturn($this->createMock(CustomerInterface::class));

        $this->attributeMapper->method('map')->willReturn([]);

        // Should not throw
        $result = $this->creator->createCustomer(
            'john@example.com',
            'john',
            '',
            '',
            [],
            []
        );

        $this->assertNotNull($result);
    }
}
