<?php
/* Enchilada Framework 3.0 
 * Dynamic Libraries Loading Component
 * 
 * $Id$
 * 
 * Software License Agreement (BSD License)
 * 
 * Copyright (c) 2013-2014, The Daniel Morante Company, Inc.
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

 // Default Librairies location
$libraries_path = defined('APPLICATION_LIBDIR') ? APPLICATION_LIBDIR : (defined('APPLICATION_ROOT') ? APPLICATION_ROOT : '') . 'libraries' . DIRECTORY_SEPARATOR;

// Locate places to look for to dynamicly load external supporting libraries
$system_path_finder = function($path) use (&$system_path_finder){
	//echo $path . PHP_EOL;
	$paths = array();
	// If the path is invalid, throw an exception.
	if (!is_dir($path)){throw new Exception("Invalid dynamic library path specified: \"$path\" not found.", 1);}

	// Compile a list of directories that will be used by the auto-loader to search for libraries
	$libraries_directory = dir($path);

	if ($libraries_directory){
		while (false !== ($entry = $libraries_directory->read())) {
			// Only interested in directories
			if (!is_dir($path . $entry)){continue;}
			// Skip Current Dir and Parent Dir and don't look in the 'composer' and 'vendor' directories
			if ($entry == '.' || $entry == '..' || $entry == 'composer' || $entry == 'vendor'){continue;}
			// Merge
			$paths = array_merge($paths, $system_path_finder($path . $entry . '/'));
		}
		// Save (realpath returns false for phar:// URIs, use path as-is in that case)
		$resolved = realpath($path);
		$paths[] = ($resolved !== false) ? $resolved : rtrim($path, '/');
		// Close
		$libraries_directory->close();
	}

	return $paths;
};

// Support for standalone Composer based libraries under the system libraries path
$autoload_finder = function () use ($libraries_path){
	$autoloaders = array();
	$libraries_directory = dir($libraries_path);
	if ($libraries_directory){
		// No recursion
		while (false !== ($entry = $libraries_directory->read())) {
			// Only interested in directories
			if (!is_dir($libraries_path . $entry)){continue;}
			// Skip Current Dir and Parent Dir
			if ($entry == '.' || $entry == '..'){continue;}
			// Save the autoloader
			$autoloader = $libraries_path . $entry . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
			if (is_file($autoloader)){
				$autoloaders[] = $autoloader;
			}
		}
		$libraries_directory->close();
	}
	return $autoloaders;
};

// Set the include paths as located
set_include_path(get_include_path() . PATH_SEPARATOR . implode(PATH_SEPARATOR, $system_path_finder($libraries_path)));

// Register the system auto loader
spl_autoload_register(function($className) {
    $namespace = str_replace("\\" ,"/",__NAMESPACE__);
    $className = str_replace("\\","/",$className);

	// For those cases where a library may not follow the normal patterns and consit of either a single file or has it's own autoloader
	$libraryName = strtok($className, '/');

	// Use APPLICATION_ROOT for absolute path resolution (enables phar:// support)
	$root = defined('APPLICATION_ROOT') ? APPLICATION_ROOT : '';

	$guesses = array($root . 'libraries' . DIRECTORY_SEPARATOR . (empty($namespace)?"":$namespace."/")."{$className}.php",
                     $root . 'libraries' . DIRECTORY_SEPARATOR . (empty($namespace)?"":$namespace."/")."{$className}.class.php",
                     $root . 'libraries' . DIRECTORY_SEPARATOR . (empty($namespace)?"":$namespace."/")."{$className}.trait.php",
                     $root . 'libraries' . DIRECTORY_SEPARATOR . (empty($namespace)?"":$namespace."/")."{$className}.interface.php",                     
                     $root . 'libraries' . DIRECTORY_SEPARATOR . $className . DIRECTORY_SEPARATOR ."{$className}.php",
                     $root . 'libraries' . DIRECTORY_SEPARATOR . $className . DIRECTORY_SEPARATOR ."{$className}.class.php",
                     $root . 'libraries' . DIRECTORY_SEPARATOR . $className . DIRECTORY_SEPARATOR ."{$className}.trait.php",
                     $root . 'libraries' . DIRECTORY_SEPARATOR . $className . DIRECTORY_SEPARATOR ."{$className}.interface.php",
					 $root . 'libraries' . DIRECTORY_SEPARATOR . $libraryName . DIRECTORY_SEPARATOR . strtolower($libraryName). ".php",
					 $root . 'libraries' . DIRECTORY_SEPARATOR . $libraryName . DIRECTORY_SEPARATOR . "{$libraryName}.php",
					 $root . 'libraries' . DIRECTORY_SEPARATOR . (empty($namespace) ? "" : $namespace . DIRECTORY_SEPARATOR) . strtolower($libraryName) . ".php",
					 $root . 'libraries' . DIRECTORY_SEPARATOR . (empty($namespace) ? "" : $namespace . DIRECTORY_SEPARATOR) . "{$libraryName}.php",
                     $root . 'classes' . DIRECTORY_SEPARATOR . (empty($namespace)?"":$namespace . DIRECTORY_SEPARATOR) . "{$className}.class.php",
                     $root . 'classes' . DIRECTORY_SEPARATOR . (empty($namespace)?"":$namespace . DIRECTORY_SEPARATOR) . "{$className}.trait.php",
                     $root . 'classes' . DIRECTORY_SEPARATOR . (empty($namespace)?"":$namespace . DIRECTORY_SEPARATOR) . "{$className}.interface.php",
    );

    foreach ($guesses as $guess){
        //echo $guess . PHP_EOL;
        if(file_exists($guess)){
            $class = $guess;
            break;
        }
    }

    if(!empty($class)){
        include $class;
    }
    else{  
        spl_autoload_extensions(".class.php,.php,.inc,.interface.php,.trait.php");
        spl_autoload($className);
    }
});

// Run any 'autoload.php' that was found as part of packaged libraries installed without composer
foreach($autoload_finder() as $autoload_file){ include $autoload_file; }

// Support legeacy Composer based libraries in the 'composer' folder under the system libriaries path
if (is_file($libraries_path . 'composer' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php')){include $libraries_path . 'composer' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';}

// Clean up
unset($system_path_finder);
unset($libraries_path);
unset($autoload_finder);
unset($autoload_file);

?>