<?php

declare(strict_types=1);

namespace Dnr\Service;

final class ArchiveService
{
    /** @var array<string, string> */
    private const TABLES = [
        'contact' => 'contacts',
        'engagement' => 'engagements',
        'event' => 'engagements',
        'organization' => 'organizations',
    ];

    /**
     * @return array{contacts: int, engagements: int, inquiries: int}|null
     */
    public static function organizationActiveDependencyCounts(
        \mysqli $connection,
        int $organizationId
    ): ?array {
        if ($organizationId < 1) {
            return null;
        }

        $statement = $connection->prepare(
            'SELECT
                (SELECT COUNT(*) FROM contacts WHERE organization_id = ? AND is_deleted = 0)
                  AS active_contacts,
                (SELECT COUNT(*) FROM engagements WHERE organization_id = ? AND is_deleted = 0)
                  AS active_engagements,
                (SELECT COUNT(*) FROM booking_inquiries
                 WHERE organization_id = ?
                   AND stage IN (
                     \'new\', \'contacted\', \'qualified\', \'awaiting_details\', \'proposal_sent\'
                   )) AS active_inquiries'
        );
        if (!$statement) {
            return null;
        }

        $statement->bind_param('iii', $organizationId, $organizationId, $organizationId);
        if (!$statement->execute()) {
            $statement->close();
            return null;
        }
        $result = $statement->get_result();
        $dependencies = $result ? $result->fetch_assoc() : null;
        $statement->close();
        if (!$dependencies) {
            return null;
        }

        return [
            'contacts' => (int) ($dependencies['active_contacts'] ?? 0),
            'engagements' => (int) ($dependencies['active_engagements'] ?? 0),
            'inquiries' => (int) ($dependencies['active_inquiries'] ?? 0),
        ];
    }

    public static function contactActiveInquiryCount(
        \mysqli $connection,
        int $contactId
    ): ?int {
        if ($contactId < 1) {
            return null;
        }
        $statement = $connection->prepare(
            "SELECT COUNT(*) AS active_inquiries
             FROM booking_inquiries
             WHERE primary_contact_id = ?
               AND stage IN (
                 'new', 'contacted', 'qualified', 'awaiting_details', 'proposal_sent'
               )"
        );
        if (!$statement) {
            return null;
        }
        $statement->bind_param('i', $contactId);
        if (!$statement->execute()) {
            $statement->close();
            return null;
        }
        $result = $statement->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $statement->close();
        return $row ? (int) ($row['active_inquiries'] ?? 0) : null;
    }

    public static function setArchived(
        \mysqli $connection,
        string $entity,
        int $id,
        bool $isArchived
    ): bool {
        if (!isset(self::TABLES[$entity]) || $id < 1) {
            return false;
        }

        $archiveValue = $isArchived ? 1 : 0;
        $table = self::TABLES[$entity];
        $transactionStarted = false;
        try {
            $connection->begin_transaction();
            $transactionStarted = true;

            if ($entity === 'organization') {
                // Child creation locks this same row before inserting. Holding
                // it through the dependency check closes the archive/create race.
                $lockStatement = $connection->prepare(
                    'SELECT is_deleted FROM organizations WHERE id = ? FOR UPDATE'
                );
            } elseif (!$isArchived) {
                $childTable = $entity === 'contact' ? 'contacts' : 'engagements';
                $organizationJoin = $entity === 'contact' ? 'LEFT JOIN' : 'INNER JOIN';
                $lockStatement = $connection->prepare(
                    "SELECT child.is_deleted, o.is_deleted AS organization_is_deleted
                     FROM {$childTable} child
                     {$organizationJoin} organizations o ON o.id = child.organization_id
                     WHERE child.id = ? FOR UPDATE"
                );
            } else {
                $lockStatement = $connection->prepare(
                    "SELECT is_deleted FROM {$table} WHERE id = ? FOR UPDATE"
                );
            }
            if (!$lockStatement) {
                throw new \RuntimeException('Unable to prepare the archive lock.');
            }
            $lockStatement->bind_param('i', $id);
            if (!$lockStatement->execute()) {
                $lockStatement->close();
                throw new \RuntimeException('Unable to lock the record.');
            }
            $lockResult = $lockStatement->get_result();
            $lockedEntity = $lockResult ? $lockResult->fetch_assoc() : null;
            $lockStatement->close();
            if (!$lockedEntity
                || (int) $lockedEntity['is_deleted'] === $archiveValue
                || (!$isArchived && !empty($lockedEntity['organization_is_deleted']))
            ) {
                $connection->rollback();
                $transactionStarted = false;
                return false;
            }

            if ($entity === 'organization' && $isArchived) {
                $dependencies = self::organizationActiveDependencyCounts($connection, $id);
                if ($dependencies === null
                    || $dependencies['contacts'] > 0
                    || $dependencies['engagements'] > 0
                    || $dependencies['inquiries'] > 0
                ) {
                    $connection->rollback();
                    $transactionStarted = false;
                    return false;
                }
            }

            if ($entity === 'contact' && $isArchived) {
                $activeInquiryCount = self::contactActiveInquiryCount($connection, $id);
                if ($activeInquiryCount === null || $activeInquiryCount > 0) {
                    $connection->rollback();
                    $transactionStarted = false;
                    return false;
                }
            }

            $statement = $connection->prepare(
                "UPDATE {$table} SET is_deleted = ? WHERE id = ? AND is_deleted <> ?"
            );
            if (!$statement) {
                throw new \RuntimeException('Unable to prepare the archive change.');
            }
            $statement->bind_param('iii', $archiveValue, $id, $archiveValue);
            $success = $statement->execute() && $statement->affected_rows === 1;
            $statement->close();
            if (!$success) {
                throw new \RuntimeException('The archive state did not change.');
            }

            $connection->commit();
            $transactionStarted = false;
            return true;
        } catch (\Throwable $exception) {
            if ($transactionStarted) {
                $connection->rollback();
            }
            throw $exception;
        }
    }
}
