<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Observer;

use Magento\Customer\Model\Customer;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use M2Oidc\OAuth\Model\ResourceModel\PasskeyCredentialRepository;
use M2Oidc\OAuth\Model\ResourceModel\UserProvider;
use M2Oidc\OAuth\Observer\CustomerDeleteObserver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \M2Oidc\OAuth\Observer\CustomerDeleteObserver
 */
class CustomerDeleteObserverTest extends TestCase
{
    /** @var UserProvider&MockObject */
    private UserProvider $userProviderResource;

    /** @var PasskeyCredentialRepository&MockObject */
    private PasskeyCredentialRepository $passkeyCredentialRepository;

    /** @var CustomerDeleteObserver */
    private CustomerDeleteObserver $observer;

    protected function setUp(): void
    {
        $this->userProviderResource = $this->createMock(UserProvider::class);
        $this->passkeyCredentialRepository = $this->createMock(PasskeyCredentialRepository::class);
        $this->observer = new CustomerDeleteObserver(
            $this->userProviderResource,
            $this->passkeyCredentialRepository
        );
    }

    private function buildObserver(int $customerId): Observer
    {
        $customer = $this->createMock(Customer::class);
        $customer->method('getId')->willReturn($customerId);

        $event = new \Magento\Framework\Event(['customer' => $customer]);
        return new Observer(['event' => $event]);
    }

    public function testDeleteMappingCalledWithCorrectCustomerIdAndType(): void
    {
        $this->userProviderResource
            ->expects($this->once())
            ->method('deleteMapping')
            ->with('customer', 7);
        $this->passkeyCredentialRepository
            ->expects($this->once())
            ->method('deleteAllForUser')
            ->with('customer', 7);

        $this->observer->execute($this->buildObserver(7));
    }

    public function testNoOpWhenCustomerIdIsZero(): void
    {
        $this->userProviderResource
            ->expects($this->never())
            ->method('deleteMapping');
        $this->passkeyCredentialRepository
            ->expects($this->never())
            ->method('deleteAllForUser');

        $this->observer->execute($this->buildObserver(0));
    }
}
