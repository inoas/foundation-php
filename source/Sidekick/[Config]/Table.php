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
namespace Solver\Sidekick;

// TODO: Change terminology to "fields" which can be based on columns or expressions.
// Read fields: only can be selected;
// Write fields: only can be written to;
// Conditions or filter fields: only can be used in where() clauses;
// Expression-based fields can have parameters;
// Linked is just another field type (with parameter Selector).
// Expressions get QueryBuilder where they can add to the select etc. query (and say, add JOIN and get back temp table id to use etc.).
class Table {
	protected $hasColumnCodecs = false;
	protected $hasColumnRenames = false;
	protected $name = null;
	protected $internalName = null;
	protected $externalFieldList = [];
	protected $internalFieldList = [];
	protected $externalFields = [];
	protected $internalFields = [];
 	protected $primaryKey = null;
	
	function __construct($name = null, $internalName = null) {
		if ($name !== null) $this->setName($name, $internalName);
	}

	function setName($name, $internalName = null) {
		$this->name = $name;
		$this->internalName = $internalName ?: $name;
		return $this;
	}
	
// 	function setSuper($tableName) { TODO: Inheritance, maybe multiple (like traits); makes joins on PK if a superfield is selected.
// 		
// 	}

	function setPK(...$columns) { // TODO: Document the names here should be the "external" names, to avoid confusion when they differ from the internal ones.
		// TODO: Validate in render() that those PK fields set here exist, and are columns, not expressions (why not expressions? think about it)
		$this->primaryKey = $columns;
		
		return $this;
	}

// 	function addFieldLoader(\Closure $closure) {
// 		TODO: Ability to lazy-load a field upon it being demanded (allows schemas to not get too big up-front).		
// 	}
	
// 	function addDirective($fieldName, $columnName = null, \Directive $directive) {
//		
// 	}
	
	function addExpession($name, $expression, \Closure $decoder = null) {
		// Field type pseudo-constants.
		static $FT_EXPR = 2;
		
		if (is_array($name)) throw new \Exception('Name as array given: expression fields cannot be composite.');
		
		$publicCol = [
			'name' => $name,
			'toName' => $name,
			'composite' => false,
			'transform' => null,
			'expression' => $expression,
			'type' => $FT_EXPR,
		];
		
		$internalCol = [
			'name' => $name,
			'toName' => $name,
			'composite' => false,
			'transform' => $decoder,
			'type' => $FT_EXPR,
		];
		
		$this->externalFields[$name] = $publicCol;
		$this->externalFieldList[] = [false, $name];
		
		$this->internalFields[$internalName] = $internalCol;
		$this->internalFieldList[] = [false, $internalName];
		
		return $this;
	}
	
	function addColumn($name, $internalName = null, \Closure $encoder = null, \Closure $decoder = null) {
		// Field type pseudo-constants.
		static $FT_COL = 1;
		
		if ($internalName === null) $internalName = $name;
		$composite = is_array($name) || is_array($internalName);
		
		if ($composite) {
			$name = (array) $name;
			$internalName = (array) $internalName;
		}
		
		if ($composite && ($encoder === null || $decoder === null)) {
			throw new \Exception('Encoder and decoder must be provided when you specify composite columns.');
		}

		$publicCol = [
			'name' => $name,
			'toName' => $internalName,
			'composite' => $composite,
			'transform' => $encoder,
			'type' => $FT_COL,
		];
		
		$internalCol = [
			'name' => $internalName,
			'toName' => $name,
			'composite' => $composite,
			'transform' => $decoder,
			'type' => $FT_COL,
		];
		
		// TODO: Detect collisions and throw on them.
		if ($composite) {
			foreach ($name as $v) {
				$this->externalFields[$v] = $publicCol;
			}
			$this->externalFieldList[] = [true, $name];
			
			foreach ($internalName as $v) {
				$this->internalFields[$v] = $internalCol;
			}
			$this->internalFieldList[] = [true, $internalName];
		} else {
			$this->externalFields[$name] = $publicCol;
			$this->externalFieldList[] = [false, $name];
			
			$this->internalFields[$internalName] = $internalCol;
			$this->internalFieldList[] = [false, $internalName];
		}
		
		return $this;
	}
		
	function render() {
		if ($this->name === null) throw new \Exception('Table name is a required property.');
		if (!$this->externalFields) throw new \Exception('Tables need at least one column.');
		return get_object_vars($this);
	}
}