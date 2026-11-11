<?php

declare(strict_types=1);

namespace Orchestra\Email\Tests\Unit;

use Orchestra\Email\EmailService;
use Orchestra\Email\RecipientGroup;
use Orchestra\Email\Tests\Fakes\WordPress;
use PHPUnit\Framework\TestCase;

final class LogLabelTest extends TestCase
{
    protected function setUp(): void
    {
        WordPress::reset();
    }

    public function testTheLogRefersToASinglePersonInsteadOfKeepingTheirName(): void
    {
        $member = new RecipientGroup(
            'orchestra_members_member_7',
            'Mia',
            'Members',
            static fn (): array => [7],
            'cap',
            [7],
            false,
            person: 7
        );
        $all = new RecipientGroup('orchestra_members_all', 'All members', 'Members', static fn (): array => [], 'cap');

        $this->assertSame('Members: {user:7}', EmailService::logLabel($member));
        $this->assertStringNotContainsString('Mia', EmailService::logLabel($member));
        $this->assertSame('Members: All members', EmailService::logLabel($all));
    }

    public function testThePersonIsShownWithTheCurrentNameOrAsFormerMember(): void
    {
        WordPress::addUser(7, 'mia@example.org', 'Mia Meier');

        $this->assertSame('Members: Mia Meier', EmailService::displayLabel('Members: {user:7}'));
        $this->assertSame('Members: Former member', EmailService::displayLabel('Members: {user:8}'), 'deleted');
        $this->assertSame('Members: Former member', EmailService::displayLabel('Members: {user:0}'), 'erased');
        $this->assertSame('Members: All members', EmailService::displayLabel('Members: All members'));
    }
}
