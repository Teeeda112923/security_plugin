=== CyberNote Security Checker ===
Contributors: teeeda1129
Tags: security, hardening, diagnostic, audit, maintenance
Requires at least: 5.9
Tested up to: 7.1
Stable tag: 1.1.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Diagnoses WordPress security settings and version status, presenting plain-language improvement steps in Japanese. No external requests. Lightweight.

== Description ==

CyberNote Security Checker is a lightweight plugin that audits your WordPress site's security posture without sending any data to external servers.

Many security plugins are powerful but heavy, English-only, and full of technical jargon. CyberNote Security Checker takes the opposite approach: it targets Japanese individual bloggers and small business owners who need to understand exactly what to do — delivered quickly and without specialist knowledge.

**12 diagnostic checks. Zero external requests.**

A widget appears on the WordPress dashboard showing results in three levels: good (no action needed) / attention (improvement recommended) / recommended (priority action required). Each item includes a plain-Japanese explanation of the risk and step-by-step remediation guidance.

= Category A: Version Freshness (3 checks) =

* **WordPress core** — Detects whether maintenance releases (mainly security and bug fixes) are unapplied, and distinguishes them from feature updates. If WordPress has not yet retrieved update information, the result says so instead of reporting "no problem".
* **PHP version** — Evaluated against the official PHP support schedule by date, so a branch is flagged automatically once its support ends. End-of-life versions flagged as "priority action"; security-only branches as "attention" with the end date shown.
* **Plugin and theme updates** — Displays the count and names of pending updates. A direct link opens the standard WordPress update screen; the plugin never performs updates itself. If update information has not been retrieved yet, the result says so.

= Category B: Hardening Settings (7 checks) and Category C: Hygiene (2 checks) =

* **Debug display** — WP_DEBUG with screen output on a production site is flagged as "priority action". Debug mode without screen output, a debug log in the publicly reachable default location, or PHP's display_errors enabled on the server are flagged as "attention".
* **File editing** — If the theme and plugin code editor is enabled in the admin panel, flagged as "priority action". DISALLOW_FILE_EDIT or DISALLOW_FILE_MODS both count as disabled.
* **Admin username** — If an administrator account named admin or administrator exists, flagged as "attention" (changing it carries migration risk, so no urgent push).
* **HTTPS** — Judged from the Site Address and WordPress Address settings (works correctly behind reverse proxies and load balancers). Sites configured for plain HTTP are flagged as "priority action".
* **Database table prefix** — Default wp_ prefix flagged as "attention" (live-site changes carry risk, so no urgent push).
* **XML-RPC** — Enabled XML-RPC is flagged as "attention"; use-case guidance included before recommending disablement.
* **REST API user enumeration** — If anonymous requests to /wp/v2/users return user data, flagged as "attention". REST API restrictions applied through the standard authentication filter are taken into account.
* **Security keys (salts)** (Category C) — Checks the eight wp-config.php authentication keys and salts. Keys that are too short are flagged as "priority action". Keys that are missing, left at the placeholder or duplicated are flagged as "attention": WordPress then substitutes random values stored in the database, which is safer than it sounds but still leaves a risk if the database leaks. Key values are never displayed.
* **Unused plugins and themes** (Category C) — Inactive plugins and unused themes still ship files on the server that can be exploited if vulnerable. Their presence is flagged as "attention" and their names are listed, with removal guidance (keeping one fallback theme is fine).

= Design Principles =

* **Read-only** — The plugin only presents diagnostic results. It never automatically changes site settings or files.
* **No external requests** — Every check reads WordPress built-in APIs and site configuration only. Nothing leaves your server.
* **Lightweight** — No real-time file scanning, no custom WAF, no resident processes. Diagnostics run once when the admin page loads.
* **Plain language** — Technical terms are avoided. Each check explains why it matters and what to do in everyday language.

= Vulnerability alerts (separate external service) =

This plugin is free and fully functional on its own. Matching your installed plugins and themes against external vulnerability databases (CVE) requires server-side processing that cannot be done locally, so it is offered separately as an external service called CyberNote, not bundled in this plugin. See https://www.cybernote.click/wp-security-checker-guide/ for details.

