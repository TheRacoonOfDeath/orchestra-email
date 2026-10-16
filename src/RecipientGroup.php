<?php

declare(strict_types=1);

namespace Orchestra\Email;

use Closure;

/**
 * A group of users that can be emailed, e.g. "Accepted participants of Spring Concert 2027".
 *
 * Groups are registered by the domain plugins and computed from current data whenever they are used.
 */
final class RecipientGroup
{
    /**
     * @param string $id Unique, `orchestra_{plugin}_{name}`, e.g. `orchestra_projects_12_accepted`
     * @param string $category Heading the group is listed under, e.g. "Members" or a project name
     * @param Closure(): array<mixed> $recipients Returns the user IDs of the group; other values are ignored
     * @param string $capability Required to email the group, checked with `$capabilityArgs`.
     *        A meta capability allows rules per group, e.g. per project.
     * @param list<mixed> $capabilityArgs
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $category,
        private readonly Closure $recipients,
        public readonly string $capability,
        public readonly array $capabilityArgs = [],
    ) {
    }

    /**
     * @return list<int>
     */
    public function recipientIds(): array
    {
        $ids = [];
        foreach (($this->recipients)() as $id) {
            if (\is_int($id) && $id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function canBeEmailedBy(int $userId): bool
    {
        return $userId > 0 && \user_can($userId, $this->capability, ...$this->capabilityArgs);
    }
}
