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
namespace Solver\Accord;

use Solver\Logging\StatusLog as SL;
use Solver\Accord\InternalTransformUtils as ITU;

/**
 * Accepts integers, floats, and strings formatted as an integer or a float. Numbers as strings will be normalized
 * (whitespace trimmed, trailing zeroes removed etc.), but left as strings to avoid precision loss.
 * 
 * TODO: Add isOneOf, isEqualTo (and negative variants) as with StringFormat, but with numbers semantics.
 * 
 * TODO: Split into distinct FloatFormat, IntFormat, BigIntFormat, BigNumber format with arbitrary precision tests and 
 * filters (for numbers in strings).
 * 
 * TODO: See "ScalarProcessor"/"ScalarFilter" from legacy code for more details and more filters/tests we should add
 * in this class.
 */
class NumberFormat implements Format, FastAction {
	use ApplyViaFastApply;
	
	protected $functions = [];
	
	/**
	 * @param int $min
	 * 
	 * @return $this
	 */
	public function isMin($min) {
		$this->functions[] = static function ($input, & $output, $mask, & $events, $path) use ($min) {
			// TODO: Use arbitrary precision semantics for large numbers in strings.
			if ($input + 0 < $min + 0) {
				if ($mask & SL::T_ERROR) ITU::addErrorTo($events, $path, "Please provide a number bigger than or equal to $min.");
				$output = null;
				return false;
			} else {
				$output = $input;
				return true;
			}
		};
		
		return $this;
	}
	
	/**
	 * @param int $max
	 * 
	 * @return $this
	 */
	public function isMax($max) {
		$this->functions[] = static function ($input, & $output, $mask, & $events, $path) use ($max) {
			// TODO: Use arbitrary precision semantics for large numbers in strings.
			if ($input + 0 > $max + 0) {
				if ($mask & SL::T_ERROR) ITU::addErrorTo($events, $path, "Please provide a number lesser than or equal to $max.");
				$output = null;
				return false;
			} else {
				$output = $input;
				return true;
			}
		};
		
		return $this;
	}
	
	/**
	 * A convenience combination of isMin() and isMax().
	 * 
	 * @param int $min
	 * 
	 * @param int $max
	 * 
	 * @return $this
	 */
	public function isInRange($min, $max) {
		$this->isMin($min);
		$this->isMax($max);
		
		return $this;
	}	
	
	/**
	 * This is identical as isMin(0), but provides a specialized error message for a common test (positive numbers).
	 * 
	 * @return $this
	 */
	public function isPositive() {
		$this->functions[] = static function ($input, & $output, $mask, & $events, $path) {
			// TODO: Use arbitrary precision semantics for large numbers in strings.
			if ($input + 0 < 0) {
				if ($mask & SL::T_ERROR) ITU::addErrorTo($events, $path, "Please provide a positive number.");
				$output = null;
				return false;
			} else {
				$output = $input;
				return true;
			}
		};
		
		return $this;
	}
	
	/**
	 * Verifies the number is one of:
	 * 
	 * - A PHP integer.
	 * - A float without a fraction and no larger than 2^53 (above which it can't accurately hold an integer value).
	 * - A positive or negative number in a string without a fraction part, or an exponent notation.
	 * 
	 * For floats, it also verifies the float value is within range for an accurately represented integer value.
	 * @return $this
	 */
	public function isInteger() {
		$this->functions[] = static function ($input, & $output, $mask, & $events, $path) {
			// FILTER_VALIDATE_INT not used because it fails on integers outside PHP's native integer range.
			if (\is_string($input) && \preg_match('/\s*[+-]?\d+\s*$/AD', $input)) return $input;
			
			if (is_int($input)) {
				$output = $input;
				return true;
			}
			
			// 9007199254740992 = 2^53 (bit precision threshold in doubles).
			if (is_float($input) && $input === floor($input) && $input <= 9007199254740992) return true;
			
			if ($mask & SL::T_ERROR) ITU::addErrorTo($events, $path, "Please provide an integer.");
			$output = null;
			return false;
		};
		
		return $this;
	}
	