== Installation ==

= Automatic installation =

1. Go to Dashboard > Plugins > Add New
2. Search for "CyberNote Security Checker"
3. Click Install Now, then Activate

= Manual installation =

1. Download the ZIP file from this page
2. Go to Dashboard > Plugins > Add New > Upload Plugin
3. Select the ZIP file and click Install Now, then Activate
4. After activation, the diagnostic widget appears on your WordPress dashboard

== Frequently Asked Questions ==

= Does this plugin send any data to external servers? =

No. All diagnostics run entirely within your WordPress installation. No data is sent anywhere.

= Will running the diagnostics slow down my site? =

No. Diagnostics only run when you open the plugin's admin page or dashboard widget, and there is no continuous background scanning.

= Does clicking "Open update screen" automatically update my plugins? =

No. It navigates to the standard WordPress update screen. The decision to update is yours.

= How do I get the latest results without reloading the page? =

Click the "Re-diagnose" button inside the widget or admin page to refresh results via AJAX without a full page reload.

= PHP 8.1 is detected. Do I need to upgrade immediately? =

PHP 8.1 reached end-of-life on 31 December 2025, so the plugin flags it as "priority action". However, upgrading PHP can break some plugins or themes. Take a backup, test in a staging environment if possible, then upgrade.

= Is it safe to leave XML-RPC enabled? =

If you use Jetpack or a mobile app that relies on XML-RPC, leaving it enabled is fine. If you have no services depending on it, consider disabling it.

== Screenshots ==

1. Dedicated admin page — all 12 diagnostic results in one view: summary, priority items, and the three categories.
2. Dashboard widget — compact summary on the standard WordPress dashboard showing issue count and top-priority items.

== Changelog ==

= 1.1.1 =
* Fixed: WordPress core and plugin/theme update checks no longer report "no problem" when WordPress has not retrieved update information yet
* Fixed: HTTPS check now uses the Site Address / WordPress Address settings, avoiding false warnings behind reverse proxies and load balancers
* Fixed: REST API user enumeration check now respects restrictions applied via the rest_authentication_errors filter
* Fixed: File editing check recognizes DISALLOW_FILE_MODS
* Fixed: Admin username check only flags accounts with administrator privileges
* Fixed: Database prefix check uses the base prefix on multisite
* Fixed: Plugin and theme names containing "&" or quotes were displayed double-escaped
* Fixed: The re-diagnose button no longer gets stuck when the request fails
* Improved: PHP version check follows the official support schedule by date
* Improved: Security keys check reflects how WordPress handles missing or default keys (too short = priority action; missing/placeholder/duplicate = attention)
* Improved: Debug check detects a publicly reachable debug.log and display_errors enabled on the server
* Improved: Unused plugins and themes are listed by name
* Improved: Clearer, consistent guidance text for PHP, debug, database prefix, XML-RPC and security keys

= 1.1.0 =
* (Not released separately on WordPress.org; these changes ship in 1.1.1.)
* Refreshed the admin UI with a cleaner, SaaS-style dashboard (summary gauge, priority card, category cards)
* Reorganized the results display into three categories: A. Version freshness / B. Hardening / C. Hygiene (diagnostic logic unchanged)
* Added a "Hygiene" sub-page and redesigned the vulnerability-alert page as a clearer external-service (CyberNote) information page — still no bundled scanning or license code
* Japanese-only status labels throughout (要対応 / 改善推奨 / 問題なし)

= 1.0.0 =
* Initial release
* Category A (version freshness): 3 diagnostic checks
* Category B (hardening settings): 9 diagnostic checks
* WordPress dashboard widget with AJAX refresh
* Dedicated admin panel with diagnostic sub-pages
* Full Japanese language support

== Upgrade Notice ==

= 1.1.1 =
Fixes several false results (HTTPS behind proxies, REST API restrictions, unknown update status) and improves accuracy. Recommended for all users.

= 1.1.0 =
UI refresh and clearer category organization. No change to what is diagnosed.

= 1.0.0 =
Initial release.
