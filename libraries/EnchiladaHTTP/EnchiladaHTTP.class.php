<?php

/* Enchilada Framework 3.0 
 * HTTP Client Foundation
 * 
 * $Id$
 * 
 * Software License Agreement (BSD License)
 * 
 * Copyright (c) 2013-2025, The Daniel Morante Company, Inc.
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

/*
 * Aids in the construction RESTful API clients
 * @author Daniel Morante
 */

class EnchiladaHTTP {

	const CONTENT_TYPE_JSON = 'Content-Type: application/json';
	const CONTENT_TYPE_FORM_ENCODED = 'Content-type: application/x-www-form-urlencoded';
	const CONTENT_TYPE_NONE = NULL;
	const DEFAULT_HTTP_REQUEST_TIMEOUT = 10;

	protected $api_endpoint;
	protected $debug = APPLICATION_DEBUG;
	protected $useragent = APPLICATION_USERAGENT;
	protected $request_timeout;
	
	// This is meant to be overriden
	protected $default_headers = [];
	
	// Extra stuff to pass to CURL
	protected $ca_cert;
	protected $plaintext_auth;
	protected $verify_ssl = true;
	protected $last_http_code = 0;

	/**
	 * Create a new instance
	 * @param string 
	 */
	function __construct($api_endpoint) {
		$this->api_endpoint = $api_endpoint;

		if (defined('APPLICATION_HTTP_TIMEOUT')) {
			$this->request_timeout = APPLICATION_HTTP_TIMEOUT;
		}
		else{			
			$this->request_timeout = self::DEFAULT_HTTP_REQUEST_TIMEOUT;
		}
	}

	/**
	 * Calls an API method.
	 * @param  string   $method The API method to call.
	 * @param  array    $data   An associative array of data or parameters to include in the API request.
	 * @param  string   $http_verb    HTTP verb used to make the request
	 * @param  array   $extra_headers    Associative array of additional headers to pass during HTTP request.
	 * @param  integer  $timeout Set maximum time the request is allowed to take, in seconds.
	 * @param  string  $format defaults to 'json', if set the $data will be auto-encoded to JSON and the response will be auto-decoded to an array
	 * @return  array   The response as an associative array, JSON-decoded if $format was set to 'json'.
	 */
	public function call($method, $data = NULL, $http_verb = 'GET', $extra_headers = array(), $timeout = NULL, $format = 'json') {
		//Check Method
		if (!$this->check_http_method($http_verb)) {
			throw new Exception("Invalid HTTP Verb");
		}

		// Build Headers
		$headers = $this->build_headers($extra_headers);

		// Build URL
		$url = $this->build_request($method);

		return $this->_request($url, $data, $headers, $http_verb, $timeout ?? $this->request_timeout, $format);
	}
	
	protected function build_headers(array $extra_headers = array()) {
	    return array_merge($this->default_headers, $extra_headers);
	}
	
	protected function build_request($method) {
	    return sprintf("%s/%s", $this->api_endpoint, $method);
	}

	/**
	 * Performs the underlying HTTP request.
	 * @param  string  $method  The API method to call.
	 * @param  array   $args    An associative array of arguments to pass to the API.
	 * @param  integer $timeout Set maximum time the request is allowed to take, in seconds.
	 * @return array           The response as an associative array, JSON-decoded.
	 */
	protected function _request($url, $data = NULL, $headers = array(), $method = 'POST', $timeout = NULL, $format = 'json') {

		if (!function_exists('curl_version')) {
			throw new Exception('cURL support is required for EnchiladaHTTP');
		}

		$payload = $this->build_payload($data, $format);

		// For GET requests, encode array data as query parameters
		if ($method === 'GET' && is_array($data) && !empty($data)) {
			$query = http_build_query($data);
			$url .= (strpos($url, '?') === false ? '?' : '&') . $query;
			// GET requests should not send a body
			$payload = null;
		}

		// Automatically set Content-Type header based on format if not already present
		$hasContentType = false;
		foreach ($headers as $h) {
			if (stripos($h, 'Content-Type:') === 0) {
				$hasContentType = true;
				break;
			}
		}
		if (!$hasContentType && $method != 'GET') {
			if ($format === 'json') {
				$headers[] = self::CONTENT_TYPE_JSON;
			} elseif (is_array($data)) {
				$headers[] = self::CONTENT_TYPE_FORM_ENCODED;
			}
		}

		$ch = curl_init();

		// Determine effective timeout
		$effectiveTimeout = $timeout ?? $this->request_timeout;

		curl_setopt_array($ch, array(
			CURLOPT_URL => $url,
			CURLOPT_TIMEOUT => $effectiveTimeout,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => $this->verify_ssl,
			CURLOPT_SSL_VERIFYHOST => $this->verify_ssl ? 2 : 0,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_USERAGENT => $this->useragent,
		));

		// Attach payload for non-GET requests
		if ($method != 'GET' && $payload !== NULL) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
		}

