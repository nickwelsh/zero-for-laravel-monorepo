<?php

declare(strict_types=1);

namespace ZeroForLaravel;

use ZeroForLaravel\Query\ZeroQuery;

final class Zero
{
    public static function scroll(ZeroQuery $query): ZeroQuery
    {
        return $query->asScroll();
    }
}
