<?php

declare(strict_types=1);

use Magento2\Rector\Src\ReplacePregSplitNullLimit;
use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/../Block',
        __DIR__ . '/../Controller',
        __DIR__ . '/../Helper',
        __DIR__ . '/../Model',
        __DIR__ . '/../Observer',
        __DIR__ . '/../Plugin',
        __DIR__ . '/../ViewModel',
    ])
    // Target the MINIMUM supported PHP version (see composer.json: 8.2-8.5) so
    // Rector never rewrites code into 8.3+ syntax that would fatal on PHP 8.2.
    ->withPhpVersion(PhpVersion::PHP_82)
    ->withSets([
        // Allgemeine Code-Qualität
        SetList::CODE_QUALITY,
        SetList::TYPE_DECLARATION,
        SetList::DEAD_CODE,
        // PHP 8.1+ Features nutzen
        SetList::PHP_81,
        SetList::PHP_82,
    ])
    ->withRules([
        // Magento-spezifische Rector-Regel aus magento-coding-standard
        ReplacePregSplitNullLimit::class,
    ])
    ->withSkip([
        // Template-Dateien ausschließen
        __DIR__ . '/../view',
        // Magento2 PHPCS requires @param and @var docblocks even with native type hints
        \Rector\DeadCode\Rector\ClassMethod\RemoveUselessParamTagRector::class,
        \Rector\DeadCode\Rector\Property\RemoveUselessVarTagRector::class,
        // Same reason (Rector 2.x rule; harmless "never registered" warning on Rector 1.x)
        \Rector\DeadCode\Rector\ClassMethod\RemoveMixedDocblockOverruledByNativeTypeRector::class,
        // Psalm requires explicit (string) casts in concatenation for type safety;
        // removing them would break Psalm strict type analysis
        \Rector\DeadCode\Rector\Concat\RemoveConcatAutocastRector::class,
        // Properties like $scopeConfig, $messageManager, $resultRedirectFactory are
        // inherited from Magento Framework base classes (AbstractHelper, Action, etc.)
        // and are not dynamic. Rector cannot resolve cross-package inheritance.
        \Rector\CodeQuality\Rector\Class_\CompleteDynamicPropertiesRector::class,
        // OidcCredentialAdapter / PasskeyCredentialAdapter implement __serialize()/
        // __unserialize() because they're stored in the PHP session; __unserialize()
        // is reached via PHP's native unserialize(), which bypasses __construct()
        // entirely. Rector only sees the constructor always assigning these nullable
        // properties and proposes dropping their `= null` default — but on a truly
        // unserialized object, restoreDependencies() reads $this->userFactory (etc.)
        // before anything ever assigns it, and a typed property with no default
        // throws "must not be accessed before initialization" instead of falling
        // through to the ObjectManager fallback. Keep the `= null` defaults.
        \Rector\DeadCode\Rector\Property\RemoveDefaultValueFromAssignedPropertyRector::class => [
            __DIR__ . '/../Model/Auth/OidcCredentialAdapter.php',
            __DIR__ . '/../Model/Auth/PasskeyCredentialAdapter.php',
        ],
    ]);
