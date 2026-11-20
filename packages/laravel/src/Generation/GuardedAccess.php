<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

use ZeroForLaravel\Permissions\PolicyPlan;

/** Shared wire contract for server page bindings and generated destinations. */
final class GuardedAccess
{
    /** @return array{status: int, scope?: 'page', revoked?: array{route: string, message?: string}} */
    public static function contract(PolicyPlan $plan): array
    {
        $access = ['status' => $plan->deniedStatus];
        if ($plan->classResource) {
            $access['scope'] = 'page';
        }

        if ($plan->revocation instanceof \ZeroForLaravel\Permissions\Revocation) {
            $plan->revocation->assertDestination();
            $access['revoked'] = $plan->revocation->toArray();
        }

        return $access;
    }
}
