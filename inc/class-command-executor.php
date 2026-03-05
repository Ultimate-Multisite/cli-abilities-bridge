<?php

defined('ABSPATH') || exit;

/**
 * Executes WP-CLI commands via proc_open with proper escaping.
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
	 * Execute a WP-CLI command.
	 *
	 * @param string $command_path Space-separated command path (e.g. "post list").
	 * @param array  $input        Input arguments from the ability invocation.
	 * @param array  $synopsis     The command's synopsis for type awareness.
	 * @return array|string|\WP_Error Parsed JSON array, raw string output, or WP_Error.
	 */
	public static function execute(string $command_path, array $input, array $synopsis = []) {

		$wp_binary = self::find_wp_cli();

		if (is_wp_error($wp_binary)) {
			return $wp_binary;
		}

		$cmd_parts = [escapeshellarg($wp_binary)];

		// Add the command path segments.
		foreach (explode(' ', $command_path) as $segment) {
			$cmd_parts[] = escapeshellarg($segment);
		}

		// Extract --url before iterating input args so it doesn't get added twice.
		$explicit_url = '';

		if (!empty($input['url'])) {
			$explicit_url = $input['url'];
			self::$current_site_url = $explicit_url;
			unset($input['url']);
		}

		// Build a lookup of synopsis param types by name.
		$param_types = [];

		foreach ($synopsis as $param) {

			$name = $param['name'] ?? '';
			$type = $param['type'] ?? '';

			if (!empty($name)) {
				$param_types[$name] = $type;
			}
		}

		// Add input arguments.
		foreach ($input as $key => $value) {

			$synopsis_type = $param_types[$key] ?? 'assoc';

			if ($synopsis_type === 'positional') {
				// Positional args go without a key prefix.
				$cmd_parts[] = escapeshellarg((string) $value);
			} elseif ($synopsis_type === 'flag') {
				// Coerce string "true"/"false"/"1"/"0" to boolean for flags.
				if (is_string($value)) {
					$value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
				}

				// Flags are only added when truthy.
				if ($value) {
					$cmd_parts[] = escapeshellarg("--{$key}");
				}
			} else {
				// Assoc and generic args use --key=value.
				$cmd_parts[] = escapeshellarg("--{$key}") . '=' . escapeshellarg((string) $value);
			}
		}

		// Add --format=json if the command supports it.
		if (self::supports_format($synopsis) && !isset($input['format'])) {
			$cmd_parts[] = '--format=json';
		}

		// Add multisite context.
		$cmd_parts[] = '--path=' . escapeshellarg(ABSPATH);

		if (is_multisite()) {
			$target_url = $explicit_url ?: self::$current_site_url ?: network_site_url();
			$cmd_parts[] = '--url=' . escapeshellarg($target_url);
		}

		// Pass the current user so WP-CLI commands that check permissions
		// (e.g. WooCommerce REST-based CLI) run as the authenticated user.
		$current_user_id = get_current_user_id();

		if ($current_user_id > 0) {
			$cmd_parts[] = '--user=' . escapeshellarg((string) $current_user_id);
		}

		// Ensure non-interactive.
		$cmd_parts[] = '--no-color';

		$command = implode(' ', $cmd_parts);

		$result = self::run($command, $command_path);

		// Auto-set current site context after site creation.
		if (str_starts_with($command_path, 'site create') && !is_wp_error($result)) {
			$url = self::extract_url_from_output($result);

			if ($url) {
				self::$current_site_url = $url;
			}
		}

		return $result;
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

		if (!empty($path) && is_executable($path)) {
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

		if (!empty($which) && is_executable($which)) {
			return $which;
		}

		return new \WP_Error(
			'wp_cli_not_found',
			'WP-CLI binary not found. Install WP-CLI or set the path via the wp_cli_abilities_wp_binary filter.'
		);
	}

	/**
	 * Check if the command supports --format=json for OUTPUT formatting.
	 *
	 * Some commands have a `format` param that controls value serialization
	 * (e.g. `option update --format=plaintext`), not output formatting.
	 * These typically offer only `plaintext` and `json`, while output
	 * formatters always include `table`.
	 *
	 * Only auto-add --format=json when the param's options include both
	 * `json` (so the command supports JSON output) and `table` (proving
	 * it's an output formatter, not a value serializer).
	 *
	 * @param array $synopsis Parsed synopsis.
	 * @return bool
	 */
	private static function supports_format(array $synopsis): bool {

		foreach ($synopsis as $param) {
			if (($param['name'] ?? '') === 'format' && ($param['type'] ?? '') === 'assoc') {
				$options = $param['options'] ?? [];

				// Must have both 'json' and 'table' — table is the hallmark
				// of output formatting (list/get commands). Value serializers
				// (like option update) only offer plaintext + json.
				return is_array($options)
					&& in_array('json', $options, true)
					&& in_array('table', $options, true);
			}
		}

		return false;
	}

	/**
	 * Run a shell command via proc_open.
	 *
	 * @param string $command      The full shell command.
	 * @param string $command_path The WP-CLI command path for error context.
	 * @return array|string|\WP_Error
	 */
	private static function run(string $command, string $command_path = '') {

		$descriptors = [
			0 => ['pipe', 'r'],  // stdin
			1 => ['pipe', 'w'],  // stdout
			2 => ['pipe', 'w'],  // stderr
		];

		// phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- proc_open is essential: this plugin's core purpose is executing WP-CLI commands via process pipes.
		$process = proc_open($command, $descriptors, $pipes, ABSPATH);

		if (!is_resource($process)) {
			return new \WP_Error('proc_open_failed', 'Failed to execute WP-CLI command.');
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing proc_open() process pipes, not filesystem file handles. WP_Filesystem is not applicable.

		// Close stdin immediately.
		fclose($pipes[0]);

		$stdout = stream_get_contents($pipes[1]);
		fclose($pipes[1]);

		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[2]);

		// phpcs:enable

		$exit_code = proc_close($process);

		if ($exit_code !== 0) {
			$raw_msg = !empty($stderr) ? trim($stderr) : "WP-CLI exited with code {$exit_code}";
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

		// Try to parse as JSON.
		$decoded = json_decode($stdout, true);

		if (json_last_error() === JSON_ERROR_NONE) {
			return $decoded;
		}

		return trim($stdout);
	}

	/**
	 * Generate actionable error hints from WP-CLI stderr output.
	 *
	 * Pattern-matches common failure messages and appends a hint so the
	 * AI model can self-correct without guessing.
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
			$hint = 'Hint: This WP-CLI command is not available. Check that required plugins are active.';
		} elseif (str_contains($stderr, 'parameter: --porcelain') || str_contains($stderr, 'porcelain expects')) {
			$hint = 'Hint: The --porcelain flag takes no value. Pass it as boolean true, not a string.';
		} elseif (preg_match('/^(usage|Synopsis):/im', $stderr)) {
			$hint = 'Hint: Wrong arguments were passed. Check the required positional parameters for this command.';
		}

		if (!empty($hint)) {
			return $stderr . "\n" . $hint;
		}

		return $stderr;
	}

	/**
	 * Extract a URL from WP-CLI site create output.
	 *
	 * WP-CLI's `site create` outputs the new site URL on success.
	 * This extracts it so we can auto-set the current site context.
	 *
	 * @param array|string $output The command output.
	 * @return string The extracted URL, or empty string.
	 */
	private static function extract_url_from_output($output): string {

		$text = is_array($output) ? wp_json_encode($output, JSON_UNESCAPED_SLASHES) : (string) $output;

		// Match common WP-CLI site create output patterns:
		// "Success: Site 3 created: https://example.com/subsite"
		// Or just a URL on its own line.
		if (preg_match('#(https?://[^\s"\'}\]>]+)#i', $text, $matches)) {
			return rtrim($matches[1], '.,;');
		}

		return '';
	}
}
