<?php

declare(strict_types=1);

namespace ZeroForLaravel\Attributes;

use Attribute;

/**
 * Allow reads without a permission policy for this model.
 *
 * Use this only for data every application user may read. Column visibility
 * still applies, and mutations still require their own authorization.
 * Each model must declare this attribute itself; it is not inherited.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ZeroPublic {}
