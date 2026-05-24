<?php

/* Enchilada Framework 3.0 
 * OpenTelemetry Protocol (OTLP) Log Exporter
 * 
 * Lightweight OTLP/HTTP JSON log exporter. Sends structured log records to any
 * OTLP-compatible collector (Alloy, OpenTelemetry Collector, Loki, etc.) without
 * requiring ext-opentelemetry or Composer packages.
 * 
 * Depends on: EnchiladaHTTP
 * 
 * @author Daniel Morante
 * @copyright 2026 The Daniel Morante Company, Inc.
 * @license BSD-2-Clause
 */

class EnchiladaOTLP {

	/** OTLP severity number constants (subset) */
	const SEVERITY_TRACE  = 1;
	const SEVERITY_DEBUG  = 5;
	const SEVERITY_INFO   = 9;
	const SEVERITY_WARN   = 13;
	const SEVERITY_ERROR  = 17;
	const SEVERITY_FATAL  = 21;

	/** Map severity numbers to text */
	const SEVERITY_TEXT = [
		self::SEVERITY_TRACE => 'TRACE',
		self::SEVERITY_DEBUG => 'DEBUG',
		self::SEVERITY_INFO  => 'INFO',
		self::SEVERITY_WARN  => 'WARN',
		self::SEVERITY_ERROR => 'ERROR',
		self::SEVERITY_FATAL => 'FATAL',
	];

	/** @var string OTLP endpoint base URL (e.g., http://telemetry.morante.com) */
	private string $endpoint;

	/** @var array Resource attributes (service.name, deployment.environment, etc.) */
	private array $resourceAttributes = [];

	/** @var string Instrumentation scope name */
	private string $scopeName;

	/** @var string Instrumentation scope version */
	private string $scopeVersion;

	/** @var array Buffered log records */
	private array $buffer = [];

	/** @var int Maximum buffer size before auto-flush */
	private int $batchSize;

	/** @var EnchiladaHTTP|null HTTP client (lazy-initialized) */
	private ?EnchiladaHTTP $http = null;

	/** @var int HTTP timeout in seconds for flush requests */
	private int $timeout;

	/** @var bool Suppress exceptions on flush failure (fire-and-forget mode) */
	private bool $suppressErrors;

	/**
	 * Create a new OTLP log exporter.
	 *
	 * @param string $endpoint     OTLP HTTP endpoint base URL (e.g., http://telemetry.morante.com)
	 * @param array  $resource     Resource attributes: ['service.name' => 'my-app', ...]
	 * @param string $scopeName    Instrumentation scope name (e.g., 'pherb.pipeline')
	 * @param string $scopeVersion Instrumentation scope version (e.g., '1.0.0')
	 * @param int    $batchSize    Max buffered records before auto-flush (0 = manual flush only)
	 * @param int    $timeout      HTTP request timeout in seconds
	 * @param bool   $suppressErrors  If true, flush failures are silently ignored
	 */
	public function __construct(
		string $endpoint,
		array $resource = [],
		string $scopeName = '',
		string $scopeVersion = '',
		int $batchSize = 10,
		int $timeout = 5,
		bool $suppressErrors = true
	) {
		$this->endpoint = rtrim($endpoint, '/');
		$this->resourceAttributes = $resource;
		$this->scopeName = $scopeName;
		$this->scopeVersion = $scopeVersion;
		$this->batchSize = $batchSize;
		$this->timeout = $timeout;
		$this->suppressErrors = $suppressErrors;
	}

	/**
	 * Emit a log record.
	 *
	 * @param string $body           Log message body
	 * @param int    $severity       Severity number (use class constants)
	 * @param array  $attributes     Structured attributes: ['job.id' => 'abc', 'duration_ms' => 1234]
	 * @param string|null $traceId   Optional W3C trace ID (32 hex chars)
	 * @param string|null $spanId    Optional span ID (16 hex chars)
	 * @param int|null $timestampNs  Unix nanoseconds (null = now)
	 */
	public function emit(
		string $body,
		int $severity = self::SEVERITY_INFO,
		array $attributes = [],
		?string $traceId = null,
		?string $spanId = null,
		?int $timestampNs = null
	): void {
		$record = [
			'timeUnixNano' => (string)($timestampNs ?? $this->nowNano()),
			'severityNumber' => $severity,
			'severityText' => self::SEVERITY_TEXT[$severity] ?? 'INFO',
			'body' => ['stringValue' => $body],
		];

		if (!empty($attributes)) {
			$record['attributes'] = $this->encodeAttributes($attributes);
		}

		if ($traceId !== null) {
			$record['traceId'] = $traceId;
		}
		if ($spanId !== null) {
			$record['spanId'] = $spanId;
		}

		$this->buffer[] = $record;

		if ($this->batchSize > 0 && count($this->buffer) >= $this->batchSize) {
			$this->flush();
		}
	}

