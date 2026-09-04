<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Fixtures;

use Magento\Customer\Model\Customer;

/**
 * Test double for Magento\Customer\Model\Customer that explicitly declares
 * the legacy-model setters used during OIDC address/profile creation.
 *
 * Real Magento (and this module's hand-written Magento stub) only expose
 * setWebsiteId()/setEmail()/setFirstname()/setLastname()/setDob()/setGender()/
 * setGroupId() dynamically via AbstractModel/DataObject's __call() magic
 * accessor — none of them are declared methods. PHPUnit's MockBuilder cannot
 * configure an undeclared method via onlyMethods(); MockBuilder::addMethods(),
 * previously used for exactly this, was removed in PHPUnit 12 without
 * replacement. Declaring the methods here (as real methods on a subclass)
 * lets onlyMethods() mock them like any other method.
 */
class CustomerWithSetters extends Customer
{
    /**
     * @param  mixed $websiteId
     * @return $this
     */
    public function setWebsiteId($websiteId)
    {
        return $this;
    }

    /**
     * @param  mixed $email
     * @return $this
     */
    public function setEmail($email)
    {
        return $this;
    }

    /**
     * @param  mixed $firstname
     * @return $this
     */
    public function setFirstname($firstname)
    {
        return $this;
    }

    /**
     * @param  mixed $lastname
     * @return $this
     */
    public function setLastname($lastname)
    {
        return $this;
    }

    /**
     * @param  mixed $dob
     * @return $this
     */
    public function setDob($dob)
    {
        return $this;
    }

    /**
     * @param  mixed $gender
     * @return $this
     */
    public function setGender($gender)
    {
        return $this;
    }

    /**
     * @param  mixed $groupId
     * @return $this
     */
    public function setGroupId($groupId)
    {
        return $this;
    }
}
