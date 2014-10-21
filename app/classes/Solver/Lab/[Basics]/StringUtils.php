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
 * String API for UTF8 encoded text. 
 * 
 * Important: This API is not binary safe. To work with ASCII strings and strings as byte buffers, use the standard 
 * PHP string API.
 * 
 * All methods in this API assume the strings passed have been normalized NFC-style, which is the default normalization
 * style for the framework. You need to normalize strings that come from unknown sources (user input etc.).
 * 
 * TODO: PHPDoc for all methods.
 * TODO: Implement the rest of the methods from the mapping chart.
 */
class StringUtils {
	const NFC = \Normalizer::FORM_C;
	const NFD = \Normalizer::FORM_D;
	const NFKD = \Normalizer::FORM_KD;
	const NFKC = \Normalizer::FORM_KC;
	
	public static function normalize($string, $form = self::NFC) {
		return \normalizer_normalize($string, $form);
	}
	
	public static function length($string) {
		return \mb_strlen($string, 'UTF-8');
	}
	
	public static function slice($string, $index, $length = null) {
		return \mb_substr($string, $index, $length, 'UTF-8');
	}
	
	public static function indexOf($string, $substring, $startIndex = null) {
		$indexOut = \mb_strpos($string, $substring, $startIndex !== null ? $startIndex : 0, 'UTF-8');
		return $indexOut !== false ? $indexOut : -1;
	}
	
	public static function lastIndexOf($string, $substring, $startIndex = null) {
		$indexOut = \mb_strrpos($string, $substring, $startIndex !== null ? $startIndex : 0, 'UTF-8');
		return $indexOut !== false ? $indexOut : -1;
	}
	
	public static function toLowerCase($string) {
		return \mb_strtolower($string, 'UTF-8');
	}
	
	public static function toUpperCase($string) {
		return \mb_strtoupper($string, 'UTF-8');
	}
	
	public static function trimWhitespace($string) {
		// This covers all Unicode whitespace codepoints (vs. \trim() which covers only ASCII ones).
		return RegexUtils::replace($string, '/^\pZ+|\pZ+$/D', '');
	}
}