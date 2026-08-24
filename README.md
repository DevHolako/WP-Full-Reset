# WP Full Reset

WP Full Reset is a standalone WordPress site reset and developer tool. It provides complete Nuclear Reset with selective plugin and theme preservation, Standard Database Reset, Options Reset, 1-Click SQL Database Snapshots, and granular cleanup tools.

---

## Features

### 1. Nuclear Site Reset (Full Clean Wipe)
- **Complete Factory Reset:** Drops all database tables and reinstalls clean WordPress defaults using native `wp_install()`.
- **Uploads and Cache Purge:** Cleans out `wp-content/uploads/` and cache files.
- **Selective Plugin and Theme Preservation:** Lists all installed plugins and themes pre-selected for deletion. Uncheck any plugins or themes you want to keep.
- **Admin Account Preservation:** Automatically preserves administrator username, password hash, and site URL to prevent lockout.
- **Confirmation Safety Gate:** Requires typing "reset" to prevent accidental execution.

### 2. Standard Database Reset
- Reinitializes database tables to clean WordPress defaults while preserving all media uploads, plugins, and themes on disk.

### 3. Options Reset
- Resets the `wp_options` table back to WordPress defaults without affecting posts, pages, custom post types, media, or users.

### 4. Database Snapshots (Backups)
- Fast SQL database snapshots stored in `wp-content/uploads/wp-full-reset-snapshots/`.
- 1-click restore, download `.sql` files, or delete old snapshots.
- Streaming multi-query SQL parser designed to prevent MySQL `max_allowed_packet` errors.

### 5. Granular Cleanup Tools
- **Purge Uploads Folder:** Safely empty `wp-content/uploads/`.
- **Purge Transients and Object Cache:** Clear expired/all transients and flush object cache.
- **Drop Custom Tables:** Scan and delete orphaned tables left behind by deleted plugins.
- **Delete Inactive Plugins and Themes:** One-click bulk removal of deactivated plugins and unused themes.
- **Reset .htaccess:** Delete and regenerate default WordPress rewrite rules.

---

## Installation

1. Clone or download this repository into your WordPress plugins directory:
   ```bash
   cd wp-content/plugins/
   git clone https://github.com/DevHolako/WP-Full-Reset.git wp-full-reset
   ```
2. Activate the plugin in **WordPress Admin -> Plugins**.
3. Go to **Tools -> WP Full Reset** to access all features.

---

## Security and Compatibility
- Protected by WordPress nonces and `manage_options` capability checks on all AJAX endpoints.
- Secure snapshot storage with `.htaccess` (`Deny from all`) and empty `index.php`.
- Compatible with WordPress 5.6+ and PHP 7.4 to PHP 8.3+.

---

## Author

Developed by [DevHolako](https://github.com/DevHolako)  
Repository: https://github.com/DevHolako/WP-Full-Reset

## License

This project is licensed under the GNU General Public License v2 or later.
