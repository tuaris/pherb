<?php
namespace EnchiladaREST;

/* Enchilada Framework 3.0 
 * REST API Server/Router
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
 * REST API router and server for the Enchilada Framework.
 * 
 * Provides route registration, parameter extraction, middleware support,
 * and request dispatching for building RESTful APIs.
 * 
 * Usage:
 *   $api = new EnchiladaREST('/api/v1');
 *   $api->get('/users', function($req, $res) { ... });
 *   $api->get('/users/{id}', function($req, $res) { ... });
 *   $api->post('/users', function($req, $res) { ... });
 *   $api->run();
 * 
 * @author Daniel Morante
 */
class EnchiladaREST {

	protected string $basePath;
	protected array $routes = [];
	protected array $middleware = [];
	protected bool $corsEnabled = false;
	protected array $corsOptions = [];

	/**
	 * Create a new REST API server instance.
	 * 
	 * @param string $basePath Base path prefix for all routes (e.g., '/api/v1')
	 */
	public function __construct(string $basePath = '') {
		$this->basePath = rtrim($basePath, '/');
	}

	/**
	 * Register a GET route.
	 * 
	 * @param string $path Route path (can include {param} placeholders)
	 * @param callable $handler Route handler function(EnchiladaRequest, EnchiladaResponse)
	 * @return self
	 */
	public function get(string $path, callable $handler): self {
		return $this->addRoute('GET', $path, $handler);
	}

	/**
	 * Register a POST route.
	 * 
	 * @param string $path Route path
	 * @param callable $handler Route handler
	 * @return self
	 */
	public function post(string $path, callable $handler): self {
		return $this->addRoute('POST', $path, $handler);
	}

	/**
	 * Register a PUT route.
	 * 
	 * @param string $path Route path
	 * @param callable $handler Route handler
	 * @return self
	 */
	public function put(string $path, callable $handler): self {
		return $this->addRoute('PUT', $path, $handler);
	}

	/**
	 * Register a PATCH route.
	 * 
	 * @param string $path Route path
	 * @param callable $handler Route handler
	 * @return self
	 */
	public function patch(string $path, callable $handler): self {
		return $this->addRoute('PATCH', $path, $handler);
	}

	/**
	 * Register a DELETE route.
	 * 
	 * @param string $path Route path
	 * @param callable $handler Route handler
	 * @return self
	 */
	public function delete(string $path, callable $handler): self {
		return $this->addRoute('DELETE', $path, $handler);
	}

	/**
	 * Register a route for multiple HTTP methods.
	 * 
	 * @param array $methods Array of HTTP methods
	 * @param string $path Route path
	 * @param callable $handler Route handler
	 * @return self
	 */
	public function match(array $methods, string $path, callable $handler): self {
		foreach ($methods as $method) {
			$this->addRoute(strtoupper($method), $path, $handler);
		}
		return $this;
	}