	/**
	 * Corrects the format of a valid string number to avoid redundancies and inconsistencies. The resulting string 
	 * represents the exact same value as the original.
	 * 
	 * This function DOES NOT alter the mantissa/exponent mathematical values, only their formatting.
	 * 
	 * Corrections include:
	 * 
	 * 1) Trims whitespace on both sides of the string.
	 * 2) Drops leading plus sign in front of the mantissa.
	 * 3) Adds plus sign if a sign is missing in front of the exponent.
	 * 4) Drops redundant lead zeroes in the mantissa and exponent.
	 * 5) A missing lead zero in front of a decimal point will be restored for zero integer values (i.e. .10 => 0.10).
	 * 6) Drops trailing zeroes in the decimal fraction.
	 * 7) Drops entire decimal fraction, if it consists of only zeroes.
	 * 8) If the exponent is positive or negative 0, it's dropped.
	 * 9) If present, the exponent E/e is always lowercased to "e".
	 * 
	 * NOTE: The choices above were guided by the ECMA-262 specification for rendering numbers to a string and should
	 * be compatible with the widest range of languages, products and platforms.
	 * 
	 * Examples:
	 * +00012.34000e005 becomes 12.34e+5
	 * -.0123e00 becomes -0.0123
	 * 10.000000E-4 becomes 10e-4
	 *  
	 * This filter operates only on strings representing decimal floating point or integer values. It has no effect
	 * on other strings, or on PHP int/float types. The string value should contain no whitespace or other.
	 * This filter can be used in place of toTypeNumber(), when no precision should be lost from the value before
	 * storing it as a string for later processing or handing it to a third-party service that needs higher precision
	 * than PHP's native number types.
	 */
	protected function normalize($input) {
		if (preg_match('/\s*([+-])?(?|0*(\d+)|0*(\d*)(?:\.0+|\.(\d+?))0*)(?:[Ee]([+-])?(?:0+|0*(\d+)))?\s*$/AD', $input, $matches)) {
			$input = 
				(isset($matches[1]) && $matches[1] === '-' ? '-' : '' ). // integer sign
				(isset($matches[2]) && ($tmp = $matches[2]) !== '' ? $tmp : '0' ). // integer digits
				(isset($matches[3]) && ($tmp = $matches[3]) !== '.' ? '.' . $tmp : '' ). // fraction
				(
					isset($matches[5]) && ($tmp = $matches[5]) !== '' ? ( // exponent digits
						'e' . (isset($matches[4]) && $matches[4] == '-' ? '-' : '+') . $tmp // exponent sign & assembly
					) : ''
				);
		}
		
		return $input;
	}
	
	public function fastApply($input = null, & $output = null, $mask = 0, & $events = null, $path = null) {
		// We deliberately do not use the result here (a PHP float) as we don't want to lose precision for large string
		// numbers.
		if (filter_var($input, FILTER_VALIDATE_FLOAT) === false) goto error;
		
		if (is_string($input)) {
			// We refuse to process strings which are suspiciously large to hold a usable floating point value, in order
			// to avoid blowing up the application in places where such large (if otherwise valid) number values are not
			// expected, such as databases.
			//
			// TODO: Revise this decision. Ideally we'd normalize the exponent and be able to trim precision for a float
			// to a user supplied limit (for ex. for single/double/quad IEEE floats).
			if (strlen($input) > 128) {
				goto error;
			} else {
				$input = $this->normalize($input);
			}
		}
		
		success:
		
		if ($this->functions) {
			return ITU::fastApplyFunctions($this->functions, $input, $output, $mask, $events, $path);
		} else {
			$output = $input;
			return true;
		}
		
		error:
		
		if ($input instanceof ToValue) return $this->fastApply($input->toValue(), $output, $mask, $events, $path);
		
		if ($mask & SL::T_ERROR) ITU::addErrorTo($events, $path, 'Please provide a number.');
		$output = null;
		return null;
	}
}