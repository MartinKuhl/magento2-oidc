<?php

declare(strict_types=1);

/**
 * Minimal Magento class / interface stubs for standalone PHPUnit tests.
 *
 * This file is included by Test/bootstrap.php ONLY when the full Magento
 * framework is NOT available (e.g. in CI where only phpunit/phpunit is
 * installed).  Each stub provides just enough structure so that:
 *
 *  1. PHP can parse and define M2Oidc\OAuth\* classes whose parent
 *     classes or implemented interfaces live in the Magento namespace.
 *  2. PHPUnit can create mock objects for those Magento types directly.
 *
 * Keep these stubs as minimal as possible; add new stubs only when a CI
 * test failure proves they are needed.
 */

// ─── Global Magento helpers ───────────────────────────────────────────────────

namespace {
    if (!function_exists('__')) {
        /**
         * Magento translation stub: returns the first argument unchanged.
         * The real __() returns a Phrase object; returning a plain string is
         * sufficient for CI tests that only pass the result to mocked methods.
         *
         * @param  string $text
         * @param  mixed  ...$args
         * @return string
         */
        function __(string $text, mixed ...$args): string
        {
            // Magento uses %1, %2, … placeholders, not printf format specifiers.
            // vsprintf() would throw ValueError on strings like 'value "%1"'.
            foreach ($args as $i => $arg) {
                $text = str_replace('%' . ($i + 1), (string) $arg, $text);
            }
            return $text;
        }
    }
}

// ─── PSR interfaces ──────────────────────────────────────────────────────────

namespace Psr\Log {
    /**
     * PSR-3 logger interface stub.
     * CI only installs phpunit/phpunit (no psr/log), so we provide this minimal
     * definition so that PHPUnit can create mock objects for LoggerInterface.
     */
    interface LoggerInterface
    {
        public function emergency(string|\Stringable $message, array $context = []): void;
        public function alert(string|\Stringable $message, array $context = []): void;
        public function critical(string|\Stringable $message, array $context = []): void;
        public function error(string|\Stringable $message, array $context = []): void;
        public function warning(string|\Stringable $message, array $context = []): void;
        public function notice(string|\Stringable $message, array $context = []): void;
        public function info(string|\Stringable $message, array $context = []): void;
        public function debug(string|\Stringable $message, array $context = []): void;
        /** @param mixed $level */
        public function log($level, string|\Stringable $message, array $context = []): void;
    }
}

// ─── Interfaces ────────────────────────────────────────────────────────────────

namespace Magento\Framework\App\Action {
    /** Marker interface — controller accepts GET requests. */
    interface HttpGetActionInterface
    {
    }

    /** Marker interface — controller accepts POST requests. */
    interface HttpPostActionInterface
    {
    }

    /**
     * Minimal stub for the frontend/base controller Action class
     * (\Magento\Framework\App\Action\Action) that BaseAction/ShowTestResults extend.
     */
    abstract class Action
    {
        /** @var Context|null */
        protected $context;

        /** @var mixed */
        protected $messageManager;

        /** @var mixed */
        protected $resultRedirectFactory;

        /** @var mixed */
        protected $resultFactory;

        /** @var mixed */
        protected $_eventManager;

        public function __construct(?Context $context = null)
        {
            $this->context = $context;
            $this->messageManager = $context?->getMessageManager();
            $this->resultRedirectFactory = $context?->getResultRedirectFactory();
            $this->resultFactory = $context?->getResultFactory();
            $this->_eventManager = $context?->getEventManager();
        }

        /** @return mixed */
        public function getRequest()
        {
            return $this->context?->getRequest();
        }

        /** @return mixed */
        public function getResponse()
        {
            return $this->context?->getResponse();
        }

        /**
         * @return mixed
         */
        abstract public function execute();
    }
}

namespace Magento\Framework\GraphQl\Config\Element {
    /** Stub for the Field config object passed to GraphQL resolvers. */
    class Field
    {
    }
}

namespace Magento\Framework\GraphQl\Exception {
    class GraphQlInputException extends \RuntimeException
    {
    }

    class GraphQlNoSuchEntityException extends \RuntimeException
    {
    }
}

namespace Magento\Framework\GraphQl\Schema\Type {
    /** Stub for the ResolveInfo object passed to GraphQL resolvers. */
    class ResolveInfo
    {
    }
}

namespace Magento\Framework\GraphQl\Query {
    interface ResolverInterface
    {
        /**
         * @param  array<mixed>|null $value
         * @param  array<mixed>|null $args
         * @return mixed
         */
        public function resolve(
            \Magento\Framework\GraphQl\Config\Element\Field $field,
            $context,
            \Magento\Framework\GraphQl\Schema\Type\ResolveInfo $info,
            ?array $value = null,
            ?array $args = null
        );
    }
}

namespace Magento\Framework\Message {
    interface ManagerInterface
    {
        public function addSuccessMessage($message);
        public function addErrorMessage($message);
        public function addWarningMessage($message);
    }
}

