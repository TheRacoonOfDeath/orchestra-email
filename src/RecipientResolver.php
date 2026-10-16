<?php

declare(strict_types=1);

namespace Orchestra\Email;

use WP_User;

/**
 * Turns groups into the users who receive an email: without duplicates, filtered by the plugins
 * (orchestra-members removes inactive members) and only users with a valid email address.
 */
final class RecipientResolver
{
    /**
     * @param list<RecipientGroup> $groups
     * @return list<int> user IDs, ascending
     */
    public function resolve(array $groups): array
    {
        $ids = [];
        foreach ($groups as $group) {
            foreach ($group->recipientIds() as $id) {
                $ids[$id] = $id;
            }
        }

        $filtered = \apply_filters(
            EmailService::RECIPIENTS_FILTER,
            \array_values($ids),
            \array_map(static fn (RecipientGroup $group): string => $group->id, $groups)
        );

        $recipients = [];
        foreach (\is_array($filtered) ? $filtered : [] as $id) {
            if (\is_int($id) && isset($ids[$id]) && self::hasEmail($id)) {
                $recipients[] = $id;
            }
        }
        \sort($recipients);

        return \array_values(\array_unique($recipients));
    }

    private static function hasEmail(int $userId): bool
    {
        $user = \get_userdata($userId);

        return $user instanceof WP_User && \is_email($user->user_email) !== false;
    }
}
