<?php

/**
 * Removes the email log, the queue, the attachments and the options when the plugin is deleted.
 *
 * Deactivating the plugin keeps them.
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use Orchestra\Email\Attachment\AttachmentStorage;
use Orchestra\Email\EmailSchema;
use Orchestra\Email\EmailSender;
use Orchestra\Email\EmailService;

$orchestraEmailDatabase = $GLOBALS['wpdb'];
if ($orchestraEmailDatabase instanceof wpdb) {
    (new EmailSchema($orchestraEmailDatabase))->drop();
}
wp_clear_scheduled_hook(EmailSender::CRON_HOOK);
wp_clear_scheduled_hook(EmailService::PURGE_CRON_HOOK);
delete_option('orchestra_core_email_lock');
AttachmentStorage::fromWordPress()->removeDirectory();
delete_option(AttachmentStorage::OPTION_DIRECTORY_SUFFIX);