namespace Magento\Framework {
    /**
     * Minimal stub for Magento\Framework\Event data class (FQCN is
     * `Magento\Framework\Event` directly — it does NOT live under the
     * `Magento\Framework\Event` sub-namespace; `Observer`/`ManagerInterface` do).
     *
     * Provides dynamic getters used in observer tests (getObject, getCustomer, etc.).
     * Uses __call so that any get<X>() call returns null by default; individual test
     * methods override specific values via constructor injection.
     */
    class Event
    {
        /** @var array<string, mixed> */
        private array $data;

        public function __construct(array $data = [])
        {
            $this->data = $data;
        }

        /** @return mixed */
        public function getData(string $key = '')
        {
            return $key === '' ? $this->data : ($this->data[$key] ?? null);
        }

        /** @return mixed */
        public function getObject()
        {
            return $this->data['object'] ?? null;
        }

        /** @return mixed */
        public function getCustomer()
        {
            return $this->data['customer'] ?? null;
        }

        /** @return mixed */
        public function __call(string $name, array $args)
        {
            if (str_starts_with($name, 'get')) {
                $key = lcfirst(substr($name, 3));
                return $this->data[$key] ?? null;
            }
            return null;
        }
    }
}

namespace Magento\Framework\Event {
    interface ManagerInterface
    {
        public function dispatch($eventName, array $data = []);
    }

    interface ObserverInterface
    {
        public function execute(\Magento\Framework\Event\Observer $observer): void;
    }

    /**
     * Minimal stub for Magento\Framework\Event\Observer.
     *
     * The real Observer extends DataObject and is constructed with a plain
     * data array (e.g. `new Observer(['event' => $event])` or `new Observer([])`
     * in tests) — not with an Event instance directly.
     */
    class Observer
    {
        /** @var array<string, mixed> */
        private array $data;

        public function __construct(array $data = [])
        {
            $this->data = $data;
        }

        public function getEvent(): \Magento\Framework\Event
        {
            return $this->data['event'] ?? new \Magento\Framework\Event($this->data);
        }

        /** @return mixed */
        public function getData(string $key = '')
        {
            return $key === '' ? $this->data : ($this->data[$key] ?? null);
        }
    }
}

namespace Magento\Framework\App {
    interface CacheInterface
    {
        /** @return string|bool */
        public function load(string $identifier);
        public function save(string $data, string $identifier, array $tags = [], ?int $lifeTime = null): bool;
        public function remove(string $identifier): bool;
        public function clean(array $tags = []): bool;
    }

    /** Minimal stub — only mocked wholesale (createMock), no methods configured directly. */
    class ActionFlag
    {
    }

    /** Minimal stub — only mocked wholesale (createMock), no methods configured directly. */
    interface ViewInterface
    {
    }

    interface RequestInterface
    {
        /** @return string|null */
        public function getClientIp();

        /**
         * @param  string $key
         * @param  mixed  $default
         * @return mixed
         */
        public function getParam($key, $default = null);

        /** @return array<string, mixed> */
        public function getParams(): array;
    }

    interface ResponseInterface
    {
    }

    interface CsrfAwareActionInterface
    {
        public function createCsrfValidationException(
            RequestInterface $request
        ): ?\Magento\Framework\App\Request\InvalidRequestException;

        public function validateForCsrf(RequestInterface $request): ?bool;
    }

    /** Minimal stub — only mocked wholesale (createMock), no methods configured directly. */
    class ResourceConnection
    {
        /** @return \Magento\Framework\DB\Adapter\AdapterInterface */
        public function getConnection($resourceName = 'default')
        {
            return new class implements \Magento\Framework\DB\Adapter\AdapterInterface {
                public function select()
                {
                    return new \Magento\Framework\DB\Select();
                }

                public function fetchAll($sql, $bind = [])
                {
                    return [];
                }

                public function update($table, array $bind, $where = '')
                {
                    return 0;
                }

                public function beginTransaction()
                {
                    return $this;
                }

                public function commit()
                {
                    return $this;
                }

                public function rollBack()
                {
                    return $this;
                }
            };
        }

        /** @return string */
        public function getTableName($tableName, $resourceName = 'default')
        {
            return (string) $tableName;
        }
    }
}

namespace Magento\Framework\App\Response {
    interface RedirectInterface
    {
        /** @return string */
        public function getRedirectUrl();
    }
}

namespace Magento\Backend\Model\Auth\Credential {
    /**
     * Methods mirror the real StorageInterface so that #[\Override] attributes
     * on OidcCredentialAdapter resolve correctly (PHP 8.3+).
     */
    interface StorageInterface
    {
        public function authenticate($username, $password);
        public function login($username, $password);
        public function reload();
        public function hasAvailableResources();
        public function setHasAvailableResources($hasResources);
    }
}

// ─── Abstract base classes ────────────────────────────────────────────────────

namespace Magento\Framework\App\Helper {
    abstract class AbstractHelper
    {
        /** @var mixed */
        protected $scopeConfig;

        public function __construct($context = null)
        {
        }
    }
}

namespace Magento\Backend\App {
    /**
     * Stub for the admin-controller base class.
     * BaseAdminAction extends this and calls parent::__construct($context).
     */
    abstract class Action
    {
        /** @var mixed */
        protected $_authorization;

