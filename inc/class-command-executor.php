<?php

defined('ABSPATH') || exit;

/**
 * Executes WP-CLI commands from raw command strings.
 *
 * Accepts the command exactly as an agent would type it in a terminal
 * (minus the `wp` prefix), tokenizes it safely, validates against the
 * blocklist and permissions, then executes via array-based proc_open
 * which bypasses the shell entirely — eliminating injection risk.
 */
class WP_CLI_Abilities_Command_Executor {

	/**
	 * Current site URL for multisite context persistence.
	 *
	 * Once set (explicitly via --url or auto-detected after site create),
	 * all subsequent commands in the same request use this URL unless
	 * overridden per-call.
	 *
	 * @var string
	 */
	private static string $current_site_url = '';

	/**
	 * Set the current site URL for multisite context.
	 *
	 * @param string $url The site URL to use as default for subsequent commands.
	 */
	public static function set_current_site(string $url): void {
		self::$current_site_url = $url;
	}

	/**
	 * Get the current site URL for multisite context.
	 *
	 * @return string
	 */
	public static function get_current_site(): string {
		return self::$current_site_url;
	}

	/**
	 * Execute a WP-CLI command from a raw command string.
	 *
	 * @param string $command The command string without the `wp` prefix
	 *                        (e.g. "post list --post_type=page --format=json").
	 * @return array|string|\WP_Error Parsed JSON, raw output, or error.
	 */
	public static function execute(string $command) {

		$command = trim($command);

		// Strip leading 'wp ' if the agent included it.
		if (str_starts_with($command, 'wp ')) {
			$command = substr($command, 3);
		}

		if ($command === '') {
			return new \WP_Error(
				'wp_cli_empty_command',
				'No command provided. Pass a WP-CLI command, e.g. "post list --format=json".'
			);
		}

		// Tokenize the command string (handles quoted arguments).
		$tokens = self::tokenize($command);

		if (empty($tokens)) {
			return new \WP_Error(
				'wp_cli_empty_command',
				'Could not parse the command. Pass a WP-CLI command, e.g. "post list --format=json".'
			);
		}

		// Extract the command path (non-flag tokens at the start).
		$command_path = self::extract_command_path($tokens);

		// Check blocklist.
		if (WP_CLI_Abilities_Command_Cache::is_blocked($command_path)) {
			return new \WP_Error(
				'wp_cli_blocked_command',
				sprintf(
					'The command "%s" is blocked for security reasons. Blocked top-level groups: %s.',
					$command_path,
					implode(', ', WP_CLI_Abilities_Command_Cache::get_blocklist())
				),
				['status' => 403]
			);
		}

		// Fine-grained permission check: destructive commands need manage_network.
		$level      = WP_CLI_Abilities_Command_Permissions::classify($command_path);
		$perm_check = WP_CLI_Abilities_Command_Permissions::check_level($level);

		if (is_wp_error($perm_check)) {
			return $perm_check;
		}

		// Find WP-CLI binary.
		$wp_binary = self::find_wp_cli();

		if (is_wp_error($wp_binary)) {
			return $wp_binary;
		}

		// Track --url if explicitly provided (for multisite context persistence).
		foreach ($tokens as $token) {
			if (preg_match('/^--url=(.+)$/', $token, $m)) {
				self::$current_site_url = $m[1];
			}
		}

		// Build the process argument array.
		// Using array-based proc_open (PHP 7.4+) bypasses the shell entirely.
		$proc_args   = [$wp_binary];
		$proc_args   = array_merge($proc_args, $tokens);
		$has_path    = self::tokens_have_flag($tokens, '--path');
		$has_url     = self::tokens_have_flag($tokens, '--url');
		$has_user    = self::tokens_have_flag($tokens, '--user');
		$has_color   = self::tokens_have_flag($tokens, '--no-color');

		if (! $has_path) {
			$proc_args[] = '--path=' . ABSPATH;
		}

		if (! $has_url && is_multisite()) {
			$target_url  = self::$current_site_url ?: network_site_url();
			$proc_args[] = '--url=' . $target_url;
		}

		// Pass the authenticated user so permission-aware commands work correctly.
		if (! $has_user) {
			$current_user_id = get_current_user_id();

			if ($current_user_id > 0) {
				$proc_args[] = '--user=' . (string) $current_user_id;
			}
		}

		if (! $has_color) {
			$proc_args[] = '--no-color';
		}

		$result = self::run($proc_args, $command_path);

		// Auto-set current site context after site creation.
		if (str_starts_with($command_path, 'site create') && ! is_wp_error($result)) {
			$url = self::extract_url_from_output($result);

			if ($url !== '') {
				self::$current_site_url = $url;
			}
		}

		return $result;
	}