		// Optional CA certificate
		if(!empty($this->ca_cert)){
			curl_setopt($ch, CURLOPT_CAINFO, $this->ca_cert);
		}
		
		// Plain text HTTP authemtication
		if(!empty($this->plaintext_auth)){
			curl_setopt($ch, CURLOPT_USERPWD, $this->plaintext_auth);
		}

		$result = curl_exec($ch);
		$this->last_http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

		if ($result === false && $this->debug) {
			// Surface transport-level errors when debugging is enabled
			print_r(curl_error($ch));
			echo PHP_EOL;
		}

		if ($this->debug && $result !== false) {
			print_r($payload);
			echo PHP_EOL;
			print_r($result) . PHP_EOL;
			echo PHP_EOL;
			print_r(curl_getinfo($ch)) . PHP_EOL;
			echo PHP_EOL;
		}

		curl_close($ch);

		if ($format == 'json') {
			return $result ? json_decode($result, true) : false;
		} else {
			return $result ? $result : false;
		}
	}

	// Formats the payload accordingly
	protected function build_payload($data, $format) {
		if (is_array($data)) {
			if ($format == 'json') {
				$payload = json_encode($data);
			} else {
				$payload = http_build_query($data);
			}
		} else {
			$payload = $data;
		}

		return $payload;
	}

	public function __get($key) {
		switch ($key) {
			case 'Endpoint': return $this->api_endpoint;
			case 'Timeout': return $this->request_timeout;
			case 'HttpCode': return $this->last_http_code;
			default: return null;
		}
	}

	/**
	 * Sets the time to wait for the HTTP request to complete
	 * 
	 * @param int $timeout seconds to wait for
	 */
	public function setTimeout($timeout = self::DEFAULT_HTTP_REQUEST_TIMEOUT){
		if(is_int($timeout)){
			$this->request_timeout = $timeout;
		}
	}

	/**
	 * Returns the HTTP status code from the most recent request.
	 *
	 * @return int HTTP status code (e.g., 200, 401, 404, 500). Returns 0 if no request has been made.
	 */
	public function getHttpCode(): int {
		return $this->last_http_code;
	}

	/**
	 * Sets the HTTP Basic Authentication credentials
	 * 
	 * @param string $username
	 * @param string $password
	 */
	public function setPlaintextAuth($username, $password){
		$this->plaintext_auth = $username . ':' . $password;
	}

	/**
	 * Sets the CA certificate bundle path for SSL verification
	 * 
	 * @param string $path Path to CA certificate file
	 */
	public function setCaCert($path){
		$this->ca_cert = $path;
	}

	/**
	 * Enable or disable SSL certificate verification
	 * 
	 * @param bool $verify Whether to verify SSL certificates
	 */
	public function setVerifySsl(bool $verify){
		$this->verify_ssl = $verify;
	}

	/**
	 * Verifies the HTTP method is valid.
	 * @param  string  $method  The HTTP method.
	 * @return boolean          The result of the check.
	 */
	protected function check_http_method($method) {
		switch ($method) {
			case "POST":
			case "GET":
			case "PUT":
			case "PATCH":
			case "DELETE":
			case "LIST":
				$isGood = true;
				break;
			default:
				$isGood = false;
				break;
		}
		return $isGood;
	}

}
