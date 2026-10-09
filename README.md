# Cohort Instagram

WordPress plugin that shows a cached Instagram feed for a Business or Creator account. Tokens stay encrypted in the database and are never sent to the front end.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- Instagram Business or Creator account
- Meta app with the Instagram API

## Installation

1. Copy the `cohort-instagram` folder to `wp-content/plugins/`.
2. Activate **Cohort Instagram** in **Plugins**.
3. Open **Settings → Instagram** and enter your App ID and App Secret, then connect the account.

## Credentials

App ID and App Secret can be saved in the plugin settings, or defined in `wp-config.php` (recommended on production):

```php
define( 'COHORT_INSTAGRAM_APP_ID', 'your-app-id' );
define( 'COHORT_INSTAGRAM_APP_SECRET', 'your-app-secret' );
define( 'COHORT_INSTAGRAM_ENCRYPTION_KEY', 'a-long-random-string' );
```

Do not commit real values. The plugin stores secrets encrypted in WordPress options. This repository contains no API keys, tokens, or site credentials.

## Theme usage

```php
<?php
if ( function_exists( 'cohort_instagram_get_media' ) ) {
	$posts = cohort_instagram_get_media();
}
```

`cohort_instagram_get_media()` returns cached posts only. It does not call Instagram on the front end. If the account is not connected or the cache is empty, configured Media Library fallbacks are used instead.

## License

GPL-2.0-or-later
