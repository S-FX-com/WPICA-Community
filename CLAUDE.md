# BLT Community — Claude Code Guide

## Project overview

WordPress plugin that manages home-centric membership for civic associations.
Post type `cmm_home` is the central record; users are linked to homes via ACF fields
(`primary_contact`, `linked_users`) and WP user meta (`cmm_home_id`, `cmm_address_code`).

## Key files

| File | Purpose |
|---|---|
| `blt-community.php` | Plugin entry point, version constants |
| `includes/class-cpt.php` | `cmm_home` custom post type |
| `includes/class-acf-fields.php` | ACF field group definitions |
| `includes/class-webhooks.php` | SureForms REST webhook handlers |
| `includes/class-applications.php` | Admin application review UI + REST address typeahead |
| `includes/class-roles.php` | Custom roles, user meta sync |
| `includes/class-onboarding.php` | Dashboard, settings, webhook docs |
| `includes/class-dues.php` | Annual dues-reset cron |
| `includes/class-reports.php` | Admin reports + CSV export |
| `includes/class-frontend.php` | Member dashboard, invite system |
| `includes/class-importer.php` | Bulk address import |
| `includes/class-block.php` | SureForms address-lookup block |

## Membership status workflow

```
inactive / expired
  → pending_review          (application webhook received)
  → approved_pending_payment (admin approves)
  → active                  (payment webhook received)
  → rejected                (admin rejects)
```

## Webhook field mapping (SureForms → POST JSON)

**Application webhook** (`POST /wp-json/cmm/v1/webhook/application`):
- `address`    — full address text from dropdown (matched to Home post title)
- `email`      — applicant email
- `first_name` — applicant first name
- `last_name`  — applicant last name

**Payment webhook** (`POST /wp-json/cmm/v1/webhook/payment`):
- `home_id`    — numeric post ID of the home
- `amount`     — payment amount (numeric)
- `date`       — payment date `YYYY-MM-DD` (defaults to today)

## Email template placeholders

Approval email supports: `{first_name}`, `{last_name}`, `{address}`,
`{dues_amount}`, `{payment_url}`, `{community_name}`, `{admin_email}`.

## Development branch

Active feature branch: `claude/membership-dues-separation-HbJFX`

## Version management — REQUIRED before every push

**Before running `git push`, always bump the patch version** in `blt-community.php`.

The version appears in exactly two places — update both atomically:

```
Line 5  : * Version:     X.Y.Z
Line 14 : define( 'CMM_VERSION', 'X.Y.Z' );
```

Bump rules:
- **Patch** (Z+1) — bug fixes, copy changes, minor tweaks
- **Minor** (Y+1, Z=0) — new features or behaviour changes
- **Major** (X+1, Y=0, Z=0) — breaking changes or large refactors

Include the version bump in the same commit as the changes it describes, or as a
dedicated "Bump version to X.Y.Z" commit immediately before pushing.