	/**
	 * Convenience: emit INFO level log.
	 */
	public function info(string $body, array $attributes = []): void
	{
		$this->emit($body, self::SEVERITY_INFO, $attributes);
	}

	/**
	 * Convenience: emit WARN level log.
	 */
	public function warn(string $body, array $attributes = []): void
	{
		$this->emit($body, self::SEVERITY_WARN, $attributes);
	}

	/**
	 * Convenience: emit ERROR level log.
	 */
	public function error(string $body, array $attributes = []): void
	{
		$this->emit($body, self::SEVERITY_ERROR, $attributes);
	}

	/**
	 * Convenience: emit DEBUG level log.
	 */
	public function debug(string $body, array $attributes = []): void
	{
		$this->emit($body, self::SEVERITY_DEBUG, $attributes);
	}

	/**
	 * Flush all buffered log records to the OTLP endpoint.
	 *
	 * @return bool True if successfully sent (or buffer was empty), false on failure.
	 */
	public function flush(): bool
	{
		if (empty($this->buffer)) {
			return true;
		}

		$payload = $this->buildPayload($this->buffer);
		$this->buffer = [];

		try {
			$http = $this->getHttpClient();
			$response = $http->call(
				'v1/logs',
				$payload,
				'POST',
				['Content-Type: application/json'],
				$this->timeout,
				'raw'
			);

			$code = $http->getHttpCode();
			return ($code >= 200 && $code < 300);
		} catch (\Throwable $e) {
			if (!$this->suppressErrors) {
				throw $e;
			}
			return false;
		}
	}

	/**
	 * Get the number of buffered records.
	 */
	public function bufferCount(): int
	{
		return count($this->buffer);
	}

	/**
	 * Flush on destruction to avoid losing buffered records.
	 */
	public function __destruct()
	{
		if (!empty($this->buffer)) {
			$this->flush();
		}
	}

	/**
	 * Build the full OTLP ExportLogsServiceRequest JSON payload.
	 *
	 * @param array $records Array of log record arrays
	 * @return string JSON-encoded payload
	 */
	private function buildPayload(array $records): string
	{
		$scopeLog = [
			'logRecords' => $records,
		];

		if ($this->scopeName !== '') {
			$scope = ['name' => $this->scopeName];
			if ($this->scopeVersion !== '') {
				$scope['version'] = $this->scopeVersion;
			}
			$scopeLog['scope'] = $scope;
		}

		$resourceLog = [
			'resource' => [
				'attributes' => $this->encodeAttributes($this->resourceAttributes),
			],
			'scopeLogs' => [$scopeLog],
		];

		$payload = [
			'resourceLogs' => [$resourceLog],
		];

		return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	/**
	 * Encode an associative array of attributes into OTLP KeyValue format.
	 *
	 * Supports: string, int, float, bool, array (of strings).
	 *
	 * @param array $attributes Associative array ['key' => value, ...]
	 * @return array OTLP KeyValue array
	 */
	private function encodeAttributes(array $attributes): array
	{
		$result = [];
		foreach ($attributes as $key => $value) {
			$result[] = [
				'key' => (string)$key,
				'value' => $this->encodeValue($value),
			];
		}
		return $result;
	}

	/**
	 * Encode a single value into OTLP AnyValue format.
	 */
	private function encodeValue(mixed $value): array
	{
		if (is_string($value)) {
			return ['stringValue' => $value];
		}
		if (is_int($value)) {
			return ['intValue' => (string)$value];
		}
		if (is_float($value)) {
			return ['doubleValue' => $value];
		}
		if (is_bool($value)) {
			return ['boolValue' => $value];
		}
		if (is_array($value)) {
			// Array of values
			$values = [];
			foreach ($value as $item) {
				$values[] = $this->encodeValue($item);
			}
			return ['arrayValue' => ['values' => $values]];
		}
		// Fallback: stringify
		return ['stringValue' => (string)$value];
	}

	/**
	 * Get current time in nanoseconds.
	 */
	private function nowNano(): int
	{
		// hrtime gives [seconds, nanoseconds] since an arbitrary point,
		// but OTLP requires Unix epoch nanoseconds.
		$micro = microtime(true);
		return (int)($micro * 1_000_000_000);
	}

	/**
	 * Get or create the HTTP client instance.
	 */
	private function getHttpClient(): EnchiladaHTTP
	{
		if ($this->http === null) {
			$this->http = new EnchiladaHTTP($this->endpoint);
			$this->http->setTimeout($this->timeout);
		}
		return $this->http;
	}
}
