<?php
/*
 * Copyright (C) 2011-2015 Solver Ltd. All rights reserved.
 * 
 * Licensed under the Apache License, Version 2.0 (the "License"); you may not use this file except in compliance with
 * the License. You may obtain a copy of the License at:
 * 
 * http://www.apache.org/licenses/LICENSE-2.0
 * 
 * Unless required by applicable law or agreed to in writing, software distributed under the License is distributed on
 * an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the License for the
 * specific language governing permissions and limitations under the License.
 */
namespace Solver\Lab { // This file contains multiple namespace blocks.

/** 
 * Startup routines for Solver\Lab.
 */
class Core {
	protected static $locations;
	protected static $nativeMap;
	protected static $composerMap;
	
	/**
	 * @param array $autoloadLocation
	 * A dict of locations to scan for classes, templates and any other autoloadable symbols, in this format:
	 * 
	 * <code>
	 * [
	 * 		"path/to/files" => "Optional\Namespace\Prefix",
	 * 		...,
	 * 		...
	 * ]
	 * </code>
	 * 
	 * Paths are considered relative to APP_ROOT.
	 *  
	 * For PSR-0 formatted directories, you can have an empty string as the namespace prefix.
	 * 
	 * An additional feature: directory & file name fragments wrapped in square brackets (and optionally surrounded by 
	 * whitespace) won't be counted towards the namespace and name of a class, so you can use it to organize files
	 * freely without affecting the actual class name.
	 * 
	 * All of the following filepaths will map to the same class name "Foo\BarController":
	 * 
	 * - "Foo/[Controllers]/BarController.php"
	 * - "Foo [Controllers]/BarController.php"
	 * - "Foo/[Module]/[Controllers]/BarController.php"
	 * - "Bar/BarController [Deprecated].php"
	 * - "Bar/[Experimental] BarController.php"
	 * - etc.
	 */
	public static function init($autoloadLocations) {		
		/*
		 * Check minimum requirements.
		 */
		
		if (\version_compare('5.4.11', PHP_VERSION) == 1) throw new \Exception('This code requires PHP 5.4.11 or later.');
		if (!\extension_loaded('intl')) throw new \Exception('This code requires extension "intl".');
		if (!\extension_loaded('iconv')) throw new \Exception('This code requires extension "iconv".');
		if (!\extension_loaded('mbstring')) throw new \Exception('This code requires extension "mbstring".');
		
		/*
		 * Fix common config problems.
		 */
		
		\error_reporting(-1);
		\ini_set('log_errors', !\DEBUG); // Log only on production server.
		\ini_set('display_errors', \DEBUG); // Display only on dev machines.
		if (!\ini_get('ignore_user_abort')) \ignore_user_abort(true);
		if (\ini_get('session.use_trans_sid')) \ini_set('session.use_trans_sid', false);
		
		// The defaults are typically 14 for precision, and 17 for serialize_precision. Using 14 leads to needless data
		// loss, as counterintuitively the 'precision' setting is used for many serialization contexts (json, sql etc.).
		// While in some edge cases 17 digits of precision are required to encode a double as a string exactly, it
		// produces false digits, which while they don't harm precision, produce noise and bloat up the size of the
		// serialized version of the number. Most implementations use 16 digits of precision for serializing doubles,
		// and they are defined as having 15.95 decimal digits of precision in IEEE 754, so we're using this as well.		
		if (\ini_get('precision') != 16) \ini_set('precision', 16);
		if (\ini_get('serialize_precision') != 16) \ini_set('serialize_precision', 16);
		
		/*
		 * Initialize autoloader state.
		 */
		self::$locations = $autoloadLocations;
		
		// Some packages still use this feature (odd), and since we replace Composer's autoloader, we should support it.
//		UNDER CONSIDERATION. The always-loaded files (like from Swift) are horribly slow and it's such a bad idea to 
//		always load them.
//		
// 		$autoloadFiles = \APP_ROOT . '/vendor/composer/autoload_files.php';
// 		if (file_exists($autoloadFiles)) foreach (require $autoloadFiles as $file) {
// 			require $file;
// 		}
		 
		self::$composerMap = require \APP_ROOT . '/vendor/composer/autoload_classmap.php';
		
		// TRICKY: We need the mute operator to avoid a file_exists check just for first time map cache generation.
		if ((self::$nativeMap = @include \APP_ROOT . '/cache/map.php') === false) {
			self::$nativeMap = self::map();
		}
	}
	
