<?php

declare(strict_types=1);

namespace Orchestra\Email\Tests\Unit;

use InvalidArgumentException;
use Orchestra\Email\EmailGroupRegistry;
use Orchestra\Email\RecipientGroup;
use Orchestra\Email\Tests\Fakes\WordPress;
use PHPUnit\Framework\TestCase;

final class EmailGroupRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        WordPress::reset();
    }

    public function testListsTheGroupsAUserMayEmail(): void
    {
        $registry = (new EmailGroupRegistry())
            ->add(self::group('orchestra_members_all', 'orchestra_members_email_send'))
            ->add(self::group('orchestra_projects_12_accepted', 'orchestra_projects_email_send'));
        WordPress::$userCapabilities[5] = ['orchestra_members_email_send'];

        $this->assertSame(['orchestra_members_all'], self::ids($registry->forUser(5)));
        $this->assertSame([], $registry->forUser(6));
        $this->assertSame([], $registry->forUser(0));
        $this->assertNotNull($registry->find('orchestra_projects_12_accepted'));
        $this->assertNull($registry->find('unknown'));
    }

    public function testRejectsInvalidIds(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new EmailGroupRegistry())->add(self::group('members', 'cap'));
    }

    public function testRejectsDuplicates(): void
    {
        $registry = (new EmailGroupRegistry())->add(self::group('orchestra_members_all', 'cap'));

        $this->expectException(InvalidArgumentException::class);
        $registry->add(self::group('orchestra_members_all', 'cap'));
    }

    public function testIgnoresInvalidRecipientIds(): void
    {
        $resolver = static fn (): array => [3, '4', 0, -1, 5];
        $group = new RecipientGroup('orchestra_members_all', 'All', 'Members', $resolver, 'cap');

        $this->assertSame([3, 5], $group->recipientIds());
    }

    private static function group(string $id, string $capability): RecipientGroup
    {
        return new RecipientGroup($id, 'Label', 'Category', static fn (): array => [], $capability);
    }

    /**
     * @param list<RecipientGroup> $groups
     * @return list<string>
     */
    private static function ids(array $groups): array
    {
        return \array_map(static fn (RecipientGroup $group): string => $group->id, $groups);
    }
}
