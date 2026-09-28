# Deployment

Keep the application root and `config.php` outside the web document root. Serve only the contents of `public/`.

1. Copy `config.example.php` to `config.php` in the private application root and set the database credentials, Telegram token, bot username, webhook URL, and independent random secrets. Never commit `config.php`.
2. If the public directory is deployed separately from the application root, set `PERIODBOT_APP_ROOT` in the PHP environment to the absolute path of that private root. Otherwise, the code uses the parent of `public/`.
3. Apply `schema.sql` to a dedicated bot database. Apply later migrations only when upgrading an existing installation, after taking a backup.
4. Configure the Telegram webhook and a private scheduled run of `cron-cli.php`. Remove one-time setup and migration endpoints after successful use.

The public repository contains source code, not production credentials, user data, operational audit reports, or private QA scripts. The live deployment may use host-specific configuration that is intentionally absent here.
