<?php

declare(strict_types=1);

namespace ZeroForLaravel\Query;

/** PHP's explicit counterpart to an absent JavaScript value, never JSON null. */
enum MissingValue
{
    case Missing;
}
