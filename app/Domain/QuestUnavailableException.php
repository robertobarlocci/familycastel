<?php

declare(strict_types=1);

namespace FamilyCastel\Domain;

/** Thrown when a Sidequest cannot be accepted ("Someone was faster!"). */
final class QuestUnavailableException extends \RuntimeException
{
}
