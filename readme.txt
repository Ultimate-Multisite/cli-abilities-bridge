=== CLI Abilities Bridge ===
Contributors: jeandavidgrattepanche
Tags: cli, abilities, api, automation, multisite
Requires at least: 6.8
Tested up to: 6.9
Stable tag: 2.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Exposes WP-CLI and system commands as abilities via the WordPress Abilities API.

== Description ==

CLI Abilities Bridge gives AI agents direct access to WP-CLI through a single ability. Instead of registering hundreds of individual command abilities (which consumes excessive tokens for AI models to parse), the plugin exposes one `wp-cli/execute` ability that accepts commands exactly as you would type them in a terminal.

The plugin also registers a curated set of system CLI commands (network diagnostics, text processing, system info) as structured abilities with strict security controls.

= How It Works =

AI agents call `wp-cli/execute` with a command string — the same way they would use bash:

    post list --post_type=page --format=json
    option get blogname
    plugin list --status=active --format=json
    user list --role=administrator --format=json
    post create --post_title="Hello World" --post_status=publish

No discovery step, no syncing, no cache. The command is validated against a blocklist, permissions are checked, and it runs.

= Features =

* **Single Ability Interface** — One `wp-cli/execute` ability instead of hundreds. Minimal token overhead for AI agents.
* **Natural Command Syntax** — Agents pass commands as plain text, exactly like bash.
* **System Command Catalog** — 30+ pre-defined system commands (whois, dig, curl, df, jq, and more) with structured input schemas.
* **Role-Based Permissions** — Three access levels (read, write, destructive) mapped to WordPress capabilities. Checked per-command at execution time.
* **Security Layering** — Command blocklists, binary allowlists, SSRF protection for curl, shell-free execution via array-based proc_open, and process timeouts.
* **MCP Annotations** — Abilities include metadata annotations for AI model awareness.
* **Multisite Aware** — Passes network context and authenticated user to all executed commands.

= Security =

The plugin enforces multiple layers of protection:

* **Blocklisted commands** — Dangerous top-level commands (db, shell, config, core, eval, etc.) and sub-commands (site empty, plugin install, super-admin add, etc.) are blocked by default.
* **No shell execution** — Commands are executed via array-based proc_open, bypassing the shell entirely and eliminating injection risk.
* **Permission checks** — Each command is classified (read/write/destructive) and checked against the user's capabilities at runtime.
* **Binary allowlist** — System commands are restricted to a strict allowlist of safe binaries.
* **SSRF protection** — Curl commands block internal/private IP ranges and unsafe URL schemes.
* **Process timeouts** — All command execution enforces configurable timeouts (default 30s, max 120s).
* **Output limits** — Command output is truncated at 1MB to prevent memory exhaustion.

= Requirements =

* WordPress with the Abilities API available (`wp_register_ability()` function).
* WP-CLI installed and executable on the server.

== Installation ==

1. Upload the `cli-abilities-bridge` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress (or Network Activate on multisite).
3. That's it — the `wp-cli/execute` ability is immediately available.

== Frequently Asked Questions ==

= Does this plugin work on single-site WordPress? =

Yes. The plugin works on both single-site and multisite installations. On multisite, it automatically passes the site URL context to WP-CLI commands.

= What happened to `wp abilities sync`? =

Version 2.0 removed the discovery/sync workflow. Commands are validated and executed on-the-fly — no caching step needed.

= What happens if WP-CLI is not installed? =

The WP-CLI ability will return an error. System command abilities work independently if the required binaries are present on the server.

= Can I customize which commands are blocked? =

Yes. Use the `wp_cli_abilities_blocklist` filter to modify the top-level blocklist and `wp_cli_abilities_subcommand_blocklist` for sub-commands.

= Can I change the required capabilities for command access levels? =

Yes. Use the `wp_cli_abilities_capability_map` filter to customize the WordPress capabilities required for each access level (read, write, destructive).

== Screenshots ==

== Changelog ==

= 2.0.0 - 2026-04-10 =
* **Breaking change**: Replaced per-command ability registration with a single `wp-cli/execute` ability.
* Agents now pass WP-CLI commands as plain text strings — natural bash-style interface.
* Removed command discovery, caching, and sync workflow (`wp abilities sync` is gone).
* Removed JSON Schema builder — no longer needed for individual command schemas.
* Switched to array-based proc_open for shell-free command execution.
* Added command string tokenizer with proper quote handling.
* Per-command permission checks now happen at execution time instead of registration time.

= 1.0.0 =
* Initial release.
* WP-CLI command discovery and ability registration.
* System command catalog with 30+ commands.
* Role-based permission system.
* JSON Schema input validation.
* SSRF protection and security hardening.