	/**
	 * Tokenize a command string into an array of arguments.
	 *
	 * Handles single-quoted, double-quoted, and backslash-escaped characters.
	 * Since we use array-based proc_open (no shell), the tokens are passed
	 * directly to the process — no shell metacharacter risk.
	 *
	 * @param string $command The raw command string.
	 * @return string[] Array of argument tokens.
	 */
	private static function tokenize(string $command): array {

		$tokens    = [];
		$current   = '';
		$in_single = false;
		$in_double = false;
		$len       = strlen($command);

		for ($i = 0; $i < $len; $i++) {
			$char = $command[ $i ];

			if ($in_single) {
				if ($char === "'") {
					$in_single = false;
				} else {
					$current .= $char;
				}
			} elseif ($in_double) {
				if ($char === '"') {
					$in_double = false;
				} elseif ($char === '\\' && $i + 1 < $len) {
					$next = $command[ $i + 1 ];

					// Only escape quotes and backslashes inside double quotes.
					if ($next === '"' || $next === '\\') {
						$current .= $next;
						$i++;
					} else {
						$current .= $char;
					}
				} else {
					$current .= $char;
				}
			} else {
				if ($char === "'") {
					$in_single = true;
				} elseif ($char === '"') {
					$in_double = true;
				} elseif ($char === '\\' && $i + 1 < $len) {
					$current .= $command[ $i + 1 ];
					$i++;
				} elseif (ctype_space($char)) {
					if ($current !== '') {
						$tokens[] = $current;
						$current  = '';
					}
				} else {
					$current .= $char;
				}
			}
		}

		if ($current !== '') {
			$tokens[] = $current;
		}

		return $tokens;
	}

	/**
	 * Extract the command path from tokenized arguments.
	 *
	 * The command path is the sequence of non-flag tokens at the start
	 * (e.g. ["post", "list"] from "post list --format=json").
	 *
	 * @param string[] $tokens Tokenized arguments.
	 * @return string Space-separated command path (e.g. "post list").
	 */
	private static function extract_command_path(array $tokens): string {

		$path_parts = [];

		foreach ($tokens as $token) {
			if (str_starts_with($token, '-')) {
				break;
			}

			$path_parts[] = $token;
		}

		return implode(' ', $path_parts);
	}

