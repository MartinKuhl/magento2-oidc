<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Controller\Adminhtml\Actions\Passkey;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\User\Model\User;
use M2Oidc\OAuth\Controller\Adminhtml\Actions\Passkey\Delete;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\ResourceModel\PasskeyCredentialRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the self-service passkey Delete controller (My Account).
 *
 * Verifies each of the four possible outcomes returns the expected JSON
 * payload and logs a distinguishable message — added because the original
 * implementation only logged the success branch, making it impossible to
 * tell from the logs alone why a "Remove" click never persisted a deletion
 * (see Test/Unit/Model/Service/PasskeySessionServiceTest.php and the fix
 * plan for the "not logged out" / "still in Registered Passkeys" bug report).
 *
 * @covers \M2Oidc\OAuth\Controller\Adminhtml\Actions\Passkey\Delete
 */
class DeleteTest extends TestCase
{
    /** @var Context&MockObject */
    private Context $context;

    /** @var HttpRequest&MockObject */
    private HttpRequest $request;

    /** @var Auth&MockObject */
    private Auth $auth;

    /** @var JsonFactory&MockObject */
    private JsonFactory $jsonFactory;

    /** @var Json&MockObject */
    private Json $json;

    /** @var PasskeyCredentialRepository&MockObject */
    private PasskeyCredentialRepository $credentialRepository;

    /** @var OAuthUtility&MockObject */
    private OAuthUtility $oauthUtility;

    /** @var Delete */
    private Delete $controller;

    /** @var mixed[]|null Captured argument of the last Json::setData() call */
    private ?array $lastJsonData = null;

    protected function setUp(): void
    {
        $this->request = $this->createMock(HttpRequest::class);
        $this->auth = $this->createMock(Auth::class);

        $this->context = $this->createMock(Context::class);
        $this->context->method('getRequest')->willReturn($this->request);
        $this->context->method('getAuth')->willReturn($this->auth);

        $this->json = $this->createMock(Json::class);
        $this->json->method('setData')->willReturnCallback(function (array $data) {
            $this->lastJsonData = $data;
            return $this->json;
        });

        $this->jsonFactory = $this->createMock(JsonFactory::class);
        $this->jsonFactory->method('create')->willReturn($this->json);

        $this->credentialRepository = $this->createMock(PasskeyCredentialRepository::class);
        $this->oauthUtility = $this->createMock(OAuthUtility::class);

        $this->controller = new Delete(
            $this->context,
            $this->jsonFactory,
            $this->credentialRepository,
            $this->oauthUtility
        );
    }

    public function testNotAuthenticatedReturnsErrorAndLogs(): void
    {
        $this->auth->method('getUser')->willReturn(null);

        $this->credentialRepository->expects($this->never())->method('deleteOwnedCredential');
        $this->oauthUtility->expects($this->once())
            ->method('customlog')
            ->with($this->stringContains('not authenticated'));

        $this->controller->execute();

        $this->assertSame(['error' => 'Not authenticated.'], $this->lastJsonData);
    }

    public function testInvalidCredentialIdReturnsErrorAndLogs(): void
    {
        $adminUser = $this->createMock(User::class);
        $adminUser->method('getId')->willReturn(7);
        $this->auth->method('getUser')->willReturn($adminUser);

        $this->request->method('getParam')->with('credential_id', 0)->willReturn(0);

        $this->credentialRepository->expects($this->never())->method('deleteOwnedCredential');
        $this->oauthUtility->expects($this->once())
            ->method('customlog')
            ->with($this->stringContains('invalid credential_id'));

        $this->controller->execute();

        $this->assertSame(['error' => 'Invalid credential.'], $this->lastJsonData);
    }

    public function testCredentialNotOwnedReturnsErrorAndLogs(): void
    {
        $adminUser = $this->createMock(User::class);
        $adminUser->method('getId')->willReturn(7);
        $this->auth->method('getUser')->willReturn($adminUser);

        $this->request->method('getParam')->with('credential_id', 0)->willReturn(11);

        $this->credentialRepository->expects($this->once())
            ->method('deleteOwnedCredential')
            ->with(11, 'admin', 7)
            ->willReturn(false);

        $this->oauthUtility->expects($this->once())
            ->method('customlog')
            ->with($this->logicalAnd(
                $this->stringContains('#11'),
                $this->stringContains('admin #7')
            ));

        $this->controller->execute();

        $this->assertSame(['error' => 'Passkey not found.'], $this->lastJsonData);
    }

    public function testSuccessfulDeleteReturnsSuccessAndLogs(): void
    {
        $adminUser = $this->createMock(User::class);
        $adminUser->method('getId')->willReturn(7);
        $this->auth->method('getUser')->willReturn($adminUser);

        $this->request->method('getParam')->with('credential_id', 0)->willReturn(11);

        $this->credentialRepository->expects($this->once())
            ->method('deleteOwnedCredential')
            ->with(11, 'admin', 7)
            ->willReturn(true);

        $this->oauthUtility->expects($this->once())
            ->method('customlog')
            ->with($this->logicalAnd(
                $this->stringContains('#11'),
                $this->stringContains('removed by admin #7')
            ));

        $this->controller->execute();

        $this->assertSame(['success' => true], $this->lastJsonData);
    }
}
