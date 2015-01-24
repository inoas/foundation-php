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
 * The purpose of this class is to allow $code to be a string (the native \Exception class only allows integers).
 * 
 * It also adds the ability to pass an arbitrary array for machine readable exception details, as necessary.
 */
class Exception extends \Exception {
	protected $details;
	
	public function __construct($message = '', $code = 0, $previous = null, $details = null) {
		parent::__construct($message, (int) $code, $previous);
		$this->code = $code;
		$this->details = $details;
	}
	
	public function getDetails() {
		return $this->details;
	}
}