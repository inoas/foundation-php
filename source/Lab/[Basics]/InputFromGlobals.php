<?php
/*
 * Copyright (C) 2011-2014 Solver Ltd. All rights reserved.
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
namespace Solver\Lab;

/** 
 * Prepares an array of input values from PHP's globals (as used by Router's dispatch method).
 * 
 * In the process it undoes the damage PHP does to the input $_FILES metadata. Currently, to read the file name of an
 * upload with field name="a[b][c][d]", you need to query:
 * 
 * $_FILES['a']['name']['b']['c']['d'];
 * 
 * This class transforms all similar constructs into the more natural:
 * 
 * $_FILES['a']['b']['c']['d']['name'];
 * 
 * The class also wraps every file metadata array into an instance of HttpUpload, and injects that into $_POST where it
 * belongs (i.e. key "body" in the returned array). This means that for the example upload name above, you can find the
 * upload here in the returned array:
 * 
 * $input['body']['a']['b']['c']['d']
 * 
 * And it'll be an instance of HttpUpload.
 */
class InputFromGlobals {
	/**
	 * @return array
	 * An array with the following keys:
	 * 
	 * - "query" ($_GET)
	 * - "body" (combined $_POST and $_FILES, where files become objects of instance HttpUpload)
	 * - "cookies" ($_COOKIE)
	 * - "server" ($_SERVER)
	 * - "env" ($_ENV)
	 */
	public static function get() {
		return [
			'query' => $_GET,
			'body' => $_FILES ? self::injectHttpUploads($_POST, $_FILES) : $_POST,
			'cookies' => $_COOKIE,
			'server' => $_SERVER,
			'env' => $_ENV,
		];
	}
	
	protected static function injectHttpUploads($body, $files) {
		// TODO: This sequence here can certainly be optimized, but now porting from my older code not to waste time.
		$files = self::fixUploadsMetadata($files);
		$files = self::replaceWithHttpUpload($files);
		return self::merge($body, $files);
	}
	
	protected static function merge($a, $b) {
		// Implements recursive merge of two deep arrays.
		$merge = function (& $a, $b) use (& $merge) {
			foreach ($b as $k => $bv) {
				if (\key_exists($k, $a)) {
					$av = & $a[$k];
					if (\is_array($av) && \is_array($bv)) {
						$merge($av, $bv);
					} else {
						$av = $bv;
					}
				} else {
					$a[$k] = $bv;
				}
			}
		};
		
		$merge($a, $b);
		return $a;
	}
	
	protected static function replaceWithHttpUpload($files) {
		// Detects a file metatdata array in a $_FILES like structure and replaces it with an instance of HttpUpload.
		$replace = function (& $files) use (& $replace) {
			if (\key_exists('name', $files) && !\is_array($files['name'])) {
				// File record.
				$files = new HttpUpload($files['tmp_name'], $files['error'], $files['size'], $files['name'], $files['type']);
			} else {
				// Go deeper down the hole.
				foreach ($files as & $v) {
					$replace($v);
				}
			}
		};
		
		$replace($files);
		
		return $files;
	}
	
	/**
	 * @param array $files
	 * $_FILES-formatted array input to reformat.
	 * 
	 * @return array
	 * Reformatted files array.
	 */
	protected static function fixUploadsMetadata(array $files) {	
		$output = array();
		$bubbleSegment = function ($input, & $output, $segment) use (& $bubbleSegment) {
			if (\is_scalar($input)) {
				$output[$segment] = $input;
			} else {
				foreach ($input as $key => $val) {
					if (!isset($output[$key])) $output[$key] = array();
					$bubbleSegment($input[$key], $output[$key], $segment);
				}
			}
		};
			
		foreach ($files as $key => $val) {		
			// If level 2 items are scalars, then this top-level entry is not array (no need to bubble).
			if (\is_scalar($val['name'])) {
				$output[$key] = $val;
			} 
			
			// Undo some damage done by PHP... 
			else {
				$output[$key] = array();
				$bubbleSegment($val['name'], $output[$key], 'name');
				$bubbleSegment($val['tmp_name'], $output[$key], 'tmp_name');
				$bubbleSegment($val['type'], $output[$key], 'type');
				$bubbleSegment($val['size'], $output[$key], 'size');
				$bubbleSegment($val['error'], $output[$key], 'error');
			}
		}
		
		return $output;
	}
}