        /** @var mixed Set from Context::getAuth(), mirroring the real AbstractAction constructor. */
        protected $_auth;

        /** @var \Magento\Backend\App\Action\Context|null */
        private $context;

        public function __construct($context = null)
        {
            $this->context = $context;
            $this->_auth = $context ? $context->getAuth() : null;
        }

        /** Delegates to the Context mock so tests can wire the request object. */
        /** @return mixed */
        public function getRequest()
        {
            return $this->context ? $this->context->getRequest() : null;
        }

        /**
         * Abstract so BaseAdminAction can redeclare it as abstract too.
         * @return mixed
         */
        abstract public function execute();

        /** Subclasses override this to check ACL permissions. */
        protected function _isAllowed()
        {
            return true;
        }
    }
}

namespace Magento\Framework\Model {
    abstract class AbstractModel
    {
        /** Subclasses override this to call _init(). */
        protected function _construct(): void
        {
        }

        protected function _init(string $resourceModel): void
        {
        }

        /** @return mixed */
        public function getId()
        {
            return null;
        }

        /** @return mixed */
        public function getData($key = null, $default = null)
        {
            return $default;
        }

        /** @return static */
        public function setData($key, $value = null)
        {
            return $this;
        }

        /** @return static */
        public function addData(array $data)
        {
            return $this;
        }

        /** @return static */
        public function setId($id)
        {
            return $this;
        }
    }
}

namespace Magento\Framework\Model\ResourceModel\Db {
    abstract class AbstractDb
    {
        /** Subclasses override this to call _init(). */
        protected function _construct(): void
        {
        }

        protected function _init(string $mainTable, string $idFieldName): void
        {
        }

        public function load($object, $value, $field = null): void
        {
        }

        public function save($object): void
        {
        }
    }
}

namespace Magento\Framework\Model\ResourceModel\Db\Collection {
    abstract class AbstractCollection implements \IteratorAggregate
    {
        /** Subclasses override this to call _init(). */
        protected function _construct(): void
        {
        }

        protected function _init(string $model, string $resourceModel): void
        {
        }

        /** @return static */
        public function addFieldToFilter($field, $condition = null)
        {
            return $this;
        }

        /** @return static */
        public function setOrder($field, $direction = 'DESC')
        {
            return $this;
        }

        public function getSize(): int
        {
            return 0;
        }

        /** @return mixed */
        public function getFirstItem()
        {
            return null;
        }

        public function getIterator(): \Iterator
        {
            return new \ArrayIterator([]);
        }
    }
}

// ─── Concrete Magento classes (directly mocked in tests) ─────────────────────

namespace Magento\Framework\App\Request {
    class InvalidRequestException extends \Exception
    {
    }

    class Http implements \Magento\Framework\App\RequestInterface
    {
        /** @return array<string,mixed> */
        public function getParams(): array
        {
            return [];
        }

        /** @return string|null */
        public function getClientIp()
        {
            return null;
        }

        /** @return mixed */
        public function getParam($key, $default = null)
        {
            return $default;
        }
    }
}

namespace Magento\Framework\View\Result {
    class PageFactory
    {
        public function create(array $data = []): Page
        {
            return new Page();
        }
    }

    class Page
    {
        public function getConfig(): \Magento\Framework\View\Page\Config
        {
            return new \Magento\Framework\View\Page\Config();
        }
    }
}

namespace Magento\Framework\View\Page {
    class Config
    {
        public function getTitle(): Title
        {
            return new Title();
        }
    }

    class Title
    {
        public function prepend($text): void
        {
        }
    }
}

namespace Magento\Backend\App\Action {
    class Context
    {
        /** @return mixed */
        public function getRequest()
        {
            return null;
        }

        /** @return mixed */
        public function getMessageManager()
        {
            return null;
        }

        /** @return mixed */
        public function getAuth()
        {
            return null;
        }
    }
}

namespace Magento\Backend\Model {
    class Auth
    {
        /** @return mixed */
        public function getUser()
        {
            return null;
        }

        /** @return mixed */
        public function getAuthStorage()
        {
            return null;
        }

        /** @return bool */
        public function isLoggedIn()
        {
            return false;
        }
    }
}

namespace Magento\Backend\Model\Auth {
    /**
     * The real AuthSession extends DataObject, whose magic __call() resolves
     * undeclared getX()/setX() calls (e.g. getUser()) to getData()/setData().
     * PHPUnit's mock generator does not override magic methods, so __call
     * stays intact on mocks — only getData()/setData() need to be configured.
     */
    class Session
    {
        /** @return mixed */
        public function getData(string $key = '', $clear = false)
        {
            return null;
        }

        /** @return static */
        public function unsetData($key = null)
        {
            return $this;
        }

        /**
         * @param  string $key
         * @param  mixed  $value
         * @return static
         */
        public function setData($key, $value = null)
        {
            return $this;
        }

        /** @return mixed */
        public function __call(string $name, array $args)
        {
            if (str_starts_with($name, 'get')) {
                return $this->getData(lcfirst(substr($name, 3)));
            }
            if (str_starts_with($name, 'set')) {
                return $this->setData(lcfirst(substr($name, 3)), $args[0] ?? null);
            }
            return null;
        }
    }
}

