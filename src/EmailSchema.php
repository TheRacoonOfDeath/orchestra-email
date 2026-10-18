<?php

declare(strict_types=1);

namespace Orchestra\Email;

use Orchestra\Core\Database\Row;
use wpdb;

/**
 * Creates and migrates the email tables.
 *
 * The tables and the version option are named after orchestra-core, which contained the email
 * service before 0.7: sites that update keep their log and queue.
 */
final class EmailSchema
{
    /** Increase whenever a table definition changes; dbDelta() applies the difference. */
    public const VERSION = 3;

    public const OPTION_VERSION = 'orchestra_core_schema_version';

    public function __construct(private readonly wpdb $wpdb)
    {
    }

    public function messagesTable(): string
    {
        return $this->wpdb->prefix . 'orchestra_core_email_log';
    }

    public function queueTable(): string
    {
        return $this->wpdb->prefix . 'orchestra_core_email_queue';
    }

    /** New in version 2, so named after this plugin. */
    public function attachmentsTable(): string
    {
        return $this->wpdb->prefix . 'orchestra_email_attachments';
    }

    public function migrateIfOutdated(): void
    {
        $installed = \get_option(self::OPTION_VERSION, 0);
        if (\is_numeric($installed) && (int) $installed >= self::VERSION) {
            return;
        }

        $this->migrate(\is_numeric($installed) ? (int) $installed : 0);
    }

    /**
     * Drops all tables. Only for uninstalling.
     */
    public function drop(): void
    {
        $this->wpdb->query("DROP TABLE IF EXISTS {$this->attachmentsTable()}");
        $this->wpdb->query("DROP TABLE IF EXISTS {$this->queueTable()}");
        $this->wpdb->query("DROP TABLE IF EXISTS {$this->messagesTable()}");
        \delete_option(self::OPTION_VERSION);
    }

    private function migrate(int $installed): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charsetCollate = $this->wpdb->get_charset_collate();

        // The body and the reply address are kept only until the email is sent; the log keeps the metadata.
        // dbDelta() is picky: one column per line, two spaces after PRIMARY KEY, KEY instead of INDEX.
        \dbDelta("CREATE TABLE {$this->messagesTable()} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  sender_id bigint(20) unsigned DEFAULT NULL,
  subject varchar(255) NOT NULL,
  body longtext DEFAULT NULL,
  reply_to varchar(100) DEFAULT NULL,
  group_labels text NOT NULL,
  recipients int(11) NOT NULL DEFAULT 0,
  sent int(11) NOT NULL DEFAULT 0,
  failed int(11) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL,
  created_at datetime NOT NULL,
  completed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at)
) {$charsetCollate};");

        \dbDelta("CREATE TABLE {$this->queueTable()} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  message_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  attempts int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY message_id (message_id)
) {$charsetCollate};");

        // Attachments are kept until their email has been sent; message_id is NULL while writing.
        \dbDelta("CREATE TABLE {$this->attachmentsTable()} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  message_id bigint(20) unsigned DEFAULT NULL,
  uploaded_by bigint(20) unsigned NOT NULL,
  storage_name char(36) NOT NULL,
  file_name varchar(255) NOT NULL,
  mime_type varchar(100) NOT NULL,
  size bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY message_id (message_id),
  KEY uploaded_by (uploaded_by)
) {$charsetCollate};");

        if ($installed === 1) {
            $this->convertPlainTextBodies();
        }

        \update_option(self::OPTION_VERSION, self::VERSION, true);
    }

    /**
     * Version 1 stored plain text; emails still being sent during the update get HTML, so that
     * their line breaks are kept.
     */
    private function convertPlainTextBodies(): void
    {
        $rows = Row::all($this->wpdb->get_results(
            $this->wpdb->prepare('SELECT id, body FROM %i WHERE body IS NOT NULL', $this->messagesTable()),
            ARRAY_A
        ));
        foreach ($rows as $row) {
            if (\is_string($row['body'] ?? null) && \is_numeric($row['id'] ?? null)) {
                $this->wpdb->update(
                    $this->messagesTable(),
                    ['body' => EmailBody::fromPlainText($row['body'])],
                    ['id' => (int) $row['id']],
                    ['%s'],
                    ['%d']
                );
            }
        }
    }
}
