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
namespace Solver\Lab;

/**
 * A variant is an implementation of a "tagged union" type, for dictionaries where a specific field is designated to 
 * differentiate between the subtypes in a union.
 * 
 * Variants are able to directly select the correct subformat by matching the provided values for the tag field. This
 * results in better performance and improved error reporting (the errors from the correct subtype get reported if the
 * input invalidates) compared to untagged unions, but it has a limitation: it requires subformats to return a
 * collection (PHP array) so the tag field can be addressed by name (dict), or index (list, tuple). UnionFormat
 * implements an untagged union and has no limitation on the value format.
 * 
 * TODO: Allow one subformat with no tagged value (i.e. the lack of a tag selects that subformat), thus making the tag
 * field optional (it's by default required).
 */
class VariantFormat extends AbstractFormat implements Format {	
	protected $tagFieldName = null;
	protected $formats = [];
	protected $error = null;
	
	/**
	 * @param string|int $tagFieldName
	 * The field name/index that will be used as the "type tag": its value will be used to identify which of the 
	 * possible variant formats should be selected for the input data.
	 */
	public function tag($tagFieldName) {
		// For now we enforce this to ensure a consistent method call order, but in the future we may drop this requirement.
		if ($this->formats) throw new \Exception('You must call method tag() before adding formats via add().');
		
		$this->tagFieldName = $tagFieldName;
	}
	
	/**
	 * @param string|int $tagValue
	 * Set a unique string/number value that identifies this format. The tag MUST be a scalar value. Tag values are 
	 * compared like strings (basically), so integer 0 and string "0" represent the same tag value.
	 *  
	 * @param Format $format
	 * @throws \Exception
	 * @return \Solver\Lab\UnionFormat
	 */
	public function add($tagValue, Format $format) {
		if (!is_scalar($tagValue)) throw new \Exception('Tag values must be scalar (integer, int, or a float with an integer value).');
		if (isset($this->formats[$tagValue])) throw new \Exception('Tag value "' . $tagValue . '" has already been specified for another format in this variant.');
		if ($this->rules) throw new \Exception('You should call method add() before any test*() or filter*() calls.');
		
		$this->formats[$tagValue] = $format;
		
		return $this;
	}
	
	public function extract($value, ErrorLog $log, $path = null) {
		$tagField = $this->tagFieldName;
		
		if ($tagField === null) {
			throw new \Exception('You must set the tag field name via tag() before you extract.');
		}
		
		// Variants must be containers (dict, list, tuple).
		if (!is_array($value)) {
			$log->addError($path, 'Please fill in a valid value.'); // TODO: More specific error?
			return null;
		}
		
		// The tag field must be present.
		if (!key_exists($tagField, $value)) {
			if ((string) $tagField === (string) (int) $tagField) {
				$msg = 'Please provide required index "' . $tagField . '".';
			} else {
				$msg = 'Please provide required field "' . $tagField . '".';
			}
			$log->addError($path, $msg);
			return null;
		}
		
		// The value must match our registered type tags.
		if (!isset($this->formats[$value[$tagField]])) {
			$log->addError($path . '.' . $this->tagFieldName, 'Please fill in a valid value.'); // TODO: More specific error?
			return null;
		}
		
		$value = $this->formats[$value[$tagField]]->extract($value, $log, $path);
		
		if ($log->hasErrors()) {
			return null;
		} else {
			return parent::extract($value, $log, $path);
		}
	}
}