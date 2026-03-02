=== CLI Abilities Bridge ===
Contributors: jeandavidgrattepanche
Tags: cli, abilities, api, automation, multisite
Requires at least: 6.8
Tested up to: 6.9
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically discovers WP-CLI commands and exposes them as abilities via the WordPress Abilities API.

== Description ==

CLI Abilities Bridge connects the power of WP-CLI with the WordPress Abilities API. It discovers installed WP-CLI commands, converts them into structured abilities with JSON Schema validation, and makes them available through a permission-controlled interface.

The plugin also registers a curated set of system CLI commands (network diagnostics, text processing, system info) as abilities with strict security controls.

= Features =

* **Automatic Command Discovery** — Recursively walks the WP-CLI command tree and registers leaf commands as abilities.
* **System Command Catalog** — 30+ pre-defined system commands (whois, dig, curl, df, jq, and more) with structured input schemas.
* **Role-Based Permissions** — Three access levels (read, write, destructive) mapped to WordPress capabilities.
* **JSON Schema Validation** — Every ability has a JSON Schema built from the WP-CLI command synopsis.
* **Security Layering** — Command blocklists, binary allowlists, SSRF protection for curl, shell argument escaping, and process timeouts.
* **MCP Annotations** — Abilities include metadata annotations (readonly, destructive, idempotent) for AI model awareness.
* **Multisite Aware** — Passes network context and authenticated user to all executed commands.

= WP-CLI Commands =

* `wp abilities sync` — Discover and cache all available WP-CLI commands.
* `wp abilities clear` — Clear the command cache.
* `wp abilities list` — List all cached commands with access levels.

= Security =

The plugin enforces multiple layers of protection:

* **Blocklisted commands** — Dangerous top-level commands (db, shell, config, core, eval, etc.) and sub-commands (site empty, plugin install, super-admin add, etc.) are blocked by default.
* **Binary allowlist** — System commands are restricted to a strict allowlist of safe binaries.
* **SSRF protection** — Curl commands block internal/private IP ranges and unsafe URL schemes.
* **Process timeouts** — All command execution enforces configurable timeouts (default 30s, max 120s).
* **Output limits** — Command output is truncated at 1MB to prevent memory exhaustion.

= Requirements =

* WordPress Multisite with the Abilities API available (`wp_register_ability()` function).
* WP-CLI installed and executable on the server for WP-CLI command abilities.

== Installation ==

1. Upload the `cli-abilities-bridge` folder to the `/wp-content/plugins/` directory.
2. Network Activate the plugin through the 'Plugins' menu in WordPress.
3. Run `wp abilities sync` to discover and cache WP-CLI commands.

== Frequently Asked Questions ==

= Does this plugin work on single-site WordPress? =

The plugin is designed for WordPress Multisite networks and is marked as network-only.

= What happens if WP-CLI is not installed? =

The WP-CLI command abilities will not be available, but the system command abilities will still work if the required binaries are present on the server.

= Can I customize which commands are blocked? =

Yes. Use the `wp_cli_abilities_blocklist` filter to modify the top-level blocklist and `wp_cli_abilities_subcommand_blocklist` for sub-commands.

= Can I change the required capabilities for command access levels? =

Yes. Use the `wp_cli_abilities_capability_map` filter to customize the WordPress capabilities required for each access level (read, write, destructive).

== Screenshots ==

== Changelog ==

= 1.0.0 =
* Initial release.
* WP-CLI command discovery and ability registration.
* System command catalog with 30+ commands.
* Role-based permission system.
* JSON Schema input validation.
* SSRF protection and security hardening.
