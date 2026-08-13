# Security Policy

## Supported versions

Security fixes are provided for the latest tagged minor release.

## Reporting a vulnerability

Do not open a public issue for a security vulnerability. Report it privately to
`security@pars.design` with affected versions, reproduction steps, and impact.
Do not include production credentials or personal data.

The connector intentionally keeps feed tokens and webhook secrets in host
application environment configuration. It never generates, prints, or commits
customer credentials.
