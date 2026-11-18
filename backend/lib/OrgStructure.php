<?php
/** Shared CRUD for departments and positions, always scoped to the user's organisation. */
class OrgStructure
{
    public static function handle(string $table, string $nameCol, string $entity): void
    {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
        $fk = $table === 'departments' ? 'department_id' : 'position_id';

        if (method() === 'GET') {
            $user = Auth::user(); // employees see these names on their profile too
            ok(q("SELECT t.*, (SELECT COUNT(*) FROM employees e WHERE e.$fk = t.id AND e.status = 'active') AS employee_count
                  FROM $table t WHERE t.organization_id = ? ORDER BY t.is_active DESC, t.$nameCol",
                [$user['organization_id']])->fetchAll());
        }

        $user = Auth::requirePermission('employees.manage');
        $in = method() === 'DELETE' ? [] : input();

        if (method() === 'POST') {
            require_fields($in, ['code', $nameCol]);
            q("INSERT INTO $table (organization_id, code, $nameCol, description) VALUES (?, ?, ?, ?)",
                [$user['organization_id'], strtoupper((string)clean($in['code'], 20)), clean($in[$nameCol], 100),
                 clean($in['description'] ?? null)]);
            $newId = (int)db()->lastInsertId();
            Audit::log($user, "$entity.created", $entity, $newId);
            ok(q("SELECT * FROM $table WHERE id = ?", [$newId])->fetch(), ucfirst($entity) . ' created', 201);
        }

        if (!$id || !q("SELECT 1 FROM $table WHERE id = ? AND organization_id = ?", [$id, $user['organization_id']])->fetch()) {
            throw new ApiError(ucfirst($entity) . ' not found.', 404);
        }

        if (method() === 'PUT') {
            require_fields($in, ['code', $nameCol]);
            q("UPDATE $table SET code = ?, $nameCol = ?, description = ?, is_active = ? WHERE id = ?",
                [strtoupper((string)clean($in['code'], 20)), clean($in[$nameCol], 100), clean($in['description'] ?? null),
                 isset($in['is_active']) ? (int)(bool)$in['is_active'] : 1, $id]);
            Audit::log($user, "$entity.updated", $entity, $id);
            ok(q("SELECT * FROM $table WHERE id = ?", [$id])->fetch(), ucfirst($entity) . ' updated');
        }

        if (method() === 'DELETE') {
            q("UPDATE $table SET is_active = 0 WHERE id = ?", [$id]);
            Audit::log($user, "$entity.deactivated", $entity, $id);
            ok(null, ucfirst($entity) . ' deactivated');
        }

        throw new ApiError('Method not allowed.', 405);
    }
}
