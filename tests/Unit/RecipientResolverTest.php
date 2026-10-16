<?php

declare(strict_types=1);

namespace Orchestra\Email\Tests\Unit;

use Orchestra\Email\EmailService;
use Orchestra\Email\RecipientGroup;
use Orchestra\Email\RecipientResolver;
use Orchestra\Email\Tests\Fakes\WordPress;
use PHPUnit\Framework\TestCase;

final class RecipientResolverTest extends TestCase
{
    protected function setUp(): void
    {
        WordPress::reset();
        foreach ([1, 2, 3, 4] as $id) {
            WordPress::addUser($id, "user{$id}@example.com");
        }
        WordPress::addUser(5, 'not an address');
    }

    public function testMergesGroupsWithoutDuplicates(): void
    {
        $recipients = (new RecipientResolver())->resolve([self::group('a', [3, 1, 2]), self::group('b', [2, 4])]);

        $this->assertSame([1, 2, 3, 4], $recipients);
    }

    public function testSkipsUnknownUsersAndInvalidAddresses(): void
    {
        $this->assertSame([1], (new RecipientResolver())->resolve([self::group('a', [1, 5, 99])]));
    }

    public function testPluginsCanRemoveRecipients(): void
    {
        $seenGroups = [];
        $filter = static function (array $ids, array $groups) use (&$seenGroups): array {
            $seenGroups = $groups;

            return \array_values(\array_diff($ids, [2]));
        };
        WordPress::$filters[EmailService::RECIPIENTS_FILTER] = $filter;

        $recipients = (new RecipientResolver())->resolve([self::group('a', [1, 2]), self::group('b', [3])]);

        $this->assertSame([1, 3], $recipients);
        $this->assertSame(['orchestra_test_a', 'orchestra_test_b'], $seenGroups);
    }

    public function testFiltersCannotAddRecipients(): void
    {
        WordPress::$filters[EmailService::RECIPIENTS_FILTER] = static fn (array $ids): array => [...$ids, 4, 'x'];

        $this->assertSame([1], (new RecipientResolver())->resolve([self::group('a', [1])]));
    }

    /**
     * @param list<int> $ids
     */
    private static function group(string $name, array $ids): RecipientGroup
    {
        return new RecipientGroup('orchestra_test_' . $name, $name, 'Test', static fn (): array => $ids, 'cap');
    }
}
