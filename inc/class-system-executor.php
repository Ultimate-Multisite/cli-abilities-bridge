<?php

defined('ABSPATH') || exit;

/**
 * Executes system CLI commands via proc_open with timeout and security controls.
 */
class WP_CLI_Abilities_System_Executor {

	/**
	 * Default command timeout in seconds.
	 */
	const DEFAULT_TIMEOUT = 30;

	/**
	 * Maximum allowed timeout in seconds.
	 */
	const MAX_TIMEOUT = 120;

	/**
	 * Allowed binaries (defense-in-depth allowlist).
	 *
	 * @var string[]
	 */
	const ALLOWED_BINARIES = [
		'whois',
		'dig',
		'nslookup',
		'host',
		'ping',
		'tracepath',
		'curl',
		'date',
		'cal',
		'uptime',
		'free',
		'df',
		'du',
		'uname',
		'hostname',
		'jq',
		'base64',
		'md5sum',
		'sha256sum',
		'openssl',
		'mail',
		'journalctl',
		'systemctl',
		'ss',
		'php',
		'bash',
		'tar',
		'unzip',
		'expr',
	];

	/**
	 * Blocked URL schemes for curl SSRF protection.
	 *
	 * @var string[]
	 */
	const BLOCKED_SCHEMES = [
		'file',
		'gopher',
		'dict',
		'ftp',
		'ldap',
		'tftp',
	];

	/**
	 * RFC1918 and loopback CIDR ranges blocked for curl.
	 *
	 * @var string[]
	 */
	const BLOCKED_IP_RANGES = [
		'10.0.0.0/8',
		'172.16.0.0/12',
		'192.168.0.0/16',
		'127.0.0.0/8',
		'169.254.0.0/16',
		'0.0.0.0/8',
		'::1/128',
		'fc00::/7',
		'fe80::/10',
	];

	/**
	 * Execute a system command.
	 *
	 * @param string      $binary  The binary name (e.g. 'whois', 'curl').
	 * @param array       $args    Array of command-line arguments (already safe strings).
	 * @param string|null $stdin   Optional input to pipe via stdin.
	 * @param int         $timeout Timeout in seconds.
	 * @return string|\WP_Error Command output or error.
	 */
	public static function execute(string $binary, array $args = [], ?string $stdin = null, int $timeout = self::DEFAULT_TIMEOUT) {

		// Validate binary against allowlist.
		if (! in_array($binary, self::ALLOWED_BINARIES, true)) {
			return new \WP_Error(
				'system_binary_not_allowed',
				sprintf('Binary "%s" is not in the allowed list.', $binary),
				['status' => 403]
			);
		}

		// Enforce timeout limits.
		$timeout = max(1, min($timeout, self::MAX_TIMEOUT));

		// Build the command string.
		$cmd_parts = [escapeshellarg($binary)];

		foreach ($args as $arg) {
			$cmd_parts[] = escapeshellarg($arg);
		}

		$command = implode(' ', $cmd_parts);

		return self::run($command, $stdin, $timeout);
	}

	/**
	 * Validate a URL for curl SSRF protection.
	 *
	 * @param string $url The URL to validate.
	 * @return true|\WP_Error True if safe, WP_Error if blocked.
	 */
	public static function validate_curl_url(string $url) {

		$parsed = wp_parse_url($url);

		if ($parsed === false || empty($parsed['host'])) {
			return new \WP_Error(
				'system_curl_invalid_url',
				'Invalid URL provided.',
				['status' => 400]
			);
		}

		// Check scheme.
		$scheme = strtolower($parsed['scheme'] ?? 'https');

		if (in_array($scheme, self::BLOCKED_SCHEMES, true)) {
			return new \WP_Error(
				'system_curl_blocked_scheme',
				sprintf('URL scheme "%s" is not allowed.', $scheme),
				['status' => 403]
			);
		}

		// Resolve hostname to check for internal IPs.
		$host = $parsed['host'];

		// Block localhost variations.
		$localhost_names = ['localhost', 'localhost.localdomain', '0.0.0.0', '127.0.0.1', '::1', '[::1]'];

		if (in_array(strtolower($host), $localhost_names, true)) {
			return new \WP_Error(
				'system_curl_blocked_host',
				'Requests to localhost are not allowed.',
				['status' => 403]
			);
		}

		// Resolve and check IP ranges.
		$ips = gethostbynamel($host);

		if ($ips !== false) {
			foreach ($ips as $ip) {
				if (self::ip_in_blocked_range($ip)) {
					return new \WP_Error(
						'system_curl_blocked_ip',
						sprintf('Host "%s" resolves to a blocked internal IP address.', $host),
						['status' => 403]
					);
				}
			}
		}

		return true;
	}

