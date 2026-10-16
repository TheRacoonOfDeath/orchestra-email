<?php

declare(strict_types=1);

namespace Orchestra\Email\Tests\Fakes;

/**
 * In-memory state behind the fake WordPress functions in functions.php.
 */
final class WordPress
{
    /** @var array<string, \WP_Role> */
    public static array $roles = [];

    /** @var array<string, mixed> */
    public static array $options = [];

    /** @var array<int, \WP_User> */
    public static array $users = [];

    /** @var array<int, list<string>> user ID => capabilities */
    public static array $userCapabilities = [];

    /** @var array<string, callable> hook name => the only callback */
    public static array $filters = [];

    public static string|false $referer = false;

    public static function reset(): void
    {
        self::$roles = [];
        self::$options = [];
        self::$users = [];
        self::$userCapabilities = [];
        self::$filters = [];
        self::$referer = false;
        $_REQUEST = [];
    }

    public static function addUser(int $id, string $email, string $name = 'User'): \WP_User
    {
        $user = new \WP_User();
        $user->ID = $id;
        $user->user_email = $email;
        $user->display_name = $name;

        return self::$users[$id] = $user;
    }
}
