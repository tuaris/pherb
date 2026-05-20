<?php
namespace EnchiladaREST;

/* Enchilada Framework 3.0 
 * REST API Request Handler
 * 
 * $Id$
 * 
 * Software License Agreement (BSD License)
 * 
 * Copyright (c) 2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 * 
 * Redistribution and use of this software in source and binary forms, with or without modification, are
 * permitted provided that the following conditions are met:
 * 
 *   Redistributions of source code must retain the above
 *   copyright notice, this list of conditions and the
 *   following disclaimer.
 * 
 *   Redistributions in binary form must reproduce the above
 *   copyright notice, this list of conditions and the
 *   following disclaimer in the documentation and/or other
 *   materials provided with the distribution.
 * 
 *   Neither the name of The Daniel Morante Company, Inc. nor the names of its
 *   contributors may be used to endorse or promote products
 *   derived from this software without specific prior
 *   written permission of The Daniel Morante Company, Inc.
 * 
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED
 * WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A
 * PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
 * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR
 * TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF
 * ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

/**
 * Encapsulates an incoming HTTP request for REST API handling.
 * Provides convenient access to method, path, headers, query params, and body.
 * 
 * @author Daniel Morante
 */
class EnchiladaRequest {

	protected string $method;
	protected string $path;
	protected array $query;
	protected array $headers;
	protected ?string $rawBody;
	protected ?array $jsonBody;
	protected array $routeParams;

	/**
	 * Create a new request instance from PHP globals.
	 */
	public function __construct() {
		$this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
		$this->path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
		$this->query = $_GET;
		$this->headers = $this->parseHeaders();
		$this->rawBody = null;
		$this->jsonBody = null;
		$this->routeParams = [];
	}

	/**
	 * Parse request headers from $_SERVER.
	 * 
	 * @return array Associative array of headers (lowercase keys)
	 */
	protected function parseHeaders(): array {
		$headers = [];
		
		// Try getallheaders() first (Apache)
		if (function_exists('getallheaders')) {
			foreach (getallheaders() as $name => $value) {
				$headers[strtolower($name)] = $value;
			}
			return $headers;
		}
		
		// Fallback to parsing $_SERVER
		foreach ($_SERVER as $key => $value) {
			if (strpos($key, 'HTTP_') === 0) {
				$name = str_replace('_', '-', strtolower(substr($key, 5)));
				$headers[$name] = $value;
			} elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'])) {
				$name = str_replace('_', '-', strtolower($key));
				$headers[$name] = $value;
			}
		}
		
		return $headers;
	}

	/**
	 * Get the HTTP method.
	 * 
	 * @return string GET, POST, PUT, PATCH, DELETE, etc.
	 */
	public function getMethod(): string {
		return $this->method;
	}

	/**
	 * Get the request path (without query string).
	 * 
	 * @return string
	 */
	public function getPath(): string {
		return $this->path;
	}

	/**
	 * Get all query parameters.
	 * 
	 * @return array
	 */
	public function getQuery(): array {
		return $this->query;
	}

	/**
	 * Get a specific query parameter.
	 * 
	 * @param string $key Parameter name
	 * @param mixed $default Default value if not present
	 * @return mixed
	 */
	public function getQueryParam(string $key, mixed $default = null): mixed {
		return $this->query[$key] ?? $default;
	}

	/**
	 * Get all request headers.
	 * 
	 * @return array Associative array with lowercase keys
	 */
	public function getHeaders(): array {
		return $this->headers;
	}

	/**
	 * Get a specific header value.
	 * 
	 * @param string $name Header name (case-insensitive)
	 * @param string|null $default Default if not present
	 * @return string|null
	 */
	public function getHeader(string $name, ?string $default = null): ?string {
		return $this->headers[strtolower($name)] ?? $default;
	}

	/**
	 * Get the raw request body.
	 * 
	 * @return string
	 */
	public function getRawBody(): string {
		if ($this->rawBody === null) {
			$this->rawBody = file_get_contents('php://input') ?: '';
		}
		return $this->rawBody;
	}

	/**
	 * Get the request body parsed as JSON.
	 * 
	 * @return array|null Decoded JSON or null if invalid/empty
	 */
	public function getJsonBody(): ?array {
		if ($this->jsonBody === null) {
			$raw = $this->getRawBody();
			if (!empty($raw)) {
				$decoded = json_decode($raw, true);
				$this->jsonBody = is_array($decoded) ? $decoded : null;
			}
		}
		return $this->jsonBody;
	}

	/**
	 * Get a value from the JSON body.
	 * 
	 * @param string $key Key name
	 * @param mixed $default Default if not present
	 * @return mixed
	 */
	public function getJsonParam(string $key, mixed $default = null): mixed {
		$body = $this->getJsonBody();
		return $body[$key] ?? $default;
	}

	/**
	 * Set route parameters (populated by the router after matching).
	 * 
	 * @param array $params Route parameters
	 */
	public function setRouteParams(array $params): void {
		$this->routeParams = $params;
	}

	/**
	 * Get all route parameters.
	 * 
	 * @return array
	 */
	public function getRouteParams(): array {
		return $this->routeParams;
	}

	/**
	 * Get a specific route parameter.
	 * 
	 * @param string $name Parameter name
	 * @param mixed $default Default if not present
	 * @return mixed
	 */
	public function getRouteParam(string $name, mixed $default = null): mixed {
		return $this->routeParams[$name] ?? $default;
	}

	/**
	 * Check if the request expects JSON response.
	 * 
	 * @return bool
	 */
	public function expectsJson(): bool {
		$accept = $this->getHeader('accept', '');
		return strpos($accept, 'application/json') !== false || strpos($accept, '*/*') !== false;
	}

	/**
	 * Check if the request has JSON content.
	 * 
	 * @return bool
	 */
	public function isJson(): bool {
		$contentType = $this->getHeader('content-type', '');
		return strpos($contentType, 'application/json') !== false;
	}

	/**
	 * Get the client IP address.
	 * 
	 * @return string
	 */
	public function getClientIp(): string {
		// Check for forwarded headers (behind proxy)
		if ($forwarded = $this->getHeader('x-forwarded-for')) {
			$ips = array_map('trim', explode(',', $forwarded));
			return $ips[0];
		}
		
		return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
	}

	/**
	 * Get the Bearer token from Authorization header.
	 * 
	 * @return string|null
	 */
	public function getBearerToken(): ?string {
		$auth = $this->getHeader('authorization');
		if ($auth && preg_match('/^Bearer\s+(.+)$/i', $auth, $matches)) {
			return $matches[1];
		}
		return null;
	}

	/**
	 * Magic getter for convenient property access.
	 */
	public function __get(string $name): mixed {
		return match($name) {
			'Method' => $this->method,
			'Path' => $this->path,
			'Query' => $this->query,
			'Headers' => $this->headers,
			'Body' => $this->getJsonBody(),
			'RawBody' => $this->getRawBody(),
			'RouteParams' => $this->routeParams,
			'ClientIp' => $this->getClientIp(),
			default => null,
		};
	}

}
