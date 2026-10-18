# orchestra-email
Emails to members, sections and project participants.

Part of the Orchestra plugin suite. Requires the [orchestra-core](https://github.com/TheRacoonOfDeath/orchestra-core) plugin.

## Features

- The Email page `[orchestra_email]` (or **Email** in wp-admin): write an email to the groups you
  may email, with bold, italic, underline, font sizes, colors, lists and attachments, check the
  recipients and send it
- Recipient groups registered by the other plugins: everyone, members, conductors and organizers
  (orchestra-members), accepted and pending participants of a project (orchestra-projects)
- One email per recipient, sent in the background in batches; replies go to the sender or an address they choose
- A log of the sent emails with metadata only, deleted after 12 months

## Installation

1. Install and activate orchestra-core 0.7 or later.
2. Upload `orchestra-email-{VERSION}.zip` under **Plugins → Add New → Upload Plugin** and activate it.
3. Create the Orchestra pages (**Create Orchestra pages** in wp-admin), or put `[orchestra_email]` on a page.

Sites that used the email service of orchestra-core 0.6 or earlier keep their log and queued
emails, see [docs/email.md](docs/email.md#moving-from-orchestra-core).

Updates are installed from the GitHub releases of this repository. The plugin reads
`update.json` from the latest release, so the GitHub API and its rate limit are not involved and
no token is needed.

## Documentation

- [Email service: API, sending and deliverability](docs/email.md)

The domain model, use cases and capabilities of the whole suite are documented in orchestra-core.

## Development

```bash
composer install
composer check   # coding standard, static analysis and unit tests
```

The end-to-end tests of orchestra-members and orchestra-projects install this plugin and test
sending to their groups.

## Releasing

1. Run `composer check`, and the end-to-end tests of orchestra-members and orchestra-projects.
2. Bump `Version:` in `orchestra-email.php`.
3. Run `./build-plugin.sh`; it creates `build/orchestra-email-{VERSION}.zip` with production
   dependencies only, and `build/update.json`.
4. Create a GitHub release `v{VERSION}` and attach the zip **and `update.json`**.
