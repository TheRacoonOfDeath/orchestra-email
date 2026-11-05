<?php

declare(strict_types=1);

namespace Orchestra\Email\Tests\Unit;

use Orchestra\Email\Automatic\AutomaticEmail;
use Orchestra\Email\Automatic\AutomaticEmailRegistry;
use Orchestra\Email\Automatic\TemplateRenderer;
use Orchestra\Email\Automatic\TemplateStore;
use Orchestra\Email\EmailService;
use Orchestra\Email\RecipientResolver;
use Orchestra\Email\Tests\Fakes\WordPress;
use PHPUnit\Framework\TestCase;

final class AutomaticEmailTest extends TestCase
{
    protected function setUp(): void
    {
        WordPress::reset();
    }

    public function testFillsThePlaceholdersAndKeepsUnknownOnes(): void
    {
        $this->assertSame(
            '<p>Hello Anna &amp; Ben, see <ul><li>Bass</li></ul> {typo}</p>',
            TemplateRenderer::body(
                '<p>Hello {name}, see {changes} {typo}</p>',
                ['name' => 'Anna &amp; Ben', 'changes' => '<ul><li>Bass</li></ul>']
            )
        );
    }

    public function testTheSubjectIsPlainText(): void
    {
        $this->assertSame(
            'Your parts in Rock & Pop have changed',
            TemplateRenderer::subject('Your parts in {project} have changed', ['project' => '<b>Rock &amp; Pop</b>'])
        );
    }

    public function testUsesTheDefaultUntilATextIsSaved(): void
    {
        $store = new TemplateStore();
        $email = self::email();

        $this->assertSame(
            ['subject' => 'Default {project}', 'body' => '<p>Hi</p>', 'custom' => false],
            $store->template($email)
        );

        $store->save($email->id, 'Mine', '<p>Mine</p>');
        $this->assertSame(['subject' => 'Mine', 'body' => '<p>Mine</p>', 'custom' => true], $store->template($email));

        $store->reset($email->id);
        $this->assertFalse($store->template($email)['custom']);
    }

    public function testOnlyEditableEmailsAreOffered(): void
    {
        WordPress::$userCapabilities[1] = ['orchestra_test_edit'];
        $registry = (new AutomaticEmailRegistry())->add(self::email());

        $this->assertCount(1, $registry->editableBy(1));
        $this->assertSame([], $registry->editableBy(2));
    }

    public function testRejectsInvalidIds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AutomaticEmailRegistry())->add(new AutomaticEmail('changed', 'L', 'D', 'cap', 'S', 'B', []));
    }

    public function testPluginsCanLeaveOutRecipientsOfAutomaticEmails(): void
    {
        foreach ([1, 2, 3] as $id) {
            WordPress::addUser($id, "user{$id}@example.com");
        }
        WordPress::addUser(4, 'not an address');
        $seen = null;
        $filter = static function (array $ids, string $automaticId) use (&$seen): array {
            $seen = $automaticId;

            return \array_values(\array_diff($ids, [2]));
        };
        WordPress::$filters[EmailService::AUTOMATIC_RECIPIENTS_FILTER] = $filter;
        WordPress::$filters[EmailService::RECIPIENTS_FILTER] = static fn (array $ids): array => \array_diff($ids, [3]);

        $resolver = new RecipientResolver();

        $this->assertSame([1], $resolver->automatic('orchestra_test_changed', [3, 2, 1, 4]));
        $this->assertSame('orchestra_test_changed', $seen);
        $this->assertSame([1, 4], $resolver->stillReceiving([1, 2, 3, 4], 'orchestra_test_changed'));
        $this->assertSame([1, 2, 4], $resolver->stillReceiving([1, 2, 3, 4]), 'written emails skip the opt-out');
    }

    private static function email(): AutomaticEmail
    {
        return new AutomaticEmail(
            'orchestra_test_changed',
            'Changed',
            'When something changed',
            'orchestra_test_edit',
            'Default {project}',
            '<p>Hi</p>',
            ['project' => 'The project']
        );
    }
}