namespace Magento\Framework\Math {
    class Random
    {
        public function getRandomString(int $length, ?string $chars = null): string
        {
            $chars ??= 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            $result = '';
            for ($i = 0; $i < $length; $i++) {
                $result .= $chars[random_int(0, strlen($chars) - 1)];
            }
            return $result;
        }
    }
}

namespace Magento\User\Model {
    class UserFactory
    {
        public function create(array $data = []): User
        {
            return new User();
        }
    }

    class User
    {
        /** @return mixed */
        public function getId()
        {
            return null;
        }

        public function getUsername(): string
        {
            return '';
        }

        /** @return mixed */
        public function getIsActive()
        {
            return false;
        }

        /** @return mixed */
        public function hasAssigned2Role($id)
        {
            return false;
        }

        /** @return static */
        public function loadByUsername(string $username)
        {
            return $this;
        }

        /** @return static */
        public function setUsername(string $username)
        {
            return $this;
        }

        /** @return static */
        public function setFirstname(string $firstname)
        {
            return $this;
        }

        /** @return static */
        public function setLastname(string $lastname)
        {
            return $this;
        }

        /** @return static */
        public function setEmail(string $email)
        {
            return $this;
        }

        /** @return static */
        public function setPassword(string $password)
        {
            return $this;
        }

        /** @return static */
        public function setIsActive($isActive)
        {
            return $this;
        }

        /** @return mixed */
        public function getFirstName()
        {
            return null;
        }

        /** @return mixed */
        public function getLastName()
        {
            return null;
        }

        /** @return mixed */
        public function getEmail()
        {
            return null;
        }

        /** @return mixed */
        public function getRoles()
        {
            return null;
        }

        /** @return static */
        public function setHasDataChanges(bool $flag)
        {
            return $this;
        }
    }
}

namespace Magento\User\Model\ResourceModel {
    class User
    {
        public function load($model, $value, $field = null): void
        {
        }

        public function save($model): void
        {
        }

        /** @return \Magento\Framework\DB\Adapter\AdapterInterface */
        public function getConnection()
        {
            return new class implements \Magento\Framework\DB\Adapter\AdapterInterface {
                public function select()
                {
                    return new \Magento\Framework\DB\Select();
                }

                public function fetchAll($sql, $bind = [])
                {
                    return [];
                }

                public function update($table, array $bind, $where = '')
                {
                    return 0;
                }

                public function beginTransaction()
                {
                    return $this;
                }

                public function commit()
                {
                    return $this;
                }

                public function rollBack()
                {
                    return $this;
                }
            };
        }

        public function recordLogin($model): void
        {
        }
    }
}

namespace Magento\User\Model\ResourceModel\User {
    class CollectionFactory
    {
        public function create(): Collection
        {
            return new Collection();
        }
    }

    class Collection
    {
        /** @return static */
        public function addFieldToFilter($field, $condition = null)
        {
            return $this;
        }

        public function getSize(): int
        {
            return 0;
        }

        /** @return mixed */
        public function getFirstItem()
        {
            return null;
        }
    }
}


namespace Magento\Customer\Model {
    class Customer
    {
        /** @return mixed */
        public function getId()
        {
            return null;
        }

        public function getEmail(): string
        {
            return '';
        }

        /** @return \Magento\Customer\Api\Data\CustomerInterface */
        public function getDataModel()
        {
            return new \Magento\Customer\Api\Data\CustomerData();
        }
    }

    /** Minimal stub for Magento\Customer\Model\Session. */
    class Session
    {
        /**
         * Matches real Magento\Framework\Session\SessionManager::getData()
         * signature exactly — PHPUnit's mock generator binds unpassed params
         * to these declared defaults, so `$clear`'s default must be `false`
         * (not `null`) for willReturnMap()'s argument-list matching to work.
         *
         * @return mixed
         */
        public function getData(string $key = '', $clear = false)
        {
            return null;
        }

        /** @return static */
        public function unsetData($key = null)
        {
            return $this;
        }

        /**
         * @param  string $key
         * @param  mixed  $value
         * @return static
         */
        public function setData($key, $value = null)
        {
            return $this;
        }
    }

    class CustomerFactory
    {
        public function create(array $data = []): Customer
        {
            return new Customer();
        }
    }
}

// ─── Encryption (used by SsrfUrlValidator / data patch tests) ──────────────────

namespace Magento\Framework\Encryption {
    /**
     * Encryptor interface stub — only the methods used by this module.
     */
    interface EncryptorInterface
    {
        /**
         * @param  string $data
         * @return string
         */
        public function encrypt($data);

        /**
         * @param  string $data
         * @return string
         */
        public function decrypt($data);
    }
}

// ─── Setup / data patches (used by EncryptPlaintextClientSecrets tests) ────────

namespace Magento\Framework\Setup {
    /**
     * Module data setup stub — only the methods used by this module's patches.
     */
    interface ModuleDataSetupInterface
    {
        /** @return \Magento\Framework\DB\Adapter\AdapterInterface */
        public function getConnection();

