# Email Service

orchestra-email sends emails with formatted text and attachments to recipient groups that the
other Orchestra plugins define. It contains no business rules.

The domain plugins register **recipient groups**, following the same pattern as capabilities.
A group has an ID, a label, a category it is listed under (e.g. a project), a function that
returns the recipients' user IDs and the capability required to email it, checked with optional
arguments (e.g. the project ID), so that rules per group can be added later.

orchestra-members registers the global groups (everyone, all members, all conductors, all
organizers), orchestra-projects the groups of each project (accepted and pending participants). Currently conductors and
organizers may email all groups.

The plugin provides:

- the screen for writing an email: it lists only the groups the user may email, can be opened
  with groups preselected, e.g. from a project page, and shows the recipients before sending
- resolving groups to recipients, without duplicates
- formatting with WordPress's editor (bold, italic, underline, font size, a few colors, lists) and
  attachments
- sending one email per recipient, so recipients never see each other's addresses, and a copy to
  the sender
- sending in the background in batches with WP-Cron, to avoid timeouts and hosting limits
- a reply address, prefilled with the sender's email address and changeable, e.g. to a shared
  mailbox
- a log with metadata only (sender, groups, subject, number of recipients, time), deleted after
  12 months; the text and the reply address are deleted as soon as the email has been sent

Delivery uses `wp_mail()`, so an SMTP plugin can be added at any time without changes.

## Using it

The Email page (`[orchestra_email]`) and **Email → Write Email** in wp-admin are shown to everyone
who may email at least one group.

1. Select one or more groups. Groups are listed by category, e.g. "Orchestra" or a project.
2. Check the address replies go to (your own by default; any valid address, e.g. a shared
   mailbox), then enter subject and message. The toolbar formats the selected text: bold, italic, underline, font
   size, color, bulleted and numbered lists, and removing the formatting.
3. Optionally choose attachments and click **Review**. Attachments chosen so far stay listed as
   checkboxes; uncheck one to leave it out.
4. Check the recipients, the message (shown as the recipients will see it) and the attachments
   and click **Send**. You always get a
   copy yourself, even if you are in none of the groups; it is included in the number of
   recipients.

**Email → Sent Emails** shows the log: date, sender, subject, groups, number of recipients and
whether all emails were sent.

## Registering groups

```php
use Orchestra\Email\EmailGroupRegistry;
use Orchestra\Email\EmailService;
use Orchestra\Email\RecipientGroup;

if (!class_exists(\Orchestra\Email\Plugin::class)) {
    return; // orchestra-email is optional
}

add_action(EmailService::REGISTER_GROUPS_ACTION, function (EmailGroupRegistry $groups): void {
    $groups->add(new RecipientGroup(
        'orchestra_projects_12_accepted',            // orchestra_{plugin}_{name}, unique
        'Accepted participants',                     // label
        'Spring Concert 2027',                       // category
        fn (): array => $participantIds,             // user IDs, computed when the group is used
        'orchestra_projects_email_send',             // capability required to email the group
        [12],                                        // arguments of the capability check
    ));
});
```

- A group with `listed: false` (the seventh argument, since 0.5) is not offered when writing an
  email and does not make the Email area visible; it appears only when a link preselects it, e.g.
  orchestra-members' group of a single member, used by "Write an email" on a member's profile.
- The action is fired the first time groups are needed in a request, e.g. when the Email menu
  is built. Keep the registration cheap; compute recipients only in the closure.
- The capability is checked with `user_can($userId, $capability, ...$capabilityArgs)`. Passing the
  object (e.g. the project) allows a meta capability with rules per object later, e.g. members
  emailing their own section, without changing this plugin.
- `EmailService::REGISTER_GROUPS_ACTION` is `orchestra_email_register_groups`.
- `EmailService::RECIPIENTS_FILTER` (`orchestra_email_recipients`) receives the user IDs and the
  group IDs of every email. Plugins may remove recipients; they cannot add any.

Other plugins link to the compose screen with groups preselected:

```php
if (\Orchestra\Email\Plugin::running()) {
    $url = \Orchestra\Email\Plugin::instance()->email()->composeUrl(['orchestra_projects_12_accepted']);
}
```

`Plugin::running()` is false while orchestra-email waits for orchestra-core 0.7 (see below).

## Formatting

The editor is WordPress's TinyMCE editor with a reduced toolbar. Without JavaScript it is a text
field, and line breaks are kept.

The text is stored and sent as HTML. Before that, `EmailBody::sanitize()` keeps only the tags of
the editor (`p`, `br`, `strong`, `em`, `u`, `s`, `span`, lists, `blockquote` and links) and only
the CSS properties `color`, `font-size` and `text-decoration`. Everything else, e.g. scripts,
images or positioning, is removed. Each email also contains a plain-text version for email
programs that do not show HTML.

