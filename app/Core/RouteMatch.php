<?php

declare(strict_types=1);

namespace FamilyCastel\Core;

final class RouteMatch
{
    /** @param callable $handler */
    public function __construct(
        public readonly mixed $handler,
        /** @var array<string, string> */
        public readonly array $params,
    ) {
    }
}
