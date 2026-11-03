<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;
use ZeroForLaravel\Tests\Fixtures\OwnerPermissions;

abstract class OwnerPostgresTestCase extends PostgresTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OwnerPermissions::configure();
        OwnerPermissions::install();
    }

    protected function tearDown(): void
    {
        if (isset($this->app) && $this->app !== null && Schema::hasTable('permission_models')) {
            foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'permission_%'") as $row) {
                if ($row instanceof stdClass && is_string($row->tablename)) {
                    DB::statement('DROP TABLE "'.$row->tablename.'" CASCADE');
                }
            }

            foreach (DB::select("SELECT proname, pg_get_function_identity_arguments(oid) AS args FROM pg_proc WHERE pronamespace = 'public'::regnamespace AND proname LIKE 'zero_permissions_%'") as $row) {
                if ($row instanceof stdClass && is_string($row->proname) && is_string($row->args)) {
                    DB::statement('DROP FUNCTION IF EXISTS "'.$row->proname.'"('.$row->args.') CASCADE');
                }
            }

            Schema::dropIfExists('users');
        }

        parent::tearDown();
    }
}
