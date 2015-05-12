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
namespace Solver\Sparta;

use Solver\Radar\Radar;

/**
 * A simple host for rendering templates. The reason there are separate AbstractTemplate & Template classes is to hide
 * the private members from the templates, in order to avoid a mess (only protected/public methods will be accessible).
 */
abstract class AbstractTemplate {
	/**
	 * Allows bypassing the default escape mechanism (for doing this in templates, see out()).
	 * 
	 * @var bool
	 */
	private $autoescapeBypass = false;
	
	/**
	 * Sets the autoescape format for templates (for use in templates, see setAutoEsc()).
	 * @var string
	 */
	private $autoescapeFormat = 'html';
	
	/**
	 * Implements autoescaping, should be passed to ob_start($here, 1);
	 * 
	 * @var \Closure
	 */
	private $autoescapeHandler;
	
	/**
	 * A dict container with custom data as passed by the page controller.
	 * 
	 * @var PageModel
	 */
	protected $model;
	
	/**
	 * A log of success/info/warning/error events as passed by the controller.
	 * 
	 * @var PageLog
	 */
	protected $log;
		
	/**
	 * Execution context for the template.
	 * 
	 * @var \Closure
	 */
	private $scope;
	
	/**
	 * @var string
	 */
	private $templateId;
	
	/**
	 * See method tag().
	 * 
	 * @var array
	 */
	private $tagFuncStack;
	
	/**
	 * See method tag().
	 * 
	 * @var array
	 */
	private $tagParamStack;
	
	/**
	 * See method tag().
	 * 
	 * @var array
	 */
	private $tagFuncs;
	
	/**
	 * FIXME: If we run multiple views that use the same imports, we'll be pointlessly reloading the same file. This
	 * can be fixed if this list below is static (but this should be fixed together with tags becoming scope-specific,
	 * and also static, so it all works out).
	 * 
	 * Note: when implementing scopes, don't forget a tag should see the tags in its scope of definition. We should
	 * add this to tag define calls somehow.
	 * 
	 * @var array
	 */
	private $renderedTemplateIds = [];
	
	/**
	 * See constructor.
	 *
	 * @var \Closure
	 */
	private $resolver;
	
	/**
	 * @param string $templateId
	 * A template identifier.
	 * 
	 * A template is not a class, but for consistency, it's addressed as if it was a class in a PSR-0 compatible 
	 * directory structure. So template identifiers use backslash for namespace separators just like PHP classes do.
	 * 
	 * If your template id is "Foo\Bar" this will resolve to loading file DOC_ROOT . "/app/templates/Foo/Bar.php".
	 * 
	 * Also, just like classes, you can use directory names wrapped in square brackets purely to group files together
	 * without affecting the template id (see class autoloading).
	 * 
	 * @param null|\Closure $resolver
	 * null | ($templateId: string) => null | string; Takes template id, returns filepath to it (or null if not found).
	 *
	 * This parameter is optional. If not passed, the template id will be resolved via a call to Radar::find().
	 */
	public function __construct($templateId, \Closure $resolver = null) {
		$this->templateId = $templateId;
		$this->resolver = $resolver;
	}
	
	/**
	 * Includes and renders a template. The template has access to the public and protected members of this class, both
	 * by using $this, and by using a local alias that's injected by the system. For example, $this->tag() and $tag()
	 * are equivalent within a template.
	 * 
	 * @param PageModel $model
	 * @param PageLog $log
	 * @return mixed
	 * Anything returned from the template file.
	 */
	public function __invoke(PageModel $model, PageLog $log) {
		/*
		 * Setup calling scope for this template (and embeded rendered/imported templates).
		 */
		
		$this->model = $model;
		$this->log = $log;
		
		$__localVars__ = $this->getLocalVars();
		
		/* @var $scope \Closure */
		$scope = function ($__path__) use ($__localVars__) {
			extract($__localVars__, EXTR_REFS);
			return require $__path__;
			
		};
				
		// Hide private properties from the scope (this class is abstract and subclassed by Template, so Template is
		// the topmost class possible to instantiate). If you extend Template and override the methods, don't forget
		// to rebind the scope to the your class.
		$scope = $scope->bindTo($this, get_class($this));
		$this->scope = $scope;
		
		$format = & $this->autoescapeFormat;
		$bypass = & $this->autoescapeBypass;
		
		$this->autoescapeHandler = function ($buffer) use (& $format, & $bypass) {
			if ($bypass) {
				return $buffer;
			} else {
				if ($format === 'html') return \htmlspecialchars($buffer, \ENT_QUOTES, 'UTF-8');
				if ($format === 'js') return \json_encode($value, \JSON_UNESCAPED_UNICODE);
			}
		};
		
		ob_start($this->autoescapeHandler, 1);
		$result = $this->render($this->templateId);
		ob_end_flush();
		
		return $result;
	}	
	
