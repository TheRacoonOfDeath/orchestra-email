# Email Service

orchestra-email sends plain-text emails to recipient groups that the other Orchestra plugins
define. It contains no business rules.

The domain plugins register **recipient groups**, following the same pattern as capabilities.
A group has an ID, a label, a category it is listed under (e.g. a project), a function that
returns the recipients' user IDs and the capability required to email it, checked with optional
arguments (e.g. the project ID), so that rules per group can be added later.

orchestra-members registers the global groups (everyone, all members, all conductors, all
organizers), orchestra-projects the project and assignment-based groups. Currently conductors and
organizers may email all groups.

The plugin provides:

- the screen for writing an email: it lists only the groups the user may email, can be opened
  with groups preselected, e.g. from a project page, and shows the recipients before sending
- resolving groups to recipients, without duplicates
- sending one plain-text email per recipient, so recipients never see each other's addresses
- sending in the background in batches with WP-Cron, to avoid timeouts and hosting limits
- a log with metadata only (sender, groups, subject, number of recipients, time), deleted after
  12 months; the text is deleted as soon as the email has been sent

Delivery uses `wp_mail()`, so an SMTP plugin can be added at any time without changes.

## Using it

The Email page (`[orchestra_email]`) and **Email → Write Email** in wp-admin are shown to everyone
who may email at least one group.

1. Select one or more groups. Groups are listed by category, e.g. "Orchestra" or a project.
2. Enter subject and message (plain text) and click **Review**.
3. Check the recipients and click **Send**.

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

## Sending

- Recipients are resolved when the user clicks **Send** and stored in the queue table
  `orchestra_core_email_queue` (user IDs only).
- The first batch is sent right away, the rest by the WP-Cron event `orchestra_core_email_send`
  every minute. A batch has 50 emails (filter `orchestra_email_batch_size`).
- Every recipient gets their own email via `wp_mail()`, with **Reply-To** set to the sender and a
  footer naming sender and groups. The **From** address is left to WordPress
  (`wordpress@your-domain`) or an SMTP plugin.
- A failed delivery is retried twice in the next batches, then counted as failed in the log.
- When all emails of a message are sent, its text is deleted. The log entry is deleted after
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