	/**
	 * Check if an IP falls within blocked ranges.
	 *
	 * @param string $ip The IP address to check.
	 * @return bool True if blocked.
	 */
	private static function ip_in_blocked_range(string $ip): bool {

		$ip_long = ip2long($ip);

		if ($ip_long === false) {
			// IPv6 — check string prefixes for common blocked ranges.
			$ip_lower = strtolower($ip);
			return str_starts_with($ip_lower, '::1')
				|| str_starts_with($ip_lower, 'fc')
				|| str_starts_with($ip_lower, 'fd')
				|| str_starts_with($ip_lower, 'fe80');
		}

		$ranges = [
			['10.0.0.0', '10.255.255.255'],
			['172.16.0.0', '172.31.255.255'],
			['192.168.0.0', '192.168.255.255'],
			['127.0.0.0', '127.255.255.255'],
			['169.254.0.0', '169.254.255.255'],
			['0.0.0.0', '0.255.255.255'],
		];

		foreach ($ranges as [$start, $end]) {
			if ($ip_long >= ip2long($start) && $ip_long <= ip2long($end)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Run a shell command via proc_open with timeout.
	 *
	 * @param string      $command The full shell command.
	 * @param string|null $stdin   Optional stdin input.
	 * @param int         $timeout Timeout in seconds.
	 * @return string|\WP_Error
	 */
	private static function run(string $command, ?string $stdin, int $timeout) {

		$descriptors = [
			0 => ['pipe', 'r'], // stdin
			1 => ['pipe', 'w'], // stdout
			2 => ['pipe', 'w'], // stderr
		];

		$env = [
			'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
			'HOME' => '/tmp',
			'LANG' => 'C.UTF-8',
			'TERM' => 'dumb',
		];

		// phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- proc_open is essential: this plugin's core purpose is executing CLI commands via process pipes.
		$process = proc_open($command, $descriptors, $pipes, '/tmp', $env);

		if (! is_resource($process)) {
			return new \WP_Error('proc_open_failed', 'Failed to execute system command.');
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Operating on proc_open() process pipes, not filesystem files. WP_Filesystem is not applicable.

		// Write stdin if provided.
		if ($stdin !== null) {
			fwrite($pipes[0], $stdin);
		}

		fclose($pipes[0]);

		// Set streams to non-blocking for timeout support.
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$stdout    = '';
		$stderr    = '';
		$start     = microtime(true);
		$timed_out = false;

		while (true) {
			$status = proc_get_status($process);

			if (! $status['running']) {
				// Process finished — drain remaining output.
				$stdout .= stream_get_contents($pipes[1]);
				$stderr .= stream_get_contents($pipes[2]);
				break;
			}

			// Check timeout.
			if ((microtime(true) - $start) > $timeout) {
				$timed_out = true;
				proc_terminate($process, 9);
				break;
			}

			// Read available data.
			$read   = [$pipes[1], $pipes[2]];
			$write  = null;
			$except = null;

			if (@stream_select($read, $write, $except, 0, 200000) > 0) {
				foreach ($read as $pipe) {
					$data = fread($pipe, 8192); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reading from proc_open() process pipe, not a filesystem file.
					if ($pipe === $pipes[1]) {
						$stdout .= $data;
					} else {
						$stderr .= $data;
					}
				}
			}
		}

		fclose($pipes[1]);
		fclose($pipes[2]);

		// phpcs:enable

		$exit_code = $status['exitcode'] ?? proc_close($process);

		// If status already had a valid exit code, still close.
		if (isset($status) && ! $status['running']) {
			proc_close($process);
		}

		if ($timed_out) {
			return new \WP_Error(
				'system_command_timeout',
				sprintf('Command timed out after %d seconds.', $timeout),
				[
					'timeout' => $timeout,
					'stdout'  => $stdout,
				]
			);
		}

		if ($exit_code !== 0) {
			return new \WP_Error(
				'system_command_error',
				! empty($stderr) ? trim($stderr) : "Command exited with code {$exit_code}",
				[
					'exit_code' => $exit_code,
					'stderr'    => $stderr,
					'stdout'    => $stdout,
				]
			);
		}

		// Truncate excessive output (1MB limit).
		if (strlen($stdout) > 1048576) {
			$stdout = substr($stdout, 0, 1048576) . "\n... [output truncated at 1MB]";
		}

		return trim($stdout);
	}
}