	/**
	 * Renders another template inline within this template.
	 * 
	 * @param string $templateId
	 * Id of the template to render. Same rules as a $templateId passed to the constructor (see the constructor for
	 * details).
	 */
	protected function render($templateId) {
		// A leading backslash is accepted as some people will write one, but template ids are always absolute anyway.
		$templateId = \ltrim($templateId, '\\');
		
		if ($this->resolver) {
			$path = $this->resolver->__invoke($templateId);
		} else {
			$path = Radar::find($templateId);
		}
		
		if ($path === null) {
			throw new \Exception('Template "' . $templateId . '" not found.');
		}
		
		$scope = $this->scope;
		$result = $scope($path);
		
		// Used if the same id is import()-ed a second time.
		$this->renderedTemplateIds[$templateId] = $result;

		return $result;
	}
	
	/**
	 * Same as render(), with these differences:
	 * 
	 * - If this templateId was already imported (or rendered) before, it won't be imported again (think require_once).
	 * - Any text output generated while loading the file (via echo or otherwise) will be ignored.
	 * 
	 * The latter is handy for importing templates that contain only tag definitions (functions) for re-use. Whitespace 
	 * outside your functions will not be ignored, and any text along with it (say, HTML comments). You can freely
	 * annotate your code in whatever format you prefer, for ex.:
	 * 
	 * <code>
	 * This text won't be sent to the browser.
	 * 
	 * <? tag('foo', function () { ?>
	 * 		This text, will be rendered if you call tag('foo/') *after* the import.
	 * <? }) ?>
	 * </code>
	 * 
	 * @param string $templateId
	 * A template identifier (for details on template identifiers, see render()).
	 * 
	 * @return mixed
	 * Anything returned from the template file (if the template was rendered/imported before, you'll get the return
	 * value from that first render/import call).
	 */
	protected function import($templateId) {
		if (isset($this->renderedTemplateIds[$templateId])) return;
		
		\ob_start();
		$result = $this->render($templateId);
		\ob_end_clean();
		return $result;
	}
	
	/**
	 * Sets the auto-escape format for templates (by default HTML).
	 * 
	 * @param null|string $format
	 * Autoescape format or null not to autoescape.
	 * TODO: Null is subtle for disabling. Consider autoescape_on($format) autoescape_off() ?
	 */
	protected function autoescape($format) {
		if ($format === null) $format = 'none';
		$this->autoescapeFormat = $format;
	}
	
	// TODO: DOCUMENT.
	protected function encodeHtml($value) {
		return $this->__esc__($value, 'html');
	}
	
	// TODO: DOCUMENT.
	protected function encodeJs($value) {
		return $this->__esc__($value, 'js');
	}
	
	// TODO: DOCUMENT.
	protected function echoRaw($value) {
		$this->__out__($value, 'none');
	}
	
	// TODO: DOCUMENT.
	protected function echoHtml($value) {
		$this->__out__($value, 'html');
	}
	
	// TODO: DOCUMENT.
	protected function echoJs($value) {
		$this->__out__($value, 'js');
	}

