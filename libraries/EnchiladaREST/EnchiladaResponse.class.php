<?php

/* Enchilada Framework 3.0 
 * REST API Response Builder
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
 * Builds and sends HTTP responses for REST APIs.
 * Supports JSON responses, status codes, headers, and CORS.
 * 
 * @author Daniel Morante
 */
class EnchiladaResponse {

	const CONTENT_TYPE_JSON = 'application/json; charset=utf-8';
	const CONTENT_TYPE_HTML = 'text/html; charset=utf-8';
	const CONTENT_TYPE_TEXT = 'text/plain; charset=utf-8';

	protected int $statusCode = 200;
	protected array $headers = [];
	protected mixed $body = null;
	protected bool $sent = false;

	/**
	 * Common HTTP status codes and their reason phrases.
	 */
	protected static array $statusPhrases = [
		200 => 'OK',
		201 => 'Created',
		204 => 'No Content',
		301 => 'Moved Permanently',
		302 => 'Found',
		304 => 'Not Modified',
		400 => 'Bad Request',
		401 => 'Unauthorized',
		403 => 'Forbidden',
		404 => 'Not Found',
		405 => 'Method Not Allowed',
		409 => 'Conflict',
		422 => 'Unprocessable Entity',
		429 => 'Too Many Requests',
		500 => 'Internal Server Error',
		502 => 'Bad Gateway',
		503 => 'Service Unavailable',
	];

	/**
	 * Set the HTTP status code.
	 * 
	 * @param int $code HTTP status code
	 * @return self
	 */
	public function setStatus(int $code): self {
		$this->statusCode = $code;
		return $this;
	}

	/**
	 * Set a response header.
	 * 
	 * @param string $name Header name
	 * @param string $value Header value
	 * @return self
	 */
	public function setHeader(string $name, string $value): self {
		$this->headers[$name] = $value;
		return $this;
	}

	/**
	 * Set multiple headers at once.
	 * 
	 * @param array $headers Associative array of headers
	 * @return self
	 */
	public function setHeaders(array $headers): self {
		foreach ($headers as $name => $value) {
			$this->headers[$name] = $value;
		}
		return $this;
	}

	/**
	 * Set CORS headers for cross-origin requests.
	 * 
	 * @param string|array $origins Allowed origin(s), '*' for any
	 * @param array $methods Allowed HTTP methods
	 * @param array $headers Allowed request headers
	 * @param int $maxAge Preflight cache duration in seconds
	 * @return self
	 */
	public function setCors(
		string|array $origins = '*',
		array $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
		array $headers = ['Content-Type', 'Authorization'],
		int $maxAge = 86400
	): self {
		// Determine origin to use
		if (is_array($origins)) {
			$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
			$origin = in_array($requestOrigin, $origins) ? $requestOrigin : $origins[0];
		} else {
			$origin = $origins;
		}

		$this->setHeader('Access-Control-Allow-Origin', $origin);
		$this->setHeader('Access-Control-Allow-Methods', implode(', ', $methods));
		$this->setHeader('Access-Control-Allow-Headers', implode(', ', $headers));
		$this->setHeader('Access-Control-Max-Age', (string)$maxAge);

		return $this;
	}

	/**
	 * Set the response body.
	 * 
	 * @param mixed $body Response body
	 * @return self
	 */
	public function setBody(mixed $body): self {
		$this->body = $body;
		return $this;
	}

	/**
	 * Send a JSON response.
	 * 
	 * @param mixed $data Data to encode as JSON
	 * @param int|null $status Optional status code
	 * @param int $flags JSON encoding flags
	 */
	public function json(mixed $data, ?int $status = null, int $flags = JSON_UNESCAPED_SLASHES): void {
		if ($status !== null) {
			$this->statusCode = $status;
		}

		$this->setHeader('Content-Type', self::CONTENT_TYPE_JSON);
		$this->setHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
		$this->body = json_encode($data, $flags);

		$this->send();
	}

	/**
	 * Send a successful JSON response with standard envelope.
	 * 
	 * @param mixed $data Response data
	 * @param int $status HTTP status code (default 200)
	 */
	public function success(mixed $data, int $status = 200): void {
		$this->json([
			'success' => true,
			'data' => $data,
		], $status);
	}

	/**
	 * Send an error JSON response with standard envelope.
	 * 
	 * @param string $message Error message
	 * @param int $status HTTP status code (default 400)
	 * @param array|null $details Additional error details
	 * @param string|null $code Error code identifier
	 */
	public function error(string $message, int $status = 400, ?array $details = null, ?string $code = null): void {
		$response = [
			'success' => false,
			'error' => $message,
		];

		if ($code !== null) {
			$response['code'] = $code;
		}

		if ($details !== null) {
			$response['details'] = $details;
		}

		$this->json($response, $status);
	}

	/**
	 * Send a 404 Not Found response.
	 * 
	 * @param string $message Error message
	 */
	public function notFound(string $message = 'Resource not found'): void {
		$this->error($message, 404, null, 'NOT_FOUND');
	}

	/**
	 * Send a 401 Unauthorized response.
	 * 
	 * @param string $message Error message
	 */
	public function unauthorized(string $message = 'Authentication required'): void {
		$this->error($message, 401, null, 'UNAUTHORIZED');
	}

	/**
	 * Send a 403 Forbidden response.
	 * 
	 * @param string $message Error message
	 */
	public function forbidden(string $message = 'Access denied'): void {
		$this->error($message, 403, null, 'FORBIDDEN');
	}

	/**
	 * Send a 400 Bad Request response.
	 * 
	 * @param string $message Error message
	 * @param array|null $details Validation errors or details
	 */
	public function badRequest(string $message = 'Invalid request', ?array $details = null): void {
		$this->error($message, 400, $details, 'BAD_REQUEST');
	}

	/**
	 * Send a 500 Internal Server Error response.
	 * 
	 * @param string $message Error message
	 */
	public function serverError(string $message = 'Internal server error'): void {
		$this->error($message, 500, null, 'SERVER_ERROR');
	}

	/**
	 * Send a 204 No Content response.
	 */
	public function noContent(): void {
		$this->statusCode = 204;
		$this->body = null;
		$this->send();
	}

	/**
	 * Send a 201 Created response.
	 * 
	 * @param mixed $data Created resource data
	 * @param string|null $location URL of the created resource
	 */
	public function created(mixed $data, ?string $location = null): void {
		if ($location !== null) {
			$this->setHeader('Location', $location);
		}
		$this->success($data, 201);
	}

	/**
	 * Send the response to the client.
	 */
	public function send(): void {
		if ($this->sent) {
			return;
		}

		// Set status code
		$phrase = self::$statusPhrases[$this->statusCode] ?? 'Unknown';
		http_response_code($this->statusCode);

		// Send headers
		foreach ($this->headers as $name => $value) {
			header("$name: $value");
		}

		// Send body
		if ($this->body !== null) {
			echo $this->body;
		}

		$this->sent = true;
	}

	/**
	 * Check if response has been sent.
	 * 
	 * @return bool
	 */
	public function isSent(): bool {
		return $this->sent;
	}

	/**
	 * Static factory for quick responses.
	 * 
	 * @return self
	 */
	public static function make(): self {
		return new self();
	}

	/**
	 * Magic getter for property access.
	 */
	public function __get(string $name): mixed {
		return match($name) {
			'StatusCode' => $this->statusCode,
			'Headers' => $this->headers,
			'Body' => $this->body,
			'Sent' => $this->sent,
			default => null,
		};
	}

}
