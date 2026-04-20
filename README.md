# W3RD SOCIAL

A Laravel application for managing multiple social media accounts and automated content deletion.

## Features

### 🔗 Multi-Account Management
- **OAuth Integration**: Connect Twitter accounts via OAuth 1.0a with encrypted token storage
- **Account Dashboard**: Manage multiple social media accounts through Filament admin panel
- **Token Management**: Automatic token validation, refresh detection, and expiration tracking
- **Account Actions**: Reconnect, verify credentials, or disconnect accounts with one click
- **Metadata Sync**: Automatically stores profile images, follower counts, and account details

### 🗑️ Tweet Purge System
- **CSV Import**: Bulk import tweets for deletion with validation and duplicate prevention
- **Automated Processing**: Laravel scheduler processes one tweet per minute (safe rate limiting)
- **Tweet Protection**: Mark tweets as "saved" to prevent deletion
- **Status Tracking**: Monitor tweets through Pending → Requested → Purged lifecycle
- **Smart Deletion**: Treats 404 responses as successful deletion (already gone)
- **Statistics Dashboard**: Real-time counts of pending, requested, purged, and saved tweets
- **Account Assignment**: Process tweets from specific accounts or use default account

### 🎨 Admin Interface (Filament v4)
- **Connected Accounts**: View all social accounts with status badges, filters, and bulk actions
- **Tweet Purges**: Table view with searchable tweets, status filters, and protect/unprotect actions
- **CSV Upload**: Import thousands of tweets with comprehensive error reporting
- **Real-time Stats**: View purge progress and statistics at a glance

### 🔒 Security & Best Practices
- **Email Domain Validation**: Restrict registration to approved email domains
- **Encrypted Tokens**: All OAuth credentials encrypted in database
- **User Isolation**: Users only access their own accounts
- **Two-Factor Authentication**: Optional 2FA via Laravel Fortify
- **Rate Limiting**: Authentication throttling and safe API usage

### 🧪 Testing
- **Pest v4.1**: Modern testing with describe/it syntax and grouped test cases
- **Comprehensive Coverage**: 57 tests covering models, services, and features
- **PHPStan Analysis**: Level 5 static analysis with zero errors
- **CI/CD Ready**: GitHub Actions workflow for automated testing and linting

## Deployment Model & Tenancy

This app is currently hardened for **single-tenant / internal allowlisted use**. It is not safe for external multi-user deployment until purges receive direct `user_id` ownership and all queries are tenant-scoped.

Current isolation guarantees:

- `PurgePolicy` only permits viewing a purge when it is bound to an account owned by the current user.
- `PurgeService::processPurge()` never uses a global default account — an account is resolved from the purge itself or from an explicitly supplied user, otherwise the purge is skipped.
- `PurgeService::getDefaultAccount(User $user)` is user-scoped; the previous `id = 1` hardcode is gone.
- The CLI scheduler (`purge:process`) derives the acting user from `$purge->account->user` and skips unassigned purges.
- CSV import auto-binds every imported purge to the importing user's active Twitter account.
- Filament Purge queries (`PurgeResource::getEloquentQuery`, `PurgeBatchWidget`, stats action) are scoped to the current user's accounts.

Before opening this app to external users, purges must gain a direct `user_id` column, a migration backfill, and every remaining bulk/CLI path must be refactored to respect that ownership.

## Tech Stack

- **Laravel 12**: Latest framework features with modern PHP 8.2+
- **Filament 4.1**: Beautiful admin panel with form builders and table actions
- **Livewire & Volt**: Reactive components for authentication flows
- **Atymic Twitter**: OAuth 1.0a integration for Twitter API
- **PHPStan + Larastan**: Static analysis for code quality
- **Laravel Pint**: Opinionated code formatting

## Installation

```bash
# Clone repository
git clone https://github.com/drewroberts/tweets.git
cd tweets

# Install dependencies
composer install
npm install

# Setup environment
cp .env.example .env
php artisan key:generate

# Configure database
php artisan migrate

# Build assets
npm run build

# Start development server
composer dev  # Runs server, queue, pail, and vite concurrently
```

## Configuration

### Twitter API Setup
1. Create app at https://developer.twitter.com/
2. Enable "Request email address from users"
3. Add callback URL: `http://your-domain.com/social/twitter/callback`
4. Add to `.env`:
```env
TWITTER_CONSUMER_KEY=your_key
TWITTER_CONSUMER_SECRET=your_secret
TWITTER_API_VERSION=1.1
```

### Email Domain Restrictions
Configure allowed registration domains in `app/Enums/AllowedEmailDomain.php`

### Laravel Scheduler
Add to crontab for automated tweet deletion:
```bash
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

## Usage

### Connecting Social Accounts
1. Login to Filament admin at `/admin`
2. Navigate to "Accounts" → "Connect Twitter"
3. Authorize app on Twitter
4. Account appears in dashboard with active status

### Purging Tweets
1. Navigate to "Tweet Purges" in admin panel
2. Click "Import CSV" (top-right)
3. Upload CSV with columns: `post_id`, `posted_at`, `text`
4. System automatically processes one tweet per minute
5. Monitor progress via status badges and stats button

### CSV Import Format
```csv
post_id,posted_at,text
1234567890,2023-01-15 14:30:00,"Tweet content here"
0987654321,2023-02-20 09:15:00,"Another tweet"
```

## Commands

```bash
# Process next pending tweet
php artisan purge:process

# Run tests
php artisan test
composer test

# Static analysis
composer analyse

# Code formatting
composer format

# Development server with hot reload
composer dev
```

## Architecture

### Models
- **User**: Authenticated users with social accounts
- **Account**: Connected social media accounts with encrypted tokens
- **Purge**: Tweets scheduled for deletion with status tracking

### Services
- **TwitterAccountService**: OAuth flow, posting, credential verification
- **PurgeService**: Tweet deletion processing, statistics, queue management

### Enums
- **SocialService**: Twitter, Facebook, Telegram with OAuth version detection
- **AllowedEmailDomain**: Whitelisted email domains for registration

### Testing
- **Unit Tests**: Models (15 tests), Services (23 tests), Enums (7 tests)
- **Feature Tests**: Commands (7 tests), CSV import (6 tests), Lifecycle (3 tests), Bulk operations (2 tests), Account integration (2 tests)

## Security Features
- Encrypted OAuth token storage
- CSRF protection on all forms
- Rate-limited authentication
- Session-based OAuth flows
- Unique constraints prevent duplicate accounts
- User-scoped data access
- No plain-text credentials logged or exposed

## License

MIT License - see LICENSE file for details.
