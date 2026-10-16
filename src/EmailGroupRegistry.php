<?php

declare(strict_types=1);

namespace Orchestra\Email;

use InvalidArgumentException;

/**
 * The recipient groups registered by the Orchestra plugins during
 * {@see EmailService::REGISTER_GROUPS_ACTION}.
 */
final class EmailGroupRegistry
{
    private const ID_PATTERN = '/^orchestra_[a-z0-9]+(_[a-z0-9]+)+$/';

    /** @var array<string, RecipientGroup> */
    private array $groups = [];

    public function add(RecipientGroup $group): self
    {
        if (\preg_match(self::ID_PATTERN, $group->id) !== 1) {
            throw new InvalidArgumentException(\sprintf(
                'Invalid email group ID "%s". Expected the pattern orchestra_{plugin}_{name}.',
                $group->id
            ));
        }
        if (isset($this->groups[$group->id])) {
            throw new InvalidArgumentException(\sprintf('The email group "%s" is already registered.', $group->id));
        }

        $this->groups[$group->id] = $group;

        return $this;
    }

    /**
     * @return list<RecipientGroup> in registration order
     */
    public function all(): array
    {
        return \array_values($this->groups);
    }

    public function find(string $id): ?RecipientGroup
    {
        return $this->groups[$id] ?? null;
    }

    /**
     * @return list<RecipientGroup> the groups the user may email
     */
    public function forUser(int $userId): array
    {
        return \array_values(\array_filter(
            $this->groups,
            static fn (RecipientGroup $group): bool => $group->canBeEmailedBy($userId)
        ));
    }
}