	/**
	 * Register a route for any HTTP method.
	 * 
	 * @param string $path Route path
	 * @param callable $handler Route handler
	 * @return self
	 */
	public function any(string $path, callable $handler): self {
		return $this->match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], $path, $handler);
	}

	/**
	 * Add a route to the router.
	 * 
	 * @param string $method HTTP method
	 * @param string $path Route path
	 * @param callable $handler Route handler
	 * @return self
	 */
	protected function addRoute(string $method, string $path, callable $handler): self {
		$fullPath = $this->basePath . '/' . ltrim($path, '/');
		$pattern = $this->pathToRegex($fullPath);

		$this->routes[] = [
			'method' => $method,
			'path' => $fullPath,
			'pattern' => $pattern['regex'],
			'params' => $pattern['params'],
			'handler' => $handler,
		];

		return $this;
	}

	/**
	 * Convert a route path to a regex pattern.
	 * 
	 * Supports:
	 *   {param}     - Required parameter (matches anything except /)
	 *   {param?}    - Optional parameter
	 *   {param:num} - Parameter with numeric constraint
	 * 
	 * @param string $path Route path
	 * @return array ['regex' => pattern, 'params' => param names]
	 */
	protected function pathToRegex(string $path): array {
		$params = [];
		
		// Extract parameter names and build regex
		$pattern = preg_replace_callback(
			'#\{([a-zA-Z_][a-zA-Z0-9_]*)(\?)?(?::([a-z]+))?\}#',
			function ($matches) use (&$params) {
				$name = $matches[1];
				$optional = !empty($matches[2]);
				$constraint = $matches[3] ?? null;

				$params[] = $name;

				// Determine regex based on constraint
				$regex = match($constraint) {
					'num', 'number', 'int' => '[0-9]+',
					'alpha' => '[a-zA-Z]+',
					'alphanum' => '[a-zA-Z0-9]+',
					'slug' => '[a-zA-Z0-9\-_]+',
					'uuid' => '[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}',
					default => '[^/]+',
				};

				if ($optional) {
					return "(?:($regex))?";
				}
				return "($regex)";
			},
			$path
		);

		// Escape slashes and anchor the pattern
		$pattern = '#^' . $pattern . '$#';

		return [
			'regex' => $pattern,
			'params' => $params,
		];
	}

	/**
	 * Add middleware to run before route handlers.
	 * 
	 * Middleware receives (EnchiladaRequest, EnchiladaResponse, callable $next).
	 * Call $next() to continue to the next middleware or route handler.
	 * 
	 * @param callable $middleware Middleware function
	 * @return self
	 */
	public function use(callable $middleware): self {
		$this->middleware[] = $middleware;
		return $this;
	}

	/**
	 * Enable CORS support.
	 * 
	 * @param array $options CORS options (origins, methods, headers, maxAge)
	 * @return self
	 */
	public function enableCors(array $options = []): self {
		$this->corsEnabled = true;
		$this->corsOptions = array_merge([
			'origins' => '*',
			'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
			'headers' => ['Content-Type', 'Authorization'],
			'maxAge' => 86400,
		], $options);
		return $this;
	}

	/**
	 * Run the API server and dispatch the current request.
	 */
	public function run(): void {
		$request = new EnchiladaRequest();
		$response = new EnchiladaResponse();

		// Handle CORS
		if ($this->corsEnabled) {
			$response->setCors(
				$this->corsOptions['origins'],
				$this->corsOptions['methods'],
				$this->corsOptions['headers'],
				$this->corsOptions['maxAge']
			);

			// Handle preflight requests
			if ($request->getMethod() === 'OPTIONS') {
				$response->noContent();
				return;
			}
		}

		// Find matching route
		$route = $this->matchRoute($request);

		if ($route === null) {
			$response->notFound('Endpoint not found');
			return;
		}

		// Extract route parameters
		$request->setRouteParams($route['matchedParams']);

		// Build middleware chain
		$handler = $route['handler'];
		$middlewareChain = array_reverse($this->middleware);

		foreach ($middlewareChain as $middleware) {
			$next = $handler;
			$handler = function ($req, $res) use ($middleware, $next) {
				return $middleware($req, $res, function () use ($next, $req, $res) {
					return $next($req, $res);
				});
			};
		}

		// Execute handler
		try {
			$handler($request, $response);
		} catch (Exception $e) {
			if (defined('APPLICATION_DEBUG') && APPLICATION_DEBUG) {
				$response->error($e->getMessage(), 500, [
					'file' => $e->getFile(),
					'line' => $e->getLine(),
					'trace' => $e->getTraceAsString(),
				]);
			} else {
				$response->serverError('An unexpected error occurred');
			}
		}

		// Ensure response is sent
		if (!$response->isSent()) {
			$response->send();
		}
	}

	/**
	 * Find a matching route for the request.
	 * 
	 * @param EnchiladaRequest $request
	 * @return array|null Matched route with extracted params, or null
	 */
	protected function matchRoute(EnchiladaRequest $request): ?array {
		$method = $request->getMethod();
		$path = $request->getPath();

		foreach ($this->routes as $route) {
			// Check method
			if ($route['method'] !== $method) {
				continue;
			}

			// Check path pattern
			if (preg_match($route['pattern'], $path, $matches)) {
				// Extract named parameters
				$params = [];
				array_shift($matches); // Remove full match

				foreach ($route['params'] as $index => $name) {
					$params[$name] = $matches[$index] ?? null;
				}

				return array_merge($route, ['matchedParams' => $params]);
			}
		}

		return null;
	}

	/**
	 * Get all registered routes (for debugging/documentation).
	 * 
	 * @return array
	 */
	public function getRoutes(): array {
		return array_map(function ($route) {
			return [
				'method' => $route['method'],
				'path' => $route['path'],
				'params' => $route['params'],
			];
		}, $this->routes);
	}

	/**
	 * Create a route group with a shared prefix.
	 * 
	 * @param string $prefix Path prefix for the group
	 * @param callable $callback Function that registers routes
	 * @return self
	 */
	public function group(string $prefix, callable $callback): self {
		$originalBasePath = $this->basePath;
		$this->basePath = $originalBasePath . '/' . ltrim($prefix, '/');
		
		$callback($this);
		
		$this->basePath = $originalBasePath;
		return $this;
	}

}
