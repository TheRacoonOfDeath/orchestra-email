<?php

declare(strict_types=1);

namespace Orchestra\Email\Automatic;

use InvalidArgumentException;

/**
 * The automatic emails registered by the Orchestra plugins during
 * {@see \Orchestra\Email\EmailService::REGISTER_AUTOMATIC_ACTION}.
 */
final class AutomaticEmailRegistry
{
    private const ID_PATTERN = '/^orchestra_[a-z0-9]+(_[a-z0-9]+)+$/';

    /** @var array<string, AutomaticEmail> */
    private array $emails = [];

    public function add(AutomaticEmail $email): self
    {
        if (\preg_match(self::ID_PATTERN, $email->id) !== 1) {
            throw new InvalidArgumentException(\sprintf(
                'Invalid automatic email ID "%s". Expected the pattern orchestra_{plugin}_{name}.',
                $email->id
            ));
        }
        if (isset($this->emails[$email->id])) {
            throw new InvalidArgumentException(\sprintf('The automatic email "%s" is already registered.', $email->id));
        }

        $this->emails[$email->id] = $email;

        return $this;
    }

    public function find(string $id): ?AutomaticEmail
    {
        return $this->emails[$id] ?? null;
    }

    /**
     * @return list<AutomaticEmail> the emails whose text the user may change, in registration order
     */
    public function editableBy(int $userId): array
    {
        return \array_values(\array_filter(
            $this->emails,
            static fn (AutomaticEmail $email): bool => $email->canBeEditedBy($userId)
        ));
    }
}