        /**
         * @param  string $tableName
         * @return string
         */
        public function getTable($tableName);

        /** @return void */
        public function startSetup();

        /** @return void */
        public function endSetup();
    }
}

namespace Magento\Framework\Setup\Patch {
    interface DependentPatchInterface
    {
        /** @return string[] */
        public static function getDependencies();
    }

    interface PatchInterface extends DependentPatchInterface
    {
        /** @return string[] */
        public function getAliases();

        /** @return $this */
        public function apply();
    }

    interface DataPatchInterface extends PatchInterface
    {
    }
}

// ─── DB adapter / select (used by EncryptPlaintextClientSecrets tests) ─────────

namespace Magento\Framework\DB {
    /**
     * Minimal SQL select builder stub.
     */
    class Select
    {
        /**
         * @param  mixed $name
         * @param  mixed $cols
         * @return $this
         */
        public function from($name, $cols = '*')
        {
            return $this;
        }
    }
}

namespace Magento\Framework\DB\Adapter {
    /**
     * DB adapter stub — only the methods used by this module's data patches.
     */
    interface AdapterInterface
    {
        /** @return \Magento\Framework\DB\Select */
        public function select();

        /**
         * @param  mixed $sql
         * @param  mixed $bind
         * @return mixed[]
         */
        public function fetchAll($sql, $bind = []);

        /**
         * @param  mixed $table
         * @param  mixed $bind
         * @param  mixed $where
         * @return int
         */
        public function update($table, array $bind, $where = '');

        /** @return $this */
        public function beginTransaction();

        /** @return $this */
        public function commit();

        /** @return $this */
        public function rollBack();
    }
}

// ─── Directory (used by CountryResolver / CustomerAttributeMapper tests) ───────

namespace Magento\Directory\Model {
    /**
     * `getCountryId()` is deliberately NOT declared here — real Magento only
     * defines `getId()`, and `getCountryId()` is resolved dynamically like any
     * other AbstractModel magic getter. Tests rely on `addMethods(['getCountryId'])`,
     * which requires the method to not already exist on the class.
     */
    class Country
    {
        /** @return mixed */
        public function getId()
        {
            return null;
        }

        /** @return mixed */
        public function getName()
        {
            return null;
        }

        /** @return mixed */
        public function __call(string $name, array $args)
        {
            return null;
        }
    }
}

namespace Magento\Directory\Model\ResourceModel\Country {
    class Collection implements \IteratorAggregate
    {
        /** @var \Magento\Directory\Model\Country[] */
        private array $items = [];

        /** @return static */
        public function addFieldToFilter($field, $condition = null)
        {
            return $this;
        }

        /** @return \Magento\Directory\Model\Country */
        public function getFirstItem()
        {
            return $this->items[0] ?? new \Magento\Directory\Model\Country();
        }

        /** @return string[] */
        public function getColumnValues(string $column): array
        {
            return [];
        }

        public function getIterator(): \Iterator
        {
            return new \ArrayIterator($this->items);
        }
    }

    class CollectionFactory
    {
        /** @return Collection */
        public function create(array $data = [])
        {
            return new Collection();
        }
    }
}

// ─── HTTP curl adapter (used by Curl / JwtVerifier / RpInitiatedLogoutService) ─

namespace Magento\Framework\HTTP\Adapter {
    /**
     * Minimal cURL adapter stub — only the methods used by Helper/Curl.php.
     */
    class Curl
    {
        /** @return void */
        public function setConfig(array $options)
        {
        }

        /**
         * @param  string $method
         * @param  string $url
         * @param  string $httpVer
         * @param  array  $headers
         * @param  mixed  $body
         * @return void
         */
        public function write($method, $url, $httpVer = '1.1', $headers = [], $body = '')
        {
        }

        public function read(): string
        {
            return '';
        }

        /** @return mixed */
        public function getInfo($type)
        {
            return null;
        }

        /** @return void */
        public function close()
        {
        }
    }

    class CurlFactory
    {
        public function create(): Curl
        {
            return new Curl();
        }
    }
}

// ─── Backend URL (used by CheckAttributeMappingActionIdpBindingTest) ──────────

namespace Magento\Backend\Model {
    interface UrlInterface
    {
        /**
         * @param  string $routePath
         * @param  array  $routeParams
         * @return string
         */
        public function getUrl($routePath = null, $routeParams = null);

        public function getAreaFrontName(): string;

        /** @return string */
        public function getBaseUrl(array $params = []);
    }
}

// ─── Customer API (used by ProcessUserAction / CustomerProfileSyncService) ────

namespace Magento\Customer\Api {
    interface CustomerRepositoryInterface
    {
        /**
         * @param  string   $email
         * @param  int|null $websiteId
         * @return \Magento\Customer\Api\Data\CustomerInterface
         */
        public function get($email, $websiteId = null);

        /**
         * @param  \Magento\Customer\Api\Data\CustomerInterface $customer
         * @param  string|null                                  $passwordHash
         * @return \Magento\Customer\Api\Data\CustomerInterface
         */
        public function save($customer, $passwordHash = null);
    }