	/** 
	 * "Tag" is a light system for registering functions as reusable blocks of content, and then calling them in a 
	 * format well suited for templates. There are few benefits over plain function calls:
	 * 
	 * - You set parameters by name (easy to extend & add parameters for big templates, as parameter order doesn't
	 * matter).
	 * - You can set parameters from content, i.e. "content parameters" using the "@" syntax (see below), and autoescape
	 * will function properly while capturing parameter content (if you try to use ob_* yourself, autoescape won't
	 * work correctly).
	 * - The system is designed to look like HTML tags (as much as possible), hence the name, in order to be intuitive
	 * to front-end developers.
	 * 
	 * In the examples below, the shortcut "tag()" is used (generated for templates by the render/import methods), which
	 * for a template is the same as calling "$this->tag()".
	 *
	 * An example of defining a template:
	 * <code>
	 * <? tag('layout', function ($title = '', $head = '', $body = '') { ?>
	 *		<html>
	 *			<head>
	 *				<title><?= $title ?></title>
	 *				<? echo_raw($head) ?>
	 *			</head>
	 *			<body>
	 *				<? echo_raw($body) ?>
	 *			</body>
	 *		</html>
	 * <? }) // layout ?>
	 * </code>
	 *
	 * An example usage of the above template. You can specify a parameter inline (title), separately(bodyClass) or from
	 * content (head, body):
	 * <code>
	 * <? tag('layout', ['title' => 'Hi, world']) ?>
	 *		<? tag('@bodyClass/', 'css-class-name') ?>
	 *
	 *		<? tag('@head') ?>
	 *			<style>
	 *				body {color: red}
	 *			</style>
	 *		<? tag('/@head') ?>
	 *	
	 *		<? tag('@body') ?>
	 *			<p>Hi, world!</p>
	 *		<? tag('/@body') ?>
	 *	
	 * <? tag('/layout') ?>
	 * </code>
	 *
	 * A shorter way to invoke a template with one content parameter:
	 * <code>
	 * <? tag('layout@body') ?>
	 * 		<p>Hi, world!</p>
	 * <? tag('/layout@body') ?>
	 * </code> 
	 * 
	 * A shorter way to invoke a template without any content parameters (so you can skip the closing tag):
	 * <code>
	 * <? tag('layout/') ?>
	 * <code>
	 * 
	 * At the moment you're closing a "tag" you're calling the function. So that's when you can grb the return result,
	 * if any (having a return result isn't typical for a template function and looks a bit odd; use sparingly):
	 * <code>
	 * <? tag('layout', [...]) ?>
	 *	
	 *		<? tag('@head') ?>
	 *			...
	 *		<? tag('/@head') ?>
	 *	
	 *		<? tag('@body') ?>
	 *			...
	 *		<? tag('/@body') ?>
	 *	
	 * <? $result = tag('/layout') ?>
	 * </code>
	 * 
	 * It works with the short syntaxes, as well: 
	 * <code>
	 * <? $result = tag('layout/', [...]) ?>
	 * </code>
	 * 
	 */ 
	protected function tag($name, $params = null) {
		// TODO: Detect a tag left unclosed at the end of the document (currently silently does nothing).
		// TODO: Refactor this function into smaller specialized ones; allow TemplateCompiler to call the specialized ones when a pattern is detected (performance optimization).
		// TODO: Possible specialized methods to add? func aliases:
	 	// tag_define() tag() tag_begin() tag_end() attr() attr_begin() attr_end().
		
		$tagParamCount = \func_num_args();
		// TODO: Make this scoped (like say Java/C# imports) to the file calling $import(), allow up-scope imports if explicitly specified (i.e. "get the imports this import is including").
		$funcStack = & $this->tagFuncStack;
		$paramStack = & $this->tagParamStack;
		$funcs = & $this->tagFuncs;
		$result = null;
				
		// Register a new template function.
		if ($params instanceof \Closure) {
			if (isset($funcs[$name])) throw new \Exception('Template function named "' . $name . '" was already defined.');
			$funcs[$name] = $params;
			return;
		}
		
		// Self-closing tag <foo/>.
		if ($name[\strlen($name) - 1] === '/') {
			$selfClose = true;
			$name = \substr($name, 0, -1);
		} else {
			$selfClose = false;
		}
		
		// Closing tag </foo> vs. opening tag <foo>.
		if ($name[0] === '/') {
			$open = false; 
			$name = \substr($name, 1);
		} else {
			$open = true;
		}
		
		// Function parameter tag <@foo> vs. function tag <foo>.
		if ($name[0] === '@') {
			$param = true; 
			$name = \substr($name, 1);
		} else {
			$param = false;
		}
		
		// Shortcut <function@param> detection.
		if (\strpos($name, '@') !== false) {
			$name = \explode('@', $name);
			$shortcutParam = $name[1];
			$name = $name[0];
		} else {
			$shortcutParam = null;
		}
		
		if ($shortcutParam && !$open) {
			$this->tag('/@' . $shortcutParam);
		}
		
		if ($param) {
			if ($open) {
				if ($tagParamCount == 2) {
					if (!$selfClose) {
						throw new \Exception('When specifying a parameter value as a second parameter of $tag(), the parameter tag should be self-closing.');
					} else {
						$funcStack[\count($funcStack) - 1][1][$name] = $params;
					}
				} else {
					// Param open.
					$paramStack[] = $name;
					\ob_start(); // For buffering.
					\ob_start($this->autoescapeHandler, 1); // For autoescaping.
				}
			} else { 
				// Param close.
				$name2 = \array_pop($paramStack);
				
				if ($name !== null && $name2 !== $name) {
					throw new \Exception('Parameter end mismatch: closing "' . $name . '", expecting to close "' . $name2 . '".');
				}
				
				\ob_end_flush(); // Closing autoescape handler.
				$funcStack[\count($funcStack) - 1][1][$name] = \ob_get_clean();
			}
		} else {
			if ($open) { 
				// Function call open.
				if (!isset($funcs[$name])) throw new \Exception('Undefined template function "' . $name . '".');
				$funcStack[] = [$name, $params === null ? [] : $params];
			} else { 
				// Function call close.
				$func = \array_pop($funcStack);
		
				if ($name !== null && $func[0] !== $name) {
					throw new \Exception('Template function end mismatch: closing "' . $name . '", expecting to close "' . $func[0] . '".');
				}
				
				$funcName = $func[0];
				$funcImpl = $funcs[$funcName];
				$funcParamDict = $func[1];
				$params = $this->tagGetFunctionParams($funcName, $funcImpl, $funcParamDict);
				$result = $funcImpl(...$params);
			}
		}
		
		if ($shortcutParam && $open) {
			$this->tag('@' . $shortcutParam);
		}
		
		if ($selfClose && !$param) {
			$result = $this->tag('/' . $name);
		}
		
		return $result;
	}
	
