<?php

declare(strict_types=1);

namespace ZeroForLaravel\Permissions;

use Illuminate\Database\Connection;
use LogicException;

/** Install under the exclusive rebuild lock, after repairing the grant rows. */
final class PermissionIndexLimit
{
    public const string EXCEEDED_SQL_STATE = 'ZP001';

    public static function install(Connection $db): void
    {
        $limit = config()->integer('permissions.max_index_rows', 100_000);
        if ($limit < 0) {
            throw new LogicException('permissions.max_index_rows must be non-negative.');
        }

        $count = $db->table('permission_index')->count();
        if ($count > $limit) {
            throw new LogicException("Permission projection exceeds {$limit} grants; reduce role fan-out or raise permissions.max_index_rows.");
        }

        // Transition tables count real inserts/deletes, not ON CONFLICT updates.
        // Only the deferred trigger touches the shared counter. A rollback (even
        // to a savepoint) undoes the delta along with the corresponding grants.
        $db->statement('CREATE TABLE IF NOT EXISTS permission_index_count (id boolean PRIMARY KEY CHECK (id), total bigint NOT NULL, maximum bigint NOT NULL)');
        $db->table('permission_index_count')->updateOrInsert(['id' => true], ['total' => $count, 'maximum' => $limit]);
        $sqlState = self::EXCEEDED_SQL_STATE;
        $db->unprepared(<<<SQL
            CREATE TABLE IF NOT EXISTS permission_index_pending (writer xid8 PRIMARY KEY, delta bigint NOT NULL);
            DELETE FROM permission_index_pending;

            CREATE OR REPLACE FUNCTION zero_permissions_count_changes() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                INSERT INTO permission_index_pending
                    SELECT pg_current_xact_id(), count(*) * CASE WHEN TG_OP = 'INSERT' THEN 1 ELSE -1 END
                    FROM changed HAVING count(*) > 0
                    ON CONFLICT (writer) DO UPDATE SET delta = permission_index_pending.delta + excluded.delta;
                RETURN NULL;
            END $$;
            CREATE OR REPLACE TRIGGER zero_permissions_count_insert AFTER INSERT ON permission_index
                REFERENCING NEW TABLE AS changed FOR EACH STATEMENT EXECUTE FUNCTION zero_permissions_count_changes();
            CREATE OR REPLACE TRIGGER zero_permissions_count_delete AFTER DELETE ON permission_index
                REFERENCING OLD TABLE AS changed FOR EACH STATEMENT EXECUTE FUNCTION zero_permissions_count_changes();

            CREATE OR REPLACE FUNCTION zero_permissions_apply_count() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE change bigint; result bigint; cap bigint;
            BEGIN
                DELETE FROM permission_index_pending WHERE writer = NEW.writer RETURNING delta INTO change;
                IF change <> 0 THEN
                    UPDATE permission_index_count SET total = total + change RETURNING total, maximum INTO STRICT result, cap;
                    IF result > cap THEN
                        RAISE EXCEPTION 'Permission projection exceeds % grants; reduce role fan-out or raise permissions.max_index_rows', cap USING ERRCODE = '{$sqlState}';
                    END IF;
                END IF;
                RETURN NULL;
            END $$;
            SQL);
        // Rebuild may itself have queued a delta using the already installed trigger.
        if (! $db->scalar("SELECT EXISTS (SELECT FROM pg_trigger WHERE tgrelid = 'permission_index_pending'::regclass AND tgname = 'zero_permissions_apply_count')")) {
            $db->unprepared('CREATE CONSTRAINT TRIGGER zero_permissions_apply_count AFTER INSERT ON permission_index_pending DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION zero_permissions_apply_count()');
        }
    }
}