    interface AddressRepositoryInterface
    {
        /**
         * @param  int $addressId
         * @return \Magento\Customer\Api\Data\AddressInterface
         */
        public function getById($addressId);

        /**
         * @param  \Magento\Customer\Api\Data\AddressInterface $address
         * @return \Magento\Customer\Api\Data\AddressInterface
         */
        public function save($address);
    }
}

// ─── Controller Result factories (used by BackChannelLogout / IdpInitiatedLogin)

namespace Magento\Framework\Controller {
    /**
     * Minimal stub for Magento\Framework\Controller\ResultFactory — only the
     * TYPE_* constants and create() actually used by this module.
     */
    class ResultFactory
    {
        public const TYPE_REDIRECT = 'redirect';
        public const TYPE_JSON = 'json';

        /** @return mixed */
        public function create(string $type, array $data = [])
        {
            return match ($type) {
                self::TYPE_REDIRECT => new \Magento\Framework\Controller\Result\Redirect(),
                self::TYPE_JSON => new \Magento\Framework\Controller\Result\Json(),
                default => null,
            };
        }
    }
}

namespace Magento\Framework\Controller {
    /** Marker interface implemented by all Result\* stubs below. */
    interface ResultInterface
    {
    }
}

namespace Magento\Framework\Controller\Result {
    class Json implements \Magento\Framework\Controller\ResultInterface
    {
        /** @return static */
        public function setData($data)
        {
            return $this;
        }

        /** @return static */
        public function setHttpResponseCode($code)
        {
            return $this;
        }
    }

    class JsonFactory
    {
        public function create(): Json
        {
            return new Json();
        }
    }

    class Redirect implements \Magento\Framework\Controller\ResultInterface
    {
        /** @return static */
        public function setPath($path, array $params = [])
        {
            return $this;
        }

        /** @return static */
        public function setUrl($url)
        {
            return $this;
        }
    }

    class RedirectFactory
    {
        public function create(): Redirect
        {
            return new Redirect();
        }
    }
}

// ─── Authentication exception (used by OidcCredentialAdapterTest) ────────────

namespace Magento\Framework\Exception {
    class AuthenticationException extends \Exception
    {
    }
}

// ─── Deployment config (used by RedisConnectionFactoryTest) ──────────────────

namespace Magento\Framework\App {
    class DeploymentConfig
    {
        /** @return mixed */
        public function get(string $configPath, $defaultValue = null)
        {
            return $defaultValue;
        }
    }
}

// ─── Generic data object (used by OidcProviderRepositoryTest) ────────────────

namespace Magento\Framework {
    /**
     * Minimal stub for Magento\Framework\DataObject — real callers just need
     * array-backed getData()/getDataByKey() semantics for building fixtures.
     */
    class DataObject
    {
        /** @param array<string, mixed> $data */
        public function __construct(private array $data = [])
        {
        }

        /** @return mixed */
        public function getData(string $key = '', $index = null)
        {
            return $key === '' ? $this->data : ($this->data[$key] ?? null);
        }

        /** @return mixed */
        public function getDataByKey(string $key)
        {
            return $this->data[$key] ?? null;
        }

        /** @return mixed */
        public function __call(string $name, array $args)
        {
            if (str_starts_with($name, 'get')) {
                $key = lcfirst(substr($name, 3));
                return $this->data[$key] ?? null;
            }
            if (str_starts_with($name, 'set')) {
                $key = lcfirst(substr($name, 3));
                $this->data[$key] = $args[0] ?? null;
                return $this;
            }
            return null;
        }
    }
}

// ─── Authorization / admin roles (used by AdminProfileSyncServiceTest) ───────

namespace Magento\Authorization\Model {
    class Role extends \Magento\Framework\Model\AbstractModel
    {
    }
}

namespace Magento\Authorization\Model\ResourceModel\Role {
    class Collection
    {
        /** @return static */
        public function addFieldToFilter($field, $condition = null)
        {
            return $this;
        }

        /** @return static */
        public function setPageSize(int $size)
        {
            return $this;
        }

        /** @return \Magento\Authorization\Model\Role */
        public function getFirstItem()
        {
            return new \Magento\Authorization\Model\Role();
        }
    }

    class CollectionFactory
    {
        public function create(): Collection
        {
            return new Collection();
        }
    }
}

// ─── HTTP response (used by OAuthLogoutObserverTest) ─────────────────────────

namespace Magento\Framework\HTTP\PhpEnvironment {
    class Response implements \Magento\Framework\App\ResponseInterface
    {
        /**
         * @param  string $url
         * @param  int    $code
         * @return static
         */
        public function setRedirect($url, $code = 302)
        {
            return $this;
        }

        /** @return void */
        public function sendResponse()
        {
        }
    }
}

namespace Magento\Framework\App\Response {
    /** Minimal stub — only mocked wholesale (createMock), no methods configured directly. */
    class Http implements \Magento\Framework\App\ResponseInterface
    {
    }
}

namespace Magento\Framework\Exception {
    class NoSuchEntityException extends \Exception
    {
    }
}

