=== CyberNote Security Checker ===
Contributors: teeeda1129
Tags: security, hardening, diagnostic, audit, maintenance
Requires at least: 5.9
Tested up to: 7.0
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Diagnoses WordPress security settings and version status, presenting plain-language improvement steps in Japanese. No external requests. Lightweight.

== Description ==

CyberNote Security Checker is a lightweight plugin that audits your WordPress site's security posture without sending any data to external servers.

Many security plugins are powerful but heavy, English-only, and full of technical jargon. CyberNote Security Checker takes the opposite approach: it targets Japanese individual bloggers and small business owners who need to understand exactly what to do — delivered quickly and without specialist knowledge.

**10 diagnostic checks. Zero external requests.**

A widget appears on the WordPress dashboard showing results in three levels: good (no action needed) / attention (improvement recommended) / recommended (priority action required). Each item includes a plain-Japanese explanation of the risk and step-by-step remediation guidance.

= Category A: Version Freshness (3 checks) =

* **WordPress core** — Detects whether security-only maintenance releases are unapplied. Distinguishes urgency between security patches and feature updates.
* **PHP version** — Evaluated against official PHP support status. End-of-life versions flagged as "priority action"; security-only branches as "attention".
* **Plugin and theme updates** — Displays the count and names of pending updates. A direct link opens the standard WordPress update screen; the plugin never performs updates itself.

= Category B: Hardening Settings (7 checks) =

* **Debug display** — WP_DEBUG with screen output on a production site is flagged as "priority action"; log-only mode as "attention".
* **File editing** — If the theme and plugin code editor is enabled in the admin panel, flagged as "priority action".
* **Admin username** — If a user named admin or administrator exists, flagged as "attention" (changing it carries migration risk, so no urgent push).
* **HTTPS** — Sites running on plain HTTP are flagged as "priority action".
* **Database table prefix** — Default wp_ prefix flagged as "attention" (live-site changes carry risk, so no urgent push).
* **XML-RPC** — Enabled XML-RPC is flagged as "attention"; use-case guidance included before recommending disablement.
* **REST API user enumeration** — If anonymous requests to /wp/v2/users return user data, flagged as "attention".

= Design Principles =

* **Read-only** — The plugin only presents diagnostic results. It never automatically changes site settings or files.
* **No external requests** — Every check reads WordPress built-in APIs and site configuration only. Nothing leaves your server.
* **Lightweight** — No real-time file scanning, no custom WAF, no resident processes. Diagnostics run once when the admin page loads.
* **Plain language** — Technical terms are avoided. Each check explains why it matters and what to do in everyday language.

= Pro Plan (coming soon) =

The free plan covers whether updates are available. The Pro plan will add CVE vulnerability alerts in Japanese — matching installed plugins and themes against external vulnerability databases, with daily/weekly automated scans and email notifications.

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

PHP 8.1 reached end-of-life in late 2025, so the plugin flags it as "priority action". However, upgrading PHP can break some plugins or themes. Take a backup, test in a staging environment if possible, then upgrade.

= Is it safe to leave XML-RPC enabled? =

If you use Jetpack or a mobile app that relies on XML-RPC, leaving it enabled is fine. If you have no services depending on it, consider disabling it.

== Screenshots ==

1. Dedicated admin page — all 10 diagnostic results in one view. Priority items and hardening settings displayed in a two-column layout.
2. Dashboard widget — compact summary on the standard WordPress dashboard showing issue count and top-priority items.

== Changelog ==

= 1.0.0 =
* Initial release
* Category A (version freshness): 3 diagnostic checks
* Category B (hardening settings): 7 diagnostic checks
* WordPress dashboard widget with AJAX refresh
* Dedicated admin panel with 7 sub-pages
* Full Japanese language support

== Upgrade Notice ==

= 1.0.0 =
Initial release.