	/**
	 * Check if a flag is present in the tokens.
	 *
	 * Matches both --flag=value and bare --flag forms.
	 *
	 * @param string[] $tokens Tokenized arguments.
	 * @param string   $flag   The flag to check (e.g. "--url", "--path").
	 * @return bool
	 */
	private static function tokens_have_flag(array $tokens, string $flag): bool {

		foreach ($tokens as $token) {
			// Match --flag or --flag=value.
			if ($token === $flag || str_starts_with($token, $flag . '=')) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Find the WP-CLI binary path.
	 *
	 * @return string|\WP_Error Path to wp-cli or WP_Error if not found.
	 */
	private static function find_wp_cli() {

		/**
		 * Filter the WP-CLI binary path.
		 *
		 * @param string $path Path to the WP-CLI binary.
		 */
		$path = apply_filters('wp_cli_abilities_wp_binary', '');

		if (! empty($path) && is_executable($path)) {
			return $path;
		}

		// Check common locations.
		$candidates = [
			'/usr/local/bin/wp',
			'/usr/bin/wp',
			ABSPATH . 'wp-cli.phar',
			getenv('HOME') . '/.local/bin/wp',
		];

		foreach ($candidates as $candidate) {
			if (file_exists($candidate) && is_executable($candidate)) {
				return $candidate;
			}
		}

		// Try which.
		$which = trim((string) shell_exec('which wp 2>/dev/null'));

		if (! empty($which) && is_executable($which)) {
			return $which;
		}

		return new \WP_Error(
			'wp_cli_not_found',
			'WP-CLI binary not found. Install WP-CLI or set the path via the wp_cli_abilities_wp_binary filter.'
		);
	}

	/**
	 * Run a command via array-based proc_open (no shell interpretation).
	 *
	 * @param string[] $args         The command as an array of arguments.
	 * @param string   $command_path The WP-CLI command path for error context.
	 * @return array|string|\WP_Error
	 */
	private static function run(array $args, string $command_path = '') {

		$descriptors = [
			0 => ['pipe', 'r'],  // stdin
			1 => ['pipe', 'w'],  // stdout
			2 => ['pipe', 'w'],  // stderr
		];

		// phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- proc_open is essential: this plugin's core purpose is executing WP-CLI commands via process pipes.
		$process = proc_open($args, $descriptors, $pipes, ABSPATH);

		if (! is_resource($process)) {
			return new \WP_Error('proc_open_failed', 'Failed to execute WP-CLI command.');
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing proc_open() process pipes, not filesystem file handles.

		// Close stdin immediately.
		fclose($pipes[0]);

		$stdout = stream_get_contents($pipes[1]);
		fclose($pipes[1]);

		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[2]);

		// phpcs:enable

		$exit_code = proc_close($process);

		if ($exit_code !== 0) {
			$raw_msg = ! empty($stderr) ? trim($stderr) : "WP-CLI exited with code {$exit_code}";
			$hint    = self::humanize_error($raw_msg, $command_path);

			return new \WP_Error(
				'wp_cli_error',
				$hint,
				[
					'exit_code' => $exit_code,
					'stderr'    => $stderr,
					'stdout'    => $stdout,
				]
			);
		}

		// Try to parse as JSON for structured responses.
		$decoded = json_decode($stdout, true);

		if (json_last_error() === JSON_ERROR_NONE) {
			return $decoded;
		}

		return trim($stdout);
	}

	/**
	 * Generate actionable error hints from WP-CLI stderr output.
	 *
	 * @param string $stderr       The raw stderr text.
	 * @param string $command_path The WP-CLI command path for context.
	 * @return string The original message with an appended hint (if any).
	 */
	private static function humanize_error(string $stderr, string $command_path = ''): string {

		$hint = '';

		if (str_contains($stderr, 'Invalid JSON:')) {
			$hint = 'Hint: The value was interpreted as JSON. Remove --format or use --format=plaintext for this command.';
		} elseif (str_contains($stderr, "isn't a registered") || str_contains($stderr, 'not a registered')) {
			$hint = 'Hint: This WP-CLI command is not available. Check that required plugins are active. Run "plugin list --status=active --format=json" to see active plugins.';
		} elseif (str_contains($stderr, 'parameter: --porcelain') || str_contains($stderr, 'porcelain expects')) {
			$hint = 'Hint: The --porcelain flag takes no value. Use just "--porcelain" without "=".';
		} elseif (preg_match('/^(usage|Synopsis):/im', $stderr)) {
			$hint = 'Hint: Wrong arguments. Run "help ' . $command_path . '" to see the correct usage.';
		}

		if (! empty($hint)) {
			return $stderr . "\n" . $hint;
		}

		return $stderr;
	}

	/**
	 * Extract a URL from WP-CLI site create output.
	 *
	 * @param array|string $output The command output.
	 * @return string The extracted URL, or empty string.
	 */
	private static function extract_url_from_output($output): string {

		$text = is_array($output) ? wp_json_encode($output, JSON_UNESCAPED_SLASHES) : (string) $output;

		if (preg_match('#(https?://[^\s"\'}\]>]+)#i', $text, $matches)) {
			return rtrim($matches[1], '.,;');
		}

		return '';
	}
}
