# ⚾ BenchBuddy

**Smarter lineups. Fairer playing time. Less game-day chaos.**

BenchBuddy is a web-based baseball and softball lineup management platform designed to help coaches build balanced lineups, manage player availability, track pitching workloads, organize games, and make better game-day decisions.

Rather than managing lineups through spreadsheets, paper notes, group chats, and memory, BenchBuddy brings the core tools a coach needs into one application.

---

## Table of Contents

- [About BenchBuddy](#about-benchbuddy)
- [Core Features](#core-features)
- [Lineup Management](#lineup-management)
- [Player Management](#player-management)
- [Game Management](#game-management)
- [Bench Fairness](#bench-fairness)
- [Pitch Tracking](#pitch-tracking)
- [Game Statistics](#game-statistics)
- [Team Management](#team-management)
- [History and Reporting](#history-and-reporting)
- [Printing](#printing)
- [Subscription Plans](#subscription-plans)
- [Technology Stack](#technology-stack)
- [Project Structure](#project-structure)
- [Database](#database)
- [Configuration](#configuration)
- [Security](#security)
- [Stripe Billing](#stripe-billing)
- [Email](#email)
- [Development Environments](#development-environments)
- [Installation](#installation)
- [Deployment](#deployment)
- [Privacy](#privacy)
- [Roadmap](#roadmap)
- [License](#license)

---

# About BenchBuddy

BenchBuddy was created to solve a common problem in youth baseball and softball:

> Building fair, practical lineups while managing substitutions, pitching restrictions, attendance, player development, and game history is harder than it should be.

Coaches often manage this information across spreadsheets, handwritten lineup cards, text messages, scorebooks, and memory.

BenchBuddy centralizes those workflows.

The application is designed around real coaching scenarios and prioritizes:

- Fair playing time
- Fast lineup creation
- Simple game-day workflows
- Player development
- Pitcher safety
- Historical context
- Mobile usability
- Practical coaching decisions

BenchBuddy is designed primarily for youth baseball and softball coaches but can support other roster-based team environments.

---

# Core Features

BenchBuddy includes functionality for:

- Team management
- Player roster management
- Game creation
- Game rosters
- Automated lineup generation
- Manual lineup creation
- Defensive positioning
- Batting orders
- Bench rotation
- Bench fairness tracking
- Pitch count tracking
- Pitcher rest tracking
- Game statistics
- Player statistics
- Lineup templates
- Game history
- Lineup history
- Printable lineup sheets
- Multiple coaches/team members
- Multiple teams
- Subscription management
- Promotional codes
- Stripe billing
- Administrative tools
- Feature requests
- User sessions
- Password resets
- Two-factor authentication support

Feature availability may vary by subscription plan.

---

# Lineup Management

Lineup creation is at the core of BenchBuddy.

The application supports both generated and manually constructed lineups.

## Generated Lineups

BenchBuddy can generate defensive lineups while considering factors such as:

- Available players
- Number of innings
- Defensive positions
- Bench assignments
- Player eligibility
- Pitching restrictions
- Previous usage
- Team lineup settings

The objective is not simply to randomly assign positions.

BenchBuddy is designed to help coaches create lineups that are practical, balanced, and fair over the course of a season.

## Manual Lineups

Coaches can also manually build or modify lineups when more direct control is required.

This is useful for:

- Tournament games
- Playoffs
- Development games
- Position-specific development
- Pitching plans
- Catching rotations
- Competitive situations
- Special game scenarios

---

# Player Management

Each team maintains its own player roster.

Player records can support information such as:

- First name
- Last name
- Jersey number
- Active/archive status
- Position information
- Pitching information
- Game participation
- Historical usage

Player information is used throughout lineup generation, statistics, history, and fairness calculations.

---

# Game Management

Coaches can create and manage games throughout a season.

Game information can include:

- Opponent/game name
- Game date
- Number of innings
- Tournament association
- Game status
- Game roster
- Lineup
- Pitching information
- Statistics

Teams may play the same opponent multiple times during a season.

Each game is stored internally using its own database identifier so repeated opponent names can be supported independently.

---

# Bench Fairness

One of BenchBuddy's primary goals is helping coaches distribute playing time fairly.

Bench usage can be evaluated relative to the number of innings for which a player was eligible.

A conceptual bench-rate calculation is:

```text
Bench Percentage =
Bench Innings / Eligible Innings × 100
```

This provides more useful context than simply comparing raw bench totals.

For example:

```text
Player A
6 bench innings
48 eligible innings
12.5% bench rate
```

BenchBuddy can use this information to help coaches identify players receiving unusually high or low bench time.

---

# Pitch Tracking

BenchBuddy includes tools for tracking pitching workloads.

Depending on team configuration and plan availability, coaches can track:

- Pitch counts
- Innings pitched
- Pitching appearances
- Pitcher availability
- Required rest
- Historical pitching workload

Pitching rules can vary by league, age group, division, and organization.

BenchBuddy is designed so that pitching rules can be configured rather than assuming one universal rule set.

> **Important:** BenchBuddy is a coaching aid. Coaches remain responsible for verifying and following the official pitching and player-safety regulations applicable to their league or governing organization.

---

# Game Statistics

BenchBuddy can record lightweight player statistics on a game-by-game basis.

## Batting

Supported batting statistics may include:

- At bats
- Runs
- Hits
- Doubles
- Triples
- Home runs
- RBI
- Walks
- Strikeouts
- Hit by pitch
- Sacrifice flies
- Stolen bases

Derived statistics can include:

- AVG
- OBP
- SLG
- OPS

## Pitching

Supported pitching statistics may include:

- Innings pitched
- Pitches thrown
- Hits allowed
- Runs allowed
- Earned runs
- Walks
- Strikeouts
- Wins
- Losses
- Saves

Derived statistics can include:

- ERA
- WHIP

Statistics are intended to provide coaches with useful historical context rather than replace official league scorekeeping systems.

---

# Team Management

BenchBuddy supports collaborative team management.

Teams can have multiple users with roles such as:

- Head Coach
- Assistant Coach

Team membership allows multiple coaches to work with the same roster, games, lineups, and history.

Some administrative actions may be restricted according to role.

## Team Archiving

Teams can be archived when a season ends or when the team is no longer active.

Because archiving applies to the team itself, an archived team is archived for all members associated with that team.

Historical information can remain available for future reference depending on account permissions and subscription limits.

---

# History and Reporting

BenchBuddy maintains historical information that can help coaches understand previous decisions.

Depending on subscription level, this may include:

- Game history
- Lineup history
- Bench history
- Player usage
- Pitching history
- Player statistics

Historical information can help coaches answer questions such as:

- Who has been sitting the most?
- Who has been sitting the least?
- Who pitched recently?
- Which players have received opportunities at certain positions?
- What lineup was used in a previous game?
- How has playing time been distributed?

---

# Printing

BenchBuddy provides printable game-day lineup sheets.

Print views are designed to provide coaches with useful information without requiring the application to remain open throughout the game.

Printed information may include:

- Defensive lineup
- Batting order
- Bench assignments
- Player information
- Pitching information

Paid plans may provide watermark-free or branded print options depending on subscription configuration.

---

# Subscription Plans

BenchBuddy supports multiple subscription tiers.

Current plan structures may include:

| Feature | Free | Coach | Coach Plus | Unlimited |
|---|---:|---:|---:|---:|
| Players per team | 10 | 15 | Unlimited | Unlimited |
| Games per team | 10 | 20 | Unlimited | Unlimited |
| Game history | 3 | Past 10 | Full | Full |
| Lineup history | 3 | 12 | Full | Full |
| Generate lineups | Yes | Yes | Yes | Yes |
| Watermark-free printouts | No | Yes | Yes | Yes |
| Branded printouts | No | Yes | Yes | Yes |
| Lineup templates | 1 | 5 | 10 | Unlimited |
| Advanced lineup settings | No | No | Yes | Yes |
| Pitch count tracking | No | Yes | Yes | Yes |
| Full game history | No | No | Yes | Yes |
| Additional team members | 0 | 1 | 3 | Unlimited |
| Multiple teams | No | No | No | Yes |

Pricing and plan limits are subject to change.

The application's billing enforcement system determines which functionality is available to each team.

---

# Technology Stack

BenchBuddy is intentionally built using a relatively lightweight web stack.

## Backend

- PHP 8+
- PDO
- MySQL / MariaDB
- Server-side sessions

## Frontend

- HTML5
- CSS3
- Vanilla JavaScript
- Responsive/mobile-first interfaces

## Infrastructure and Services

- Apache-compatible hosting
- MySQL/MariaDB
- Stripe
- SMTP email delivery

## Development Philosophy

BenchBuddy intentionally avoids unnecessary frontend framework complexity.

The application primarily uses traditional server-rendered PHP, allowing it to remain:

- Fast
- Portable
- Easy to deploy
- Easy to debug
- Compatible with conventional web hosting
- Relatively lightweight

---

# Project Structure

A simplified project structure looks similar to:

```text
benchbuddy/
│
├── includes/
│   ├── auth.php
│   ├── billing.php
│   ├── billing_config.php
│   ├── config.php
│   ├── csrf.php
│   ├── db.php
│   ├── functions.php
│   ├── header.php
│   ├── footer.php
│   └── ...
│
├── assets/
│   ├── css/
│   ├── js/
│   └── images/
│
├── uploads/
│
├── index.php
├── login.php
├── forgot_password.php
├── account.php
├── games.php
├── game_stats.php
├── history.php
├── billing.php
├── billing_start.php
├── create_customer_portal.php
├── stripe_webhook.php
└── ...
```

The exact structure may change as development continues.

---

# Database

BenchBuddy uses MySQL/MariaDB.

The database contains application information such as:

- Users
- Teams
- Team memberships
- Players
- Games
- Game rosters
- Lineups
- Bench entries
- Pitch logs
- Batting statistics
- Pitching statistics
- Templates
- Billing information
- User sessions
- Password reset records
- Invitations
- Application settings

## Database Dumps

**Production and staging database exports must never be committed to this repository.**

Database dumps may contain:

- User email addresses
- Password hashes
- Session identifiers
- Password reset tokens
- Two-factor authentication secrets
- Invitation tokens
- Player information
- IP addresses
- Stripe customer identifiers
- Stripe subscription identifiers
- Historical team data

The repository's `.gitignore` should exclude:

```gitignore
*.sql
*.sql.gz
*.dump
*.backup
*.bak
```

---

# Configuration

Sensitive configuration is intentionally stored separately from the application source.

BenchBuddy can load private configuration from a file outside the publicly deployed application directory.

Conceptually:

```php
$privateConfigPath = dirname(__DIR__, 2)
    . '/private/benchbuddy-private-config.php';
```

Application files can then reference configuration values without storing the credentials directly in the repository.

For example:

```php
define('DB_HOST', $appConfig['DB_HOST']);
define('DB_NAME', $appConfig['DB_NAME']);
define('DB_USER', $appConfig['DB_USER']);
define('DB_PASS', $appConfig['DB_PASS']);
```

## Never Commit

The following should never be committed:

```text
Database passwords
SMTP passwords
Stripe secret keys
Stripe webhook secrets
Private API keys
Session secrets
Production environment files
Private configuration files
Database exports
Runtime logs containing sensitive information
```

A sanitized example configuration may be provided for development purposes, but it must contain placeholder credentials only.

---

# Security

BenchBuddy includes multiple application security controls.

## Passwords

User passwords should always be stored using PHP's password hashing functionality:

```php
password_hash()
password_verify()
```

Plaintext passwords must never be stored.

## CSRF Protection

State-changing forms use CSRF protection.

Example:

```php
<?= csrf_field() ?>
```

POST handlers verify the submitted token:

```php
verify_csrf_token();
```

## Authentication

Authentication functionality includes:

- Login sessions
- Session expiration
- Logout handling
- Current-user validation
- User activity tracking
- Team membership validation

## Authorization

Team-specific actions should validate both:

1. The authenticated user
2. The user's relationship to the requested team

Client-provided team IDs must never be trusted without server-side authorization checks.

## SQL

Database queries use PDO prepared statements.

Example:

```php
$stmt = db()->prepare("
    SELECT *
    FROM users
    WHERE id = :id
    LIMIT 1
");

$stmt->execute([
    'id' => $userId,
]);
```

Values should not be directly concatenated into SQL queries.

## Output Escaping

Dynamic values rendered into HTML should be escaped before output.

BenchBuddy provides helper functions for safe output where appropriate.

## Password Reset

Password reset functionality uses temporary reset tokens and expiration rules.

Reset tokens should:

- Be cryptographically random
- Expire after a limited period
- Become invalid after successful use
- Never expose the user's password

## Sessions

BenchBuddy tracks authenticated sessions and user activity.

Session information must never be committed to source control.

## Two-Factor Authentication

The application includes support for two-factor authentication functionality.

Any 2FA secrets stored by the application must be treated as sensitive information.

---

# Stripe Billing

BenchBuddy integrates with Stripe for subscription billing.

Stripe can be used for:

- Monthly subscriptions
- Annual subscriptions
- Subscription upgrades
- Customer management
- Customer billing portal access
- Promotional codes
- Webhook processing

## Stripe Credentials

Stripe credentials must never be committed.

This includes:

```text
sk_live_...
sk_test_...
whsec_...
```

Production and staging/test credentials should be stored in private configuration.

## Webhooks

Stripe webhook endpoints should verify webhook signatures before processing events.

Webhook logs should not be committed because they may contain:

- Stripe customer IDs
- Subscription IDs
- Team IDs
- Billing states
- Operational information

---

# Email

BenchBuddy uses SMTP-based transactional email.

Email functionality may include:

- Password resets
- Team invitations
- Account notifications
- Administrative notifications

SMTP credentials must be stored in private configuration.

Never commit:

```text
SMTP usernames containing private credentials
SMTP passwords
Mail provider API keys
```

---

# Development Environments

BenchBuddy can maintain separate staging and production environments.

This allows new functionality to be tested without modifying the live application.

Typical environments include:

```text
Production
Staging
Local development
```

Each environment should maintain independent configuration for:

- Database
- Application URL
- Stripe
- SMTP
- Sessions
- Debugging

Production credentials should never be reused unnecessarily in development environments.

---

# Installation

> This repository is primarily maintained as the source code for BenchBuddy. Installation requirements may change as development continues.

A typical installation requires:

- PHP 8+
- MySQL or MariaDB
- PDO MySQL extension
- PHP session support
- HTTPS
- SMTP access for transactional email
- Stripe account for paid billing functionality

## 1. Clone the Repository

```bash
git clone <repository-url>
cd benchbuddy
```

## 2. Configure the Database

Create a MySQL/MariaDB database and database user.

Do not use production database credentials for local development.

## 3. Create Private Configuration

Create the private configuration outside the public application directory.

Example structure:

```php
<?php

return [
    'development' => [
        'APP_NAME' => 'BenchBuddy',
        'APP_URL' => 'https://localhost',

        'DB_HOST' => 'localhost',
        'DB_NAME' => 'benchbuddy',
        'DB_USER' => 'benchbuddy_user',
        'DB_PASS' => 'replace-with-local-password',

        'STRIPE_SECRET_KEY' => '',
        'STRIPE_WEBHOOK_SECRET' => '',

        'MAIL' => [
            'host' => '',
            'port' => 587,
            'username' => '',
            'password' => '',
            'encryption' => 'tls',
            'from_email' => '',
            'from_name' => 'BenchBuddy',
        ],
    ],
];
```

Never commit the real version of this file.

## 4. Configure HTTPS

BenchBuddy should be served over HTTPS, particularly when authentication, password resets, and billing are enabled.

## 5. Configure Stripe

For billing development, use Stripe test mode.

Do not use production Stripe credentials in a local development environment.

## 6. Configure SMTP

Provide SMTP credentials through private configuration.

Test:

- Password reset messages
- Team invitations
- Transactional notifications

before deploying to production.

---

# Deployment

Before deploying a new version:

1. Test the change in staging.
2. Back up the production database.
3. Verify database migrations.
4. Verify environment configuration.
5. Confirm no secrets are present in the repository.
6. Deploy application files.
7. Run required database migrations.
8. Test authentication.
9. Test team access.
10. Test lineup creation.
11. Test CSRF-protected forms.
12. Test email functionality.
13. Test Stripe functionality when billing code changes.
14. Review application/error logs.

---

# `.gitignore`

The repository should include rules similar to:

```gitignore
# Private configuration
private/
benchbuddy-private-config.php
.env
.env.*
!.env.example

# Databases and backups
*.sql
*.sql.gz
*.dump
*.backup
*.bak

# Runtime logs
*.log
error_log
stripe_webhook_log.txt

# User uploads
/uploads/*
!/uploads/.gitkeep

# macOS
.DS_Store
**/.DS_Store
__MACOSX/

# IDE
.idea/
.vscode/

# Temporary files
*.tmp
*.temp
*.swp
*~
```

Dependency exclusions should be configured according to the project's deployment strategy.

---

# Privacy

BenchBuddy may process information associated with:

- User accounts
- Coaches
- Teams
- Players
- Games
- Player statistics
- Pitching history

Because youth sports teams may include minors, player information should be handled carefully.

Production application data must never be included in:

- Public Git repositories
- Example database dumps
- Screenshots containing sensitive information
- Public bug reports
- Development fixtures based on real users

Any demonstration data should use fictional players, teams, users, and email addresses.

---

# Development Guidelines

When contributing changes to BenchBuddy:

### PHP

Use strict typing where appropriate:

```php
declare(strict_types=1);
```

Handle PHP functions that can return `false` explicitly.

For example:

```php
$timestamp = strtotime($date);

if ($timestamp !== false) {
    echo date('M j, Y', $timestamp);
}
```

Do not assume a `string|false` or `int|false` value satisfies a strict function signature.

### Database

Use prepared statements.

Avoid:

```php
$sql = "SELECT * FROM users WHERE email = '$email'";
```

Prefer:

```php
$stmt = db()->prepare("
    SELECT *
    FROM users
    WHERE email = :email
");

$stmt->execute([
    'email' => $email,
]);
```

### Forms

State-changing POST forms should include:

```php
<?= csrf_field() ?>
```

and POST handlers should call:

```php
verify_csrf_token();
```

### Authorization

Never assume that possession of an ID grants access.

Always validate:

```text
User → Team Membership → Requested Resource
```

### Secrets

Never hard-code credentials.

Use private configuration.

---

# Production Safety Checklist

Before committing or deploying, verify:

- [ ] No database dumps are included
- [ ] No production user data is included
- [ ] No `.env` file is included
- [ ] No private configuration file is included
- [ ] No database password is included
- [ ] No SMTP password is included
- [ ] No Stripe secret key is included
- [ ] No Stripe webhook secret is included
- [ ] No API secrets are included
- [ ] No session IDs are included
- [ ] No password reset tokens are included
- [ ] No 2FA secrets are included
- [ ] No team invitation tokens are included
- [ ] No production logs are included
- [ ] No real user uploads are included
- [ ] No debugging endpoints expose configuration
- [ ] `.gitignore` is configured
- [ ] Staging has been tested
- [ ] Production database has been backed up

---

# Roadmap

BenchBuddy is under active development.

Potential areas of continued development include:

- Improved lineup intelligence
- Enhanced playing-time fairness
- More configurable league rules
- Improved pitching recommendations
- Expanded player analytics
- Better historical reporting
- Tournament workflows
- Multi-team organization tools
- Improved coach collaboration
- Additional administrative controls
- Enhanced mobile game-day workflows
- Expanded subscription management
- Additional lineup optimization options

The roadmap evolves based on real coaching use cases and application feedback.

---

# Philosophy

BenchBuddy is built around a simple idea:

**Technology should reduce the administrative burden of coaching without trying to replace the coach.**

The application can provide information, history, calculations, and recommendations, but coaches still make the final decisions.

Every team is different.

Every player is different.

Every game is different.

BenchBuddy exists to give coaches better information and better tools when making those decisions.

---

# Disclaimer

BenchBuddy is a coaching and team-management tool.

It does not replace:

- Official league rules
- Governing-body regulations
- Official scorekeeping
- Medical advice
- Player-safety policies
- Organizational requirements

Coaches and organizations are responsible for ensuring compliance with all applicable rules and safety requirements.

---

# License

**Copyright © 2026 BenchBuddy. All rights reserved.**

This repository does not grant permission to copy, modify, distribute, sublicense, or commercially use BenchBuddy source code unless explicit written permission has been provided by the copyright holder.

If this repository is private, access is provided only to authorized collaborators.

---

# BenchBuddy

**Smarter lineups. Fairer playing time. Better game days.**

Built for coaches who would rather spend their time coaching than managing spreadsheets.
