<?php

declare(strict_types=1);

namespace Orchestra\Email;

use LogicException;
use Orchestra\Core\Ui\Menu;
use Orchestra\Core\Ui\MenuItem;
use Orchestra\Email\Admin\ComposePage;
use Orchestra\Email\Ui\EmailController;
use Orchestra\Email\Ui\EmailShortcode;
use Orchestra\Email\Ui\EmailView;
use wpdb;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

final class Plugin
{
    /**
     * Update metadata, attached to every GitHub release by build-plugin.sh. This download link
     * always points to the latest release and, unlike the GitHub API, has no rate limit for
     * requests without a token.
     */
    private const UPDATE_METADATA_URL =
        'https://github.com/TheRacoonOfDeath/orchestra-email/releases/latest/download/update.json';

    /** orchestra-core before 0.7 contained the email service itself. */
    private const LEGACY_CORE_EMAIL = 'Orchestra\Core\Email\EmailService';

    private static ?self $instance = null;

    private readonly EmailSchema $schema;
    private readonly EmailSender $sender;
    private readonly EmailService $email;

    private function __construct(private readonly string $pluginFile, wpdb $wpdb)
    {
        $this->schema = new EmailSchema($wpdb);
        $log = new EmailLog($wpdb, $this->schema);
        $this->sender = new EmailSender($log);
        $this->email = new EmailService($log, $this->sender, new RecipientResolver());
    }

    /**
     * Starts the plugin, unless orchestra-core is missing or still sends emails itself: two email
     * services would send every queued email twice.
     */
    public static function boot(string $pluginFile): ?self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        PucFactory::buildUpdateChecker(self::UPDATE_METADATA_URL, $pluginFile, 'orchestra-email');
        if (!\class_exists(Menu::class) || \class_exists(self::LEGACY_CORE_EMAIL)) {
            \add_action('admin_notices', [self::class, 'showCoreNotice']);
            return null;
        }

        $wpdb = $GLOBALS['wpdb'] ?? null;
        if (!$wpdb instanceof wpdb) {
            throw new LogicException('orchestra-email requires a WordPress database connection.');
        }

        self::$instance = new self($pluginFile, $wpdb);
        self::$instance->register();

        return self::$instance;
    }

    /**
     * The running plugin. Other plugins check that this class exists, then use {@see email()}.
     */
    public static function instance(): self
    {
        return self::$instance ?? throw new LogicException('orchestra-email is not running.');
    }

    /**
     * Whether the email service runs; false while orchestra-core is missing or too old.
     */
    public static function running(): bool
    {
        return self::$instance !== null;
    }

    public function email(): EmailService
    {
        return $this->email;
    }

    private function register(): void
    {
        \add_action('init', [$this, 'loadTextDomain'], 0);
        \add_action('init', [$this, 'setUp'], 1);
        \add_action(EmailSender::CRON_HOOK, [$this->sender, 'sendBatch']);
        \add_action(EmailService::PURGE_CRON_HOOK, [$this->email, 'purgeLog']);
        \add_action('deleted_user', [$this->email, 'anonymizeUser']);

        $view = new EmailView($this->email);
        (new EmailController($this->email))->register();
        (new EmailShortcode($view))->register();
        \add_action(Menu::REGISTER_ACTION, function (Menu $menu): void {
            $menu->add(new MenuItem(
                EmailShortcode::TAG,
                \__('Email', 'orchestra-email'),
                \__('Write to members, sections and project participants.', 'orchestra-email'),
                fn (int $userId): bool => $this->email->groups()->forUser($userId) !== [],
                80
            ));
        });
        if (\is_admin()) {
            (new ComposePage($view))->register();
        }
    }

    /**
     * @internal Hooked to `init`, before anything is translated.
     */
    public function loadTextDomain(): void
    {
        \load_plugin_textdomain('orchestra-email', false, \dirname(\plugin_basename($this->pluginFile)) . '/languages');
    }

    /**
     * @internal Hooked to `init`.
     */
    public function setUp(): void
    {
        $this->schema->migrateIfOutdated();
        if (\wp_next_scheduled(EmailService::PURGE_CRON_HOOK) === false) {
            \wp_schedule_event(\time() + HOUR_IN_SECONDS, 'daily', EmailService::PURGE_CRON_HOOK);
        }
        // Not on every page view: the check queries the queue.
        if (\is_admin() || \wp_doing_cron()) {
            $this->sender->resumeIfStalled();
        }
    }

    /**
     * @internal Hooked to `admin_notices` when the plugin cannot run.
     */
    public static function showCoreNotice(): void
    {
        if (!\current_user_can('activate_plugins')) {
            return;
        }

        $message = \class_exists(self::LEGACY_CORE_EMAIL)
            ? \__(
                'Orchestra Email needs Orchestra Core 0.7 or later. Please update Orchestra Core; until then,'
                    . ' Orchestra Core sends the emails itself.',
                'orchestra-email'
            )
            : \__('Orchestra Email needs Orchestra Core. Please install and activate it.', 'orchestra-email');

        echo '<div class="notice notice-error"><p>' . \esc_html($message) . '</p></div>';
    }
}