	/**
	 * Resolves class names & template ids to an absolute file path.
	 * 
	 * @param string $symbolId
	 * A full class name or a template id.
	 * 
	 * @return string|false
	 * Full path to the resolved file. False if the file was not found.
	 */
	public static function resolve($symbolId) {		
		/*
		 * Try the maps.
		 * 
		 * Note: Composer has no automatic rescan on stale map, we do, so always query native 1st (above), Composer 2nd.
		 * We also no longer trust Composer's map to be up-to-date (it isn't when a module in the vendor dir is being
		 * worked on) hence the file check applies to Composer too.
		 */
		
		$getVerified = function ($path) use ($symbolId) {
			// TODO: This file_exists() check can go once the error-to-exception mapper is ported. That'll speed things
			// up a notch.
			if (!\file_exists($path)) { 
				// The file was moved/renamed/deleted/etc. Stale cache. Remap.
				// TRICKY: It's not a bug the native map gets remapped even when the Composer map is stale. Developers
				// can have the native map overlap Composer by adding to it select /vendor components under development.
				self::$nativeMap = self::map();
				
				if (isset(self::$nativeMap[$symbolId])) {
					return self::$nativeMap[$symbolId];
				} else {
					return false;
				}
			} else {
				return $path;
			}
		};
		
		$map = self::$nativeMap;
		
		if (isset($map[$symbolId])) {
			$path = $getVerified(\APP_ROOT . '/' . $map[$symbolId]);			
			if ($path !== false) return $path;
		}
		
		$map = self::$composerMap;
		
		if (isset($map[$symbolId])) {
			$path = $getVerified($map[$symbolId]);			
			if ($path !== false) return $path;
		}
		
		/*
		 * No match in either map, try a re-map.
		 */
		
		$newMap = self::map();
		
		// TRICKY: Some (not very well written) third party code probes for class existence by invoking the autoloader
		// on a non-existing class. This causes a remap, but doesn't mean the script will end with a fatal error at this
		// point. So we should take care not to overwrite the native map (with false) if map() is running for the second
		// time in this script. To work around the performance implications of rescanning on every run, production code
		// should not use automatic remapping, but be deployed with a stable pre-generated map. Alternatively, code 
		// shouldn't call class_exists without disabling autoloader look-ups, if the class is expected not to exist 
		// during normal script operation (the saner alternative).
		if ($newMap !== false) {
			$map = self::$nativeMap = $newMap;
			
			if (isset($map[$symbolId])) {
				return \APP_ROOT . '/' . $map[$symbolId];
			} else {
				return false;
			}
		} else {
			return false;
		}
		
	}
	
	protected static function map() {
		// There's no point in mapping more than once per request. So we refuse a second map run & let the app fail with
		// the relevant "symbol not found" error.
		static $didMap = false;
		if ($didMap) return false;
		$didMap = true;
		
		$map = [];
		
		$scanDir = function ($dir, $ns) use (& $scanDir, & $map) {
			$ns = \trim($ns, '\\');
			
			try {
				$iterator = new \DirectoryIterator(\APP_ROOT . '/'. $dir);
			} catch (\RuntimeException $e) {
				throw new \Exception('Invalid or unreadable directory: ' . $dir . '.');
			}
			
			foreach ($iterator as $file) {
				$filename = $file->getFilename();
				
				$isDir = $file->isDir();
				
				// Skip files and dirs with a leading dot (".", "..", ".svn", ".git" etc.)
				if ($filename[0] === '.') continue;
				
				if ($isDir) {
					$scanDir($dir . '/' . $filename, $ns . '\\' . $filename, $map);
				} else {	
					$pathInfo = \pathinfo($filename);
					
					// Ignore any files not having a PHP extension.
					if (!isset($pathInfo['extension']) || $pathInfo['extension'] !== 'php') continue;
					
					$symbolPath = $dir . '/' . $filename;
					$symbolName = ($ns === '' ? '' : $ns . '\\') . $pathInfo['filename'];
					
					// Dashes are interpreted the same as directory separators: a namespace delimiter.
					$symbolName = str_replace('-', '\\', $symbolName);
					
					// Strip out brackets (and whitespace around them) and anything between them, normalize repeated
					// backslashes, if any.
					$symbolName = \preg_replace('/\s*\[.*?\]\s*/', '', $symbolName);
					$symbolName = \preg_replace('/\\\\+/', '\\', $symbolName);
					
					// Legacy Solver framework "Flow" used extension ".class.php" for classes. "Flow" also had supported
					// arbitrary folders for class placement, without namespacing. To support loading these classes we 
					// filter out ".class" and any namespace when we detect a Flow class. This should be removed when we
					// no longer have Flow code to maintain.
					if (\preg_match('/\.class$/D', $symbolName)) {
						$symbolName = \preg_replace('/(.*\\\\)|\.class$/D', '', $symbolName);
					}
					
					// Using quick heuristics to detect & support legacy classes using underscores (vs. namespaces).
					$symbolLegacyName = \str_replace('\\', '_', $symbolName);
				
					if (\preg_match('/(class|interface|trait)\s+' . $symbolLegacyName . '\b/si', \file_get_contents(\APP_ROOT . '/' . $symbolPath))) {
						$symbolName = $symbolLegacyName;
					}
					
					if (isset($map[$symbolName])) {
						throw new \Exception('Duplicate declarations for symbol ' . $symbolName . ' at file "' . $map[$symbolName] . '" and file "' . $symbolPath . '".');
					}
					
					$map[$symbolName] = $symbolPath;
				}
			}
		};
		
		foreach (self::$locations as $dir => $ns) {
			$scanDir($dir, $ns);
		}
		
		\file_put_contents(\APP_ROOT . '/cache/map.php', '<?php return ' . \var_export($map, true) . ';');
		return $map;
	}
} // Class Core.
} // Namespace Solver\Lab.

namespace {
	// TRICKY: This should be defined in the root namespace.
	function __autoload($class) {
		$path = Solver\Lab\Core::resolve($class);
		if ($path !== false) require $path;
	}
}