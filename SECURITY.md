# Security policy

## Supported versions

Only the latest release of orchestra-email receives security fixes. Sites update automatically from the
GitHub releases (**Dashboard → Updates**).

## Reporting a vulnerability

Please do not open a public issue. Report it privately through
[GitHub's private vulnerability reporting](https://github.com/TheRacoonOfDeath/orchestra-email/security/advisories/new)
(**Security → Report a vulnerability**). You will get an answer within a week; a fix is released
as soon as possible and credited in the release notes, unless you prefer otherwise.

## Verifying a release

Release zips are built by GitHub Actions from the tag and carry a signed build provenance:

```sh
gh attestation verify orchestra-email-X.Y.Z.zip --repo TheRacoonOfDeath/orchestra-email \
    --signer-workflow TheRacoonOfDeath/orchestra-workflows/.github/workflows/release.yml
```

The data protection of the Orchestra plugins is described in
[orchestra-core's GDPR documentation](https://github.com/TheRacoonOfDeath/orchestra-core/blob/main/docs/gdpr.md).
