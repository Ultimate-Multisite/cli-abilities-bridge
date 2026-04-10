# AGENTS.md — CLI Abilities Bridge

## Project Overview

WordPress plugin that exposes WP-CLI as a single ability via the WordPress Abilities API. AI agents pass commands as plain text strings — the same way they would use bash — instead of parsing hundreds of individual tool definitions. Works on both single-site and multisite WordPress installations.

## Build Commands

```bash
# No build step — plain PHP plugin with no compiled assets or Composer dependencies
```

## Project Structure

```
cli-abilities-bridge/
├── cli-abilities-bridge.php       # Plugin entry point (bootstraps on plugins_loaded)
├── inc/
│   ├── class-wp-cli-abilities.php     # Registers single wp-cli/execute ability
│   ├── class-command-executor.php     # Tokenizes + executes commands via array-based proc_open
│   ├── class-command-cache.php        # Blocklist for dangerous commands
│   ├── class-command-permissions.php  # Per-command permission classification (read/write/destructive)
│   ├── class-system-commands.php      # System CLI commands (whois, dig, curl, etc.)
│   └── class-system-executor.php      # System command execution with timeout + SSRF protection
├── composer.json
└── readme.txt
```

## Architecture

The plugin registers two categories of abilities:

1. **`wp-cli/execute`** — Single ability. Agents pass raw WP-CLI commands (e.g. `post list --format=json`). The executor tokenizes the string, validates against the blocklist, checks permissions based on the command's classification, then runs via array-based `proc_open` (no shell).

2. **`system/*`** — Multiple structured abilities for system commands (whois, dig, curl, etc.). Each has its own input schema. These are separate because they're a curated, fixed set — not a discovery problem.

## Code Style & Conventions

- **PHP version**: >= 7.4
- **WordPress Coding Standards**: tabs for indentation, snake_case functions, Yoda conditions
- **File naming**: `class-{name}.php` pattern in `inc/`
- **No autoloader**: Files are manually `require_once`'d in the main plugin file
- **Text domain**: `cli-abilities-bridge`
- **Single-site and multisite**: Works on both; multisite context (--url) is added automatically when relevant

## Key Patterns

- Singleton pattern via `get_instance()` on main classes
- Hooks into `plugins_loaded` — bails early if `wp_register_ability()` doesn't exist
- Classes use WordPress-style naming (underscored, not namespaced)
- Constants defined with `CLI_ABILITIES_BRIDGE_DIR` prefix
- Array-based `proc_open` for WP-CLI execution (bypasses shell — no injection risk)
- Command string tokenizer handles single/double quotes and backslash escaping

## Local Development Environment

The shared WordPress dev install for testing this plugin is at `../wordpress` (relative to this repo root).

- **URL**: http://wordpress.local:8080
- **Admin**: http://wordpress.local:8080/wp-admin — `admin` / `admin`
- **WordPress version**: 7.0-RC2
- **This plugin**: symlinked into `../wordpress/wp-content/plugins/$(basename $PWD)`
- **Reset to clean state**: `cd ../wordpress && ./reset.sh`

WP-CLI is configured via `wp-cli.yml` in this repo root — run `wp` commands directly from here without specifying `--path`.

```bash
wp plugin activate $(basename $PWD)   # activate this plugin
wp plugin deactivate $(basename $PWD) # deactivate
wp db reset --yes && cd ../wordpress && ./reset.sh  # full reset
```