namespace Magento\Backend\App\Area {
    /** Minimal stub for Magento\Backend\App\Area\FrontNameResolver. */
    class FrontNameResolver
    {
        /** @return string */
        public function getFrontName(bool $checkCookie = false)
        {
            return 'admin';
        }
    }
}

namespace Magento\Store\Api\Data {
    interface WebsiteInterface
    {
        /** @return mixed */
        public function getId();
    }
}

namespace Magento\Customer\Api\Data {
    interface RegionInterface
    {
        /** @return static */
        public function setRegion($region);

        /** @return static */
        public function setRegionCode($regionCode);

        /** @return static */
        public function setRegionId($regionId);
    }

    class RegionInterfaceFactory
    {
        /** @return RegionInterface */
        public function create(array $data = [])
        {
            return new class implements RegionInterface {
                public function setRegion($region)
                {
                    return $this;
                }

                public function setRegionCode($regionCode)
                {
                    return $this;
                }

                public function setRegionId($regionId)
                {
                    return $this;
                }
            };
        }
    }
}

// ─── Cookie manager (used by OidcLogoutPluginTest) ───────────────────────────

namespace Magento\Framework\Stdlib {
    interface CookieManagerInterface
    {
        /** @return mixed */
        public function getCookie(string $name, $defaultValue = null);

        /**
         * @param  string $name
         * @param  string $value
         * @param  mixed  $metadata
         * @return void
         */
        public function setPublicCookie($name, $value, $metadata = null);

        /**
         * @param  string $name
         * @param  mixed  $metadata
         * @return void
         */
        public function deleteCookie($name, $metadata = null);
    }
}

// ─── Cookie metadata (used by OidcLogoutPluginTest / CheckAttributeMappingActionIdpBindingTest)

namespace Magento\Framework\Stdlib\Cookie {
    class PublicCookieMetadata
    {
        /** @return static */
        public function setDuration(int $duration)
        {
            return $this;
        }

        /** @return static */
        public function setPath(string $path)
        {
            return $this;
        }

        /** @return static */
        public function setHttpOnly(bool $httpOnly)
        {
            return $this;
        }

        /** @return static */
        public function setSecure(bool $secure)
        {
            return $this;
        }

        /** @return static */
        public function setSameSite(string $sameSite)
        {
            return $this;
        }
    }

    class CookieMetadataFactory
    {
        public function createPublicCookieMetadata(): PublicCookieMetadata
        {
            return new PublicCookieMetadata();
        }
    }
}

// ─── Action Context, base (used by BackChannelLogoutTest / IdpInitiatedLoginTest)

namespace Magento\Framework\App\Action {
    /**
     * Minimal stub for Magento\Framework\App\Action\Context — only the
     * getters actually invoked/configured by test doubles.
     */
    class Context
    {
        /** @return mixed */
        public function getRequest()
        {
            return null;
        }

        /** @return mixed */
        public function getResponse()
        {
            return null;
        }

        /** @return mixed */
        public function getResultRedirectFactory()
        {
            return null;
        }

        /** @return mixed */
        public function getResultFactory()
        {
            return null;
        }

        /** @return mixed */
        public function getMessageManager()
        {
            return null;
        }

        /** @return mixed */
        public function getEventManager()
        {
            return null;
        }

        /** @return mixed */
        public function getObjectManager()
        {
            return null;
        }

        /** @return mixed */
        public function getUrl()
        {
            return null;
        }

        /** @return mixed */
        public function getRedirect()
        {
            return null;
        }

        /** @return mixed */
        public function getActionFlag()
        {
            return null;
        }

        /** @return mixed */
        public function getView()
        {
            return null;
        }
    }
}

// ─── Store (used by ProcessUserActionRelayStateTest) ─────────────────────────

namespace Magento\Framework {
    interface UrlInterface
    {
        public const URL_TYPE_WEB = 'web';

        /**
         * @param  string|null $routePath
         * @param  array|null  $routeParams
         * @return string
         */
        public function getUrl($routePath = null, $routeParams = null);
    }

    interface ObjectManagerInterface
    {
        /**
         * @param  string $type
         * @return mixed
         */
        public function get($type);

        /**
         * @param  string $type
         * @param  array  $arguments
         * @return mixed
         */
        public function create($type, array $arguments = []);
    }
}

namespace Magento\Framework\Session {
    interface SessionManagerInterface
    {
        /** @return string */
        public function getSessionId();
    }
}

namespace Magento\Store\Model {
    class Store
    {
        /** @return mixed */
        public function getBaseUrl($type = null, $secure = null)
        {
            return '';
        }
    }

    interface StoreManagerInterface
    {
        /** @return Store */
        public function getStore($storeId = null);

        /** @return \Magento\Store\Api\Data\WebsiteInterface */
        public function getWebsite($websiteId = null);
    }
}

// ─── Customer data interfaces (used by CustomerUserCreatorAddressTest, etc.) ──

namespace Magento\Customer\Api\Data {
    interface CustomerInterface
    {
        /** @return mixed */
        public function getId();

        /** @return string|null */
        public function getEmail();

        /** @return static */
        public function setEmail($email);

        /** @return string|null */
        public function getFirstname();

        /** @return static */
        public function setFirstname($firstname);

