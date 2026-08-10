<?php

declare(strict_types=1);

namespace FamilyCastel\Domain;

/**
 * Outcome of resolving a persistent-login cookie.
 *
 * `newToken` is null when the caller must NOT re-issue the cookie: that happens
 * on the rotation grace path, where a sibling request has already handed the
 * browser the live token and we hold only hashes, so the only thing we could
 * "re-issue" is the superseded token — which would overwrite the good cookie.
 */
final class RememberResult
{
    public function __construct(
        public readonly string $principalType,
        public readonly int $principalId,
        public readonly ?string $newToken,
    ) {
    }
}