	private function tagGetFunctionParams($funcName, $funcImpl, $funcParamDict) {
		$reflFunc = new \ReflectionFunction($funcImpl);
		$params = [];
		
		/* @var $reflParam \ReflectionParameter */
		foreach ($reflFunc->getParameters() as $reflParam) {
			$paramName = $reflParam->getName();
			
			if (\key_exists($paramName, $funcParamDict)) {
				$params[] = $funcParamDict[$paramName];
			} else {
				if ($reflParam->isOptional()) {
					$params[] = $reflParam->getDefaultValue();
				} else {
					throw new \Exception('Required parameter "' . $paramName . '" for template function "' . $funcName . '" is missing.');
				}
			}
		}
		
		return $params;
	}
	
	/**
	 * Return a list of local variables to extracted into the scope of the template that'll run (optionally by
	 * reference). Override in child classes to change these variables.
	 */
	protected function getLocalVars() {
		return [
			'model' => & $this->model,
			'log' => & $this->log,
		];
	}
		
	/**
	 * TODO: REMOVE.
	 * 
	 * Escapes strings for HTML (and other targets). The assumed document charset is UTF-8.
	 * 
	 * For HTML, this method will gracefully return an empty string if you pass null (which happens when fetching a
	 * non-existing key from $model).
	 * 
	 * @param mixed $value
	 * A value to output (typically a string, but some formats, like "js" support also arrays and objects).
	 * 
	 * @param string $format
	 * Optional (default = 'html'). Escape formatting, supported values: 'html', 'js', 'none'. None returns the value
	 * unmodified, and is only included to make your code more readable when you apply escaping (or not) conditionaly.
	 */
	protected function __esc__($value, $format = 'html') {
		switch ($format) {
			case 'html':
				if ($value === null) return '';
				return \htmlspecialchars($value, \ENT_QUOTES, 'UTF-8');
				break;
			
			case 'js':
				return \json_encode($value, \JSON_UNESCAPED_UNICODE);
				break;
			
			case 'none':
				return $value;
				break;
				
			default:
				throw new \Exception('Unknown escape format "' . $format . '".');
		}
	}
	
	/**
	 * TODO: REMOVE.
	 * 
	 * Use this function to send content to the output with a specific format encoding, bypassing the auto-escape
	 * mechanism.
	 * 
	 * @param mixed $value
	 * A value to output (typically a string, but some formats, like "js" support also arrays and objects).
	 * 
	 * @param string $format
	 * Same formats as exposed by method esc().
	 */
	protected function __out__($value, $format = 'html') {
		if ($format !== 'none') $value = $this->__esc__($value, $format);
		$this->autoescapeBypass = true;
		echo $value;
		$this->autoescapeBypass = false;
	}
}