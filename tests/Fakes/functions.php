<?php

/**
 * Minimal fakes of the WordPress APIs used by the unit-tested classes of orchestra-email.
 */

declare(strict_types=1);

use Orchestra\Email\Tests\Fakes\WordPress;

class WP_Role
{
    /**
     * @param array<string, bool> $capabilities
     */
    public function __construct(public string $name, public array $capabilities)
    {
    }

    public function add_cap(string $cap, bool $grant = true): void
    {
        $this->capabilities[$cap] = $grant;
    }

    public function remove_cap(string $cap): void
    {
        unset($this->capabilities[$cap]);
    }

    public function has_cap(string $cap): bool
    {
        return !empty($this->capabilities[$cap]);
    }
}

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function get_role(string $role): ?WP_Role
{
    return WordPress::$roles[$role] ?? null;
}

/**
 * @param array<string, bool> $capabilities
 */
function add_role(string $role, string $displayName, array $capabilities = []): ?WP_Role
{
    if (isset(WordPress::$roles[$role])) {
        return null;
    }

    return WordPress::$roles[$role] = new WP_Role($role, $capabilities);
}

function remove_role(string $role): void
{
    unset(WordPress::$roles[$role]);
}

function get_option(string $option, mixed $default = false): mixed
{
    return array_key_exists($option, WordPress::$options) ? WordPress::$options[$option] : $default;
}

function update_option(string $option, mixed $value, ?bool $autoload = null): bool
{
    WordPress::$options[$option] = $value;

    return true;
}

function delete_option(string $option): bool
{
    unset(WordPress::$options[$option]);

    return true;
}

class WP_User
{
    public int $ID = 0;
    public string $user_email = '';
    public string $display_name = '';
    /** @var list<string> */
    public array $roles = [];
}

function apply_filters(string $hookName, mixed $value, mixed ...$args): mixed
{
    $callback = WordPress::$filters[$hookName] ?? null;

    return $callback === null ? $value : $callback($value, ...$args);
}

function get_userdata(int $userId): WP_User|false
{
    return WordPress::$users[$userId] ?? false;
}

function is_email(string $email): string|false
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? false : $email;
}

/**
 * Checks the capabilities in WordPress::$userCapabilities; arguments are ignored like for
 * primitive capabilities.
 */
function user_can(int $userId, string $capability, mixed ...$args): bool
{
    return in_array($capability, WordPress::$userCapabilities[$userId] ?? [], true);
}

function do_action(string $hookName, mixed ...$args): void
{
    $callback = WordPress::$filters[$hookName] ?? null;
    if ($callback !== null) {
        $callback(...$args);
    }
}

function home_url(string $path = ''): string
{
    return 'https://orchestra.example' . $path;
}

function wp_get_referer(): string|false
{
    return WordPress::$referer;
}

function wp_unslash(mixed $value): mixed
{
    return is_string($value) ? stripslashes($value) : $value;
}

/**
 * Like WordPress: only URLs of the site's host (or relative ones) are allowed.
 */
function wp_validate_redirect(string $location, string $fallback = ''): string
{
    $host = parse_url($location, PHP_URL_HOST);

    return $host === null || $host === 'orchestra.example' ? $location : $fallback;
}

function esc_html(string $text): string
{
    return \htmlspecialchars($text, ENT_QUOTES);
}

/**
 * Simplified: paragraphs at blank lines, line breaks within them.
 */
function wpautop(string $text): string
{
    $paragraphs = \preg_split('/\n\s*\n/', \trim($text)) ?: [];

    return \implode('', \array_map(static fn (string $p): string => '<p>' . \nl2br(\trim($p), false) . "</p>\n", $paragraphs));
}

/**
 * Simplified: keeps the allowed tags with their attributes, without checking the attributes.
 *
 * @param array<string, array<string, bool>> $allowedTags
 */
function wp_kses(string $html, array $allowedTags, array $protocols = []): string
{
    return \strip_tags($html, \array_keys($allowedTags));
}
