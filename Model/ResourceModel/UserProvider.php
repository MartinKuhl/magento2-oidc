<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Resource model for the m2oidc_oauth_user_provider mapping table.
 * Provides helpers for saving and retrieving the OIDC provider that created a user.
 */
class UserProvider extends AbstractDb
{
    /**
     * @inheritDoc
     */
    #[\Override]
    protected function _construct(): void
    {
        $this->_init('m2oidc_oauth_user_provider', 'id');
    }

    /**
     * Persist (or update) the OIDC provider that created a user.
     *
     * Uses INSERT ON DUPLICATE KEY UPDATE so that re-created users get
     * their provider_id refreshed without violating the UNIQUE constraint.
     *
     * @param string $userType   'customer' or 'admin'
     * @param int    $userId     Magento entity_id / user_id
     * @param int    $providerId m2oidc_oauth_client_apps.id
     */
    public function saveMapping(string $userType, int $userId, int $providerId): void
    {
        $connection = $this->getConnection();
        if ($connection === false) {
            return;
        }
        $connection->insertOnDuplicate(
            $this->getMainTable(),
            [
                'user_type'   => $userType,
                'user_id'     => $userId,
                'provider_id' => $providerId,
            ],
            ['provider_id'] // on duplicate key: update provider_id only
        );
    }

    /**
     * Remove the OIDC provider mapping for a deleted user.
     *
     * @param string $userType 'customer' or 'admin'
     * @param int    $userId
     */
    public function deleteMapping(string $userType, int $userId): void
    {
        $connection = $this->getConnection();
        if ($connection === false) {
            return;
        }
        $connection->delete(
            $this->getMainTable(),
            ['user_type = ?' => $userType, 'user_id = ?' => $userId]
        );
    }

    /**
     * Return OIDC provider info for a given user, or null if not created via OIDC.
     *
     * @param  string     $userType 'customer' or 'admin'
     * @param  int        $userId
     * @return array{display_name: string, created_at: string}|null
     */
    public function getProviderInfo(string $userType, int $userId): ?array
    {
        $connection = $this->getConnection();
        if ($connection === false) {
            return null;
        }
        $select = $connection->select()
            ->from(['up' => $this->getMainTable()], ['created_at'])
            ->join(
                ['p' => $this->getTable('m2oidc_oauth_client_apps')],
                'up.provider_id = p.id',
                ['display_name']
            )
            ->where('up.user_type = ?', $userType)
            ->where('up.user_id = ?', $userId)
            ->limit(1);

        /** @var array{display_name: string, created_at: string}|false $row */
        $row = $connection->fetchRow($select);
        return $row ?: null;
    }

    /**
     * Return the provider_id bound to a user, or null if no binding exists.
     *
     * @param string $userType 'customer' or 'admin'
     * @param int    $userId
     */
    public function getBoundProviderId(string $userType, int $userId): ?int
    {
        $connection = $this->getConnection();
        if ($connection === false) {
            return null;
        }
        $select = $connection->select()
            ->from($this->getMainTable(), ['provider_id'])
            ->where('user_type = ?', $userType)
            ->where('user_id = ?', $userId)
            ->limit(1);
        // AdapterInterface::fetchOne()'s docblock claims string, but the real
        // Zend_Db/PDO-backed implementation returns false on no matching row.
        $result = $connection->fetchOne($select);
        // @phpstan-ignore-next-line notIdentical.alwaysTrue
        return $result !== false && $result !== '' ? (int) $result : null;
    }

    /**
     * Delete a session activity record by its primary key.
     *
     * @param int $id m2oidc_oauth_user_provider.id
     */
    public function deleteById(int $id): void
    {
        $connection = $this->getConnection();
        if ($connection === false) {
            return;
        }
        $connection->delete($this->getMainTable(), ['id = ?' => $id]);
    }

    /**
     * Count users of a given type that were created via a specific provider.
     *
     * @param  string $userType   'customer' or 'admin'
     * @param  int    $providerId m2oidc_oauth_client_apps.id
     */
    public function countByTypeAndProvider(string $userType, int $providerId): int
    {
        $connection = $this->getConnection();
        if ($connection === false) {
            return 0;
        }
        $select = $connection->select()
            ->from($this->getMainTable(), [new \Zend_Db_Expr('COUNT(*)')])
            ->where('user_type = ?', $userType)
            ->where('provider_id = ?', $providerId);
        return (int) $connection->fetchOne($select);
    }
}
