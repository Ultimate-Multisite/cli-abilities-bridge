# AGENTS.md — CLI Abilities Bridge

## Project Overview

WordPress plugin that discovers WP-CLI commands and exposes them as abilities via the WordPress Abilities API. Network-activated plugin for Ultimate Multisite environments.

## Build Commands

```bash
# No build step — plain PHP plugin with no compiled assets or Composer dependencies
```

## Project Structure

```
cli-abilities-bridge/
├── cli-abilities-bridge.php       # Plugin entry point (bootstraps on plugins_loaded)
├── inc/
│   ├── class-wp-cli-abilities.php     # Main class — discovers WP-CLI commands
│   ├── class-system-commands.php      # System-level command registration
│   ├── class-command-cache.php        # Caches discovered commands
│   ├── class-command-executor.php     # Executes WP-CLI commands
│   ├── class-command-permissions.php  # Permission checks for command execution
│   ├── class-schema-builder.php       # Builds ability schemas from commands
│   └── class-system-executor.php      # System command execution
├── composer.json
└── readme.txt
```

## Code Style & Conventions

- **PHP version**: >= 7.4
- **WordPress Coding Standards**: tabs for indentation, snake_case functions, Yoda conditions
- **File naming**: `class-{name}.php` pattern in `inc/`
- **No autoloader**: Files are manually `require_once`'d in the main plugin file
- **Text domain**: `cli-abilities-bridge`
- **Network plugin**: `Network: true` — activates network-wide

## Key Patterns

- Singleton pattern via `get_instance()` on main classes
- Hooks into `plugins_loaded` — bails early if `wp_register_ability()` doesn't exist
- Classes use WordPress-style naming (underscored, not namespaced)
- Constants defined with `CLI_ABILITIES_BRIDGE_DIR` prefix
