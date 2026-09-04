<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Fixtures;

use Magento\User\Model\User;

/**
 * Test double for Magento\User\Model\User that explicitly declares setRoleId().
 *
 * Real Magento (and this module's hand-written Magento stub) only expose
 * setRoleId() dynamically via AbstractModel/DataObject's __call() magic
 * accessor — it is never a declared method. PHPUnit's MockBuilder cannot
 * configure an undeclared method via onlyMethods(); MockBuilder::addMethods(),
 * previously used for exactly this, was removed in PHPUnit 12 without
 * replacement. Declaring the method here (as a real method on a subclass)
 * lets onlyMethods() mock it like any other method.
 */
class UserWithRoleId extends User
{
    /**
     * @param  mixed $roleId
     * @return $this
     */
    public function setRoleId($roleId)
    {
        return $this;
    }
}