        /** @return string|null */
        public function getLastname();

        /** @return static */
        public function setLastname($lastname);

        /** @return string|null */
        public function getDob();

        /** @return static */
        public function setDob($dob);

        /** @return int|null */
        public function getGender();

        /** @return static */
        public function setGender($gender);

        /** @return int|null */
        public function getDefaultBilling();

        /** @return int|null */
        public function getDefaultShipping();
    }

    /** Minimal concrete CustomerInterface used only as a Customer::getDataModel() return value. */
    class CustomerData implements CustomerInterface
    {
        /** @var array<string, mixed> */
        private array $data = [];

        /** @return mixed */
        public function getId()
        {
            return $this->data['id'] ?? null;
        }

        public function getEmail()
        {
            return $this->data['email'] ?? null;
        }

        public function setEmail($email)
        {
            $this->data['email'] = $email;
            return $this;
        }

        public function getFirstname()
        {
            return $this->data['firstname'] ?? null;
        }

        public function setFirstname($firstname)
        {
            $this->data['firstname'] = $firstname;
            return $this;
        }

        public function getLastname()
        {
            return $this->data['lastname'] ?? null;
        }

        public function setLastname($lastname)
        {
            $this->data['lastname'] = $lastname;
            return $this;
        }

        public function getDob()
        {
            return $this->data['dob'] ?? null;
        }

        public function setDob($dob)
        {
            $this->data['dob'] = $dob;
            return $this;
        }

        public function getGender()
        {
            return $this->data['gender'] ?? null;
        }

        public function setGender($gender)
        {
            $this->data['gender'] = $gender;
            return $this;
        }

        public function getDefaultBilling()
        {
            return $this->data['default_billing'] ?? null;
        }

        public function getDefaultShipping()
        {
            return $this->data['default_shipping'] ?? null;
        }
    }

    interface AddressInterface
    {
        /** @return mixed */
        public function getId();

        /** @return static */
        public function setCustomerId($customerId);

        /** @return static */
        public function setFirstname($firstname);

        /** @return static */
        public function setLastname($lastname);

        /** @return string[]|null */
        public function getStreet();

        /** @return static */
        public function setStreet(array $street);

        /** @return string|null */
        public function getCity();

        /** @return static */
        public function setCity($city);

        /** @return string|null */
        public function getPostcode();

        /** @return static */
        public function setPostcode($postcode);

        /** @return string|null */
        public function getCountryId();

        /** @return static */
        public function setCountryId($countryId);

        /** @return string|null */
        public function getRegion();

        /** @return static */
        public function setRegion($region);

        /** @return static */
        public function setRegionId($regionId);

        /** @return string|null */
        public function getTelephone();

        /** @return static */
        public function setTelephone($telephone);

        /** @return static */
        public function setIsDefaultBilling($isDefaultBilling);

        /** @return static */
        public function setIsDefaultShipping($isDefaultShipping);
    }

    class AddressInterfaceFactory
    {
        /** @return AddressInterface */
        public function create(array $data = [])
        {
            return new class implements AddressInterface {
                /** @var array<string, mixed> */
                private array $data = [];

                public function getId()
                {
                    return $this->data['id'] ?? null;
                }

                public function setCustomerId($customerId)
                {
                    $this->data['customer_id'] = $customerId;
                    return $this;
                }

                public function setFirstname($firstname)
                {
                    $this->data['firstname'] = $firstname;
                    return $this;
                }

                public function setLastname($lastname)
                {
                    $this->data['lastname'] = $lastname;
                    return $this;
                }

                public function getStreet()
                {
                    return $this->data['street'] ?? null;
                }

                public function setStreet(array $street)
                {
                    $this->data['street'] = $street;
                    return $this;
                }

                public function getCity()
                {
                    return $this->data['city'] ?? null;
                }

                public function setCity($city)
                {
                    $this->data['city'] = $city;
                    return $this;
                }

                public function getPostcode()
                {
                    return $this->data['postcode'] ?? null;
                }

                public function setPostcode($postcode)
                {
                    $this->data['postcode'] = $postcode;
                    return $this;
                }

                public function getCountryId()
                {
                    return $this->data['country_id'] ?? null;
                }

                public function setCountryId($countryId)
                {
                    $this->data['country_id'] = $countryId;
                    return $this;
                }

                public function getRegion()
                {
                    return $this->data['region'] ?? null;
                }

                public function setRegion($region)
                {
                    $this->data['region'] = $region;
                    return $this;
                }

                public function setRegionId($regionId)
                {
                    $this->data['region_id'] = $regionId;
                    return $this;
                }

                public function getTelephone()
                {
                    return $this->data['telephone'] ?? null;
                }

                public function setTelephone($telephone)
                {
                    $this->data['telephone'] = $telephone;
                    return $this;
                }

                public function setIsDefaultBilling($isDefaultBilling)
                {
                    $this->data['is_default_billing'] = $isDefaultBilling;
                    return $this;
                }

                public function setIsDefaultShipping($isDefaultShipping)
                {
                    $this->data['is_default_shipping'] = $isDefaultShipping;
                    return $this;
                }
            };
        }
    }
}
