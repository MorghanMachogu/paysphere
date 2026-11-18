<?php
/**
 * Append-only audit trail (Module 4: audit trail management).
 * There is no API to edit or delete audit rows.
 */
class Audit
{
    public static function log(array $user, string $action, string $entity, ?int $entityId = null, array $details = []): void
    {
        q('INSERT INTO audit_log (organization_id, user_type, user_id, action, entity, entity_id, details, ip_address)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
            $user['organization_id'], $user['user_type'], $user['id'], $action, $entity, $entityId,
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null, client_ip(),
        ]);
    }
}