## Attachments

- Up to 10 files with 10 MB in total per email. The size is also limited by PHP's upload limits
  (`upload_max_filesize`, `post_max_size`); the email page shows the lower value. Filters:
  `orchestra_email_max_attachment_size` (bytes) and `orchestra_email_allowed_file_types` (format
  of WordPress's `upload_mimes`; default: PDF, JPEG, PNG, GIF, text, Word, Excel, PowerPoint and
  OpenDocument). The type is detected from the content, so extension and content must match.
- Files are uploaded when the email is reviewed and stored in a private directory under random
  names, like the sheet music of orchestra-library: `ORCHESTRA_EMAIL_STORAGE_DIR` if defined in
  `wp-config.php` (recommended, outside the web root), otherwise
  `wp-content/uploads/orchestra-email-<random>/` with `.htaccess` (`Require all denied`). **nginx
  ignores `.htaccess`**: define the constant or deny `^/wp-content/uploads/orchestra-email-` in the
  server configuration.
- Only the uploader can send their attachments. Recipients get them under their original names
  (made unique, e.g. "Plan (2).pdf").
- Attachments are deleted as soon as the email has been sent to all recipients, like the text.
  Attachments of emails that were never sent are deleted after a day, and those of deleted users
  right away. The table `orchestra_email_attachments` holds their metadata until then.
- Every recipient gets the files with their own email: 10 MB to 50 people are 500 MB of mail.
  Keep attachments small and check the limits of the host or mail provider.

## Sending

- Recipients are resolved when the user clicks **Send** and stored in the queue table
  `orchestra_core_email_queue` (user IDs only). The sender is added once, unless they have no
  valid email address; the log's number of recipients includes them.
- The first batch is sent right away, the rest by the WP-Cron event `orchestra_core_email_send`
  every minute. A batch has 50 emails (filter `orchestra_email_batch_size`).
- Every recipient gets their own HTML email via `wp_mail()`, with **Reply-To** set to the address
  chosen when writing (by default the sender's; emails queued before 0.3 use the sender's) and the
  attachments. Only the sender's copy has a footer naming sender and groups; the other recipients
  do not learn which groups were emailed. The **From** address is left to WordPress
  (`wordpress@your-domain`) or an SMTP plugin.
- A failed delivery is retried twice in the next batches, then counted as failed in the log.
- When all emails of a message are sent, its text and reply address are deleted. The log entry is deleted after
  12 months (filter `orchestra_email_log_retention_days`) by the daily event
  `orchestra_core_email_purge`.
- When a user is deleted, their log entries show "Former member" and their queued emails are
  dropped.

WP-Cron runs when the site is visited. On a site with little traffic, the remaining batches may
wait for the next visit. For reliable sending, disable WP-Cron's page-load trigger
(`define('DISABLE_WP_CRON', true);`) and call `wp-cron.php` from a server cron job every minute.

## Deliverability

Without an SMTP plugin, `wp_mail()` hands the email to the web server's mail program (PHP
`mail()`). That works on most hosts, but receiving servers often classify such mail as spam.

- "Sent" in the log means the web server accepted the email. Bounces and spam filtering are not
  visible there.
- Ask the host whether PHP `mail()` is enabled and what the hourly or daily limit is, and lower
  the batch size if needed.
- Add an SPF record for the site's domain that allows the web server to send, if the host
  supports it.
- Test with a few addresses at different providers (e.g. Gmail, GMX, Outlook) before writing to
  everyone.

**Switching to SMTP later** needs no change in the Orchestra plugins: install an SMTP plugin
(e.g. WP Mail SMTP or FluentSMTP), connect it to a mail provider and set the From address to
an address of the site's domain. The provider's SPF, DKIM and DMARC records then make the emails
trustworthy. Check the provider's sending limits against the size of the orchestra.

## Moving from orchestra-core

orchestra-core contained the email service up to version 0.6. orchestra-email took it over
unchanged and keeps everything that is stored, so sites that update lose nothing:

- the tables `orchestra_core_email_log` and `orchestra_core_email_queue` and the option
  `orchestra_core_schema_version`
- the WP-Cron events `orchestra_core_email_send` and `orchestra_core_email_purge`, so emails that
  were still queued are sent
- the shortcode `[orchestra_email]` and the wp-admin screens `orchestra-email` and
  `orchestra-email-log`

The PHP namespace (`Orchestra\Email`), the hooks (`orchestra_email_…`) and the text domain
(`orchestra-email`) are new. While orchestra-core 0.6 or earlier is active, orchestra-email
stays off and shows a notice, so no email is sent twice. orchestra-core 0.7 no longer contains
the email service, keeps the data on uninstall and asks administrators to install this plugin.
