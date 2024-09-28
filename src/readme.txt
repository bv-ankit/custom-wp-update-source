=== Custom WP Update Source ===
Contributors: Blogvault
Tags: updates, mirror, wordpress.org alternative
Requires at least: 4.7
Tested up to: 6.6.2
Stable tag: 1.0
License: MIT
License URI: https://mit-license.org/

Redirects WordPress core, plugin, and theme updates and searches to a mirror when wp.org is not reachable.

== Description ==

Custom WP Update Source is a plugin that provides an alternative source for WordPress updates when the official WordPress.org servers are not accessible. It redirects update checks and downloads to a custom mirror server, ensuring that your WordPress site can still receive important updates even when WordPress.org is down or unreachable. This is especially useful for sites that need security updates but cannot reach the official WordPress.org servers.

== Frequently Asked Questions ==

= Does this replace the official WordPress update system? =

No, this plugin acts as a fallback when the official WordPress.org servers are not reachable. It will only provide updates from the custom mirror if WordPress.org is inaccessible.

== Changelog ==

= 1.0 =
* Initial release

== API Specifications for Mirror Server ==

The mirror server should implement the following endpoints:

1. GET /core-update-check/
   - Returns JSON object with 'updates' array containing core update information

2. POST /plugin-info-bulk/
   - Accepts JSON array of plugin slugs
   - Returns JSON object with plugin update information for each slug

3. POST /theme-info-bulk/
   - Accepts JSON array of theme slugs
   - Returns JSON object with theme update information for each slug

The mirror server should also host plugin and theme ZIP files at:
- /core/{version}.zip
- /plugins/{plugin-slug}/{version}.zip
- /themes/{theme-slug}/{version}.zip

Ensure all endpoints return appropriate HTTP status codes and handle errors gracefully.
