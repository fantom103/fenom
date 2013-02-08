<?php
use Aspect\Template,
	Aspect\ProviderInterface;

/**
 * Aspect Template Engine
 */
class Aspect {
    const VERSION = 1.0;

    const INLINE_COMPILER = 1;
    const BLOCK_COMPILER = 2;
    const INLINE_FUNCTION = 3;
    const BLOCK_FUNCTION = 4;
    const MODIFIER = 5;

    const DENY_METHODS = 0x10;
    const DENY_INLINE_FUNCS = 0x20;
    const FORCE_INCLUDE = 0x40;

    const CHECK_MTIME = 0x80;
    const FORCE_COMPILE = 0xF0;

    const DEFAULT_CLOSE_COMPILER = 'Aspect\Compiler::stdClose';
    const DEFAULT_FUNC_PARSER = 'Aspect\Compiler::stdFuncParser';
    const DEFAULT_FUNC_OPEN = 'Aspect\Compiler::stdFuncOpen';
    const DEFAULT_FUNC_CLOSE = 'Aspect\Compiler::stdFuncOpen';
    const SMART_FUNC_PARSER = 'Aspect\Compiler::smartFuncParser';

    /**
     * @var array of possible options, as associative array
     * @see setOptions, addOptions, delOptions
     */
    private static $_option_list = array(
        "disable_methods" => self::DENY_METHODS,
        "disable_native_funcs" => self::DENY_INLINE_FUNCS,
        "force_compile" => self::FORCE_COMPILE,
        "compile_check" => self::CHECK_MTIME,
        "force_include" => self::FORCE_INCLUDE,
    );

    /**
     * Default options for functions
     * @var array
     */
    private static $_actions_defaults = array(
        self::BLOCK_FUNCTION => array(
            'type' => self::BLOCK_FUNCTION,
            'open' => self::DEFAULT_FUNC_OPEN,
            'close' => self::DEFAULT_FUNC_CLOSE,
            'function' => null,
        ),
        self::INLINE_FUNCTION => array(
            'type' => self::INLINE_FUNCTION,
            'parser' => self::DEFAULT_FUNC_PARSER,
            'function' => null,
        ),
        self::INLINE_COMPILER => array(
            'type' => self::INLINE_COMPILER,
            'open' => null,
            'close' => self::DEFAULT_CLOSE_COMPILER,
            'tags' => array(),
            'float_tags' => array()
        ),
        self::BLOCK_COMPILER => array(
            'type' => self::BLOCK_COMPILER,
            'open' => null,
            'close' => null,
            'tags' => array(),
            'float_tags' => array()
        )
    );

    /**
     * @var array Templates storage
     */
    protected $_storage = array();
    /**
     * @var array template directory
     */
    protected $_tpl_path = array();
    /**
     * @var string compile directory
     */
    protected $_compile_dir = "/tmp";

    /**
     * @var int masked options
     */
    protected $_options = 0;

    protected $_on_pre_cmp = array();
    protected $_on_cmp = array();
    protected $_on_post_cmp = array();

	/**
	 * @var Aspect\Provider
	 */
	private $_provider;
	/**
	 * @var array of Aspect\ProviderInterface
	 */
	protected $_providers = array();

    /**
     * @var array of modifiers [modifier_name => callable]
     */
    protected $_modifiers = array(
        "upper" => 'strtoupper',
        "lower" => 'strtolower',
        "date_format" => 'Aspect\Modifier::dateFormat',
        "date" => 'Aspect\Modifier::date',
        "truncate" => 'Aspect\Modifier::truncate',
        "escape" => 'Aspect\Modifier::escape',
        "e" => 'Aspect\Modifier::escape', // alias of escape
        "url" => 'urlencode',   // alias of escape:"url"
        "unescape" => 'Aspect\Modifier::unescape',
        "strip" => 'Aspect\Modifier::strip',
        "default" => 'Aspect\Modifier::defaultValue'
    );

    /**
     * @var array of allowed PHP functions
     */
    protected $_allowed_funcs = array(
        "empty" => 1, "isset" => 1, "count" => 1, "is_string" => 1, "is_array" => 1, "is_numeric" => 1, "is_int" => 1,
        "is_object" => 1, "strtotime" => 1, "gettype" => 1, "is_double" => 1, "json_encode" => 1, "json_decode" => 1,
	    "ip2long" => 1, "long2ip" => 1, "strip_tags" => 1, "nl2br" => 1
    );

    /**
     * @var array of compilers and functions
     */
    protected $_actions = array(
        'foreach' => array( // {foreach ...} {break} {continue} {foreachelse} {/foreach}
            'type' => self::BLOCK_COMPILER,
            'open' => 'Aspect\Compiler::foreachOpen',
            'close' => 'Aspect\Compiler::foreachClose',
            'tags' => array(
                'foreachelse' => 'Aspect\Compiler::foreachElse',
                'break' => 'Aspect\Compiler::tagBreak',
                'continue' => 'Aspect\Compiler::tagContinue',
            ),
            'float_tags' => array('break' => 1, 'continue' => 1)
        ),
        'if' => array(      // {if ...} {elseif ...} {else} {/if}
            'type' => self::BLOCK_COMPILER,
            'open' => 'Aspect\Compiler::ifOpen',
            'close' => 'Aspect\Compiler::stdClose',
            'tags' => array(
                'elseif' => 'Aspect\Compiler::tagElseIf',
                'else' => 'Aspect\Compiler::tagElse',
            )
        ),
        'switch' => array(  // {switch ...} {case ...} {break} {default} {/switch}
            'type' => self::BLOCK_COMPILER,
            'open' => 'Aspect\Compiler::switchOpen',
            'close' => 'Aspect\Compiler::stdClose',
            'tags' => array(
                'case' => 'Aspect\Compiler::tagCase',
                'default' => 'Aspect\Compiler::tagDefault',
                'break' => 'Aspect\Compiler::tagBreak',
            ),
            'float_tags' => array('break' => 1)
        ),
        'for' => array(     // {for ...} {break} {continue} {/for}
            'type' => self::BLOCK_COMPILER,
            'open' => 'Aspect\Compiler::forOpen',
            'close' => 'Aspect\Compiler::forClose',
            'tags' => array(
                'forelse' => 'Aspect\Compiler::forElse',
                'break' => 'Aspect\Compiler::tagBreak',
                'continue' => 'Aspect\Compiler::tagContinue',
            ),
            'float_tags' => array('break' => 1, 'continue' => 1)
        ),
        'while' => array(   // {while ...} {break} {continue} {/while}
            'type' => self::BLOCK_COMPILER,
            'open' => 'Aspect\Compiler::whileOpen',
            'close' => 'Aspect\Compiler::stdClose',
            'tags' => array(
                'break' => 'Aspect\Compiler::tagBreak',
                'continue' => 'Aspect\Compiler::tagContinue',
            ),
            'float_tags' => array('break' => 1, 'continue' => 1)
        ),
        'include' => array( // {include ...}
            'type' => self::INLINE_COMPILER,
            'parser' => 'Aspect\Compiler::tagInclude'
        ),
        'var' => array(     // {var ...}
            'type' => self::INLINE_COMPILER,
            'parser' => 'Aspect\Compiler::assign'
        ),
        'block' => array(   // {block ...} {/block}
            'type' => self::BLOCK_COMPILER,
            'open' => 'Aspect\Compiler::tagBlockOpen',
            'close' => 'Aspect\Compiler::tagBlockClose',
        ),
        'extends' => array( // {extends ...}
            'type' => self::INLINE_COMPILER,
            'parser' => 'Aspect\Compiler::tagExtends'
        ),
        'capture' => array( // {capture ...} {/capture}
            'type' => self::BLOCK_FUNCTION,
            'open' => 'Aspect\Compiler::stdFuncOpen',
            'close' => 'Aspect\Compiler::stdFuncClose',
            'function' => 'Aspect\Func::capture',
        ),
        'mailto' => array(
            'type' => self::INLINE_FUNCTION,
            'parser' => 'Aspect\Compiler::stdFuncParser',
            'function' => 'Aspect\Func::mailto',
        )

    );

	/**
	 * Factory
	 * @param string $template_dir path to templates
	 * @param string $compile_dir path to compiled files
	 * @param int $options
	 * @param \Aspect\Provider $provider
	 * @return Aspect
	 */
    public static function factory($template_dir, $compile_dir, $options = 0, Aspect\Provider $provider = null) {
        $aspect = new static($provider);
        $aspect->setCompileDir($compile_dir);
        $aspect->setTemplateDirs($template_dir);
        if($options) {
            $aspect->setOptions($options);
        }
        return $aspect;
    }

	/**
	 * @param Aspect\Provider $provider
	 */
	public function __construct(Aspect\Provider $provider = null) {
		$this->_provider = $provider ?: new Aspect\Provider();
	}

    /**
     * Set checks template for modifications
     * @param $state
     * @return Aspect
     */
    public function setCompileCheck($state) {
        $state && ($this->_options |= self::CHECK_MTIME);
        return $this;
    }

    /**
     * Set force template compiling
     * @param $state
     * @return Aspect
     */
    public function setForceCompile($state) {
        $state && ($this->_options |= self::FORCE_COMPILE);
        $this->_storage = $state ? new Aspect\BlackHole() : array();
        return $this;
    }

    /**
     * Set compile directory
     * @param string $dir directory to store compiled templates in
     * @return Aspect
     */
    public function setCompileDir($dir) {
        $this->_compile_dir = $dir;
        return $this;
    }

    /**
     * Set template directory
     * @param string|array $dirs directory(s) of template sources
     * @return Aspect
     */
    public function setTemplateDirs($dirs) {
	    $this->_provider->setTemplateDirs($dirs);
        return $this;
    }

    /**
     *
     * @param callable $cb
     */
    public function addPreCompileFilter($cb) {
        $this->_on_pre_cmp[] = $cb;
    }

    /**
     *
     * @param callable $cb
     */
    public function addPostCompileFilter($cb) {
        $this->_on_post_cmp[] = $cb;
    }

    /**
     * @param callable $cb
     */
    public function addCompileFilter($cb) {
        $this->_on_cmp[] = $cb;
    }

    /**
     * Add modifier
     *
     * @param string $modifier
     * @param string $callback
     * @return Aspect
     */
    public function addModifier($modifier, $callback) {
        $this->_modifiers[$modifier] = $callback;
        return $this;
    }

    /**
     * @param string $compiler
     * @param string $parser
     * @return Aspect
     */
    public function addCompiler($compiler, $parser) {
        $this->_actions[$compiler] = array(
            'type' => self::INLINE_COMPILER,
            'parser' => $parser
        );
        return $this;
    }

    /**
     * @param string $compiler
     * @param string $open_parser
     * @param string $close_parser
     * @param array $tags
     * @return Aspect
     */
    public function addBlockCompiler($compiler, $open_parser, $close_parser = self::DEFAULT_CLOSE_COMPILER, array $tags = array()) {
        $this->_actions[$compiler] = array(
            'type' => self::BLOCK_COMPILER,
            'open' => $open_parser,
            'close' => $close_parser ?: self::DEFAULT_CLOSE_COMPILER,
            'tags' => $tags,
        );
        return $this;
    }

    /**
     * @param string $function
     * @param callable $callback
     * @param string $parser
     * @return Aspect
     */
    public function addFunction($function, $callback, $parser = self::DEFAULT_FUNC_PARSER) {
        $this->_actions[$function] = array(
            'type' => self::INLINE_FUNCTION,
            'parser' => $parser ?: self::DEFAULT_FUNC_PARSER,
            'function' => $callback,
        );
        return $this;
    }

    /**
     * @param string $function
     * @param callable $callback
     * @return Aspect
     */
    public function addFunctionSmart($function, $callback) {
        $this->_actions[$function] = array(
            'type' => self::INLINE_FUNCTION,
            'parser' => self::SMART_FUNC_PARSER,
            'function' => $callback,
        );
        return $this;
    }

    /**
     * @param $function
     * @param $callback
     * @param null $parser_open
     * @param null $parser_close
     * @return Aspect
     */
    public function addBlockFunction($function, $callback, $parser_open = null, $parser_close = null) {
        $this->_actions[$function] = array(
            'type' => self::BLOCK_FUNCTION,
            'open' => $parser_open ?: 'Aspect\Compiler::stdFuncOpen',
            'close' => $parser_close ?: 'Aspect\Compiler::stdFuncClose',
            'function' => $callback,
        );
        return $this;
    }

    /**
     * @param array $funcs
     * @return Aspect
     */
    public function addAllowedFunctions(array $funcs) {
        $this->_allowed_funcs = $this->_allowed_funcs + array_flip($funcs);
        return $this;
    }

    /**
     * @param $modifier
     * @return mixed
     * @throws \Exception
     */
    public function getModifier($modifier) {
        if(isset($this->_modifiers[$modifier])) {
            return $this->_modifiers[$modifier];
        } elseif($this->isAllowedFunction($modifier)) {
            return $modifier;
        } else {
            throw new \Exception("Modifier $modifier not found");
        }
    }

    /**
     * @param string $function
     * @return string|bool
     */
    public function getFunction($function) {
        if(isset($this->_actions[$function])) {
            return $this->_actions[$function];
        } else {
            return false;
        }
    }

    public function isAllowedFunction($function) {
        if($this->_options & self::DENY_INLINE_FUNCS) {
            return isset($this->_allowed_funcs[$function]);
        } else {
            return is_callable($function);
        }
    }

    public function getTagOwners($tag) {
        $tags = array();
        foreach($this->_actions as $owner => $params) {
            if(isset($params["tags"][$tag])) {
                $tags[] = $owner;
            }
        }
        return $tags;
    }

    /**
     * Add template directory
     * @static
     * @param string $dir
     * @return \Aspect
     * @throws \InvalidArgumentException
     */
    public function addTemplateDir($dir) {
	    $this->_provider->addTemplateDir($dir);
	    return $this;
    }

    public function addProvider($scm, \Aspect\Provider $provider) {
        $this->_providers[$scm] = $provider;
    }

    /**
     * Set options. May be bitwise mask of constants DENY_METHODS, DENY_INLINE_FUNCS, DENY_SET_VARS, INCLUDE_SOURCES,
     * FORCE_COMPILE, CHECK_MTIME, or associative array with boolean values:
     * disable_methods - disable all calls method in template
     * disable_native_funcs - disable all native PHP functions in template
     * force_compile - recompile template every time (very slow!)
     * compile_check - check template modifications (slow!)
     * @param int|array $options
     */
    public function setOptions($options) {
        if(is_array($options)) {
            $options = Aspect\Misc::makeMask($options, self::$_option_list);
        }
        $this->_storage = ($options & self::FORCE_COMPILE) ? new Aspect\BlackHole() : array();
        $this->_options = $options;
    }

    /**
     * Get options as bits
     * @return int
     */
    public function getOptions() {
        return $this->_options;
    }

	/**
	 * @param bool|string $scm
	 * @return Aspect\Provider
	 * @throws InvalidArgumentException
	 */
	public function getProvider($scm = false) {
		if($scm) {
			if(isset($this->_provider[$scm])) {
				return $this->_provider[$scm];
			} else {
				throw new InvalidArgumentException("Provider for '$scm' not found");
			}
		} else {
			return $this->_provider;
		}
	}

    /**
     * Execute template and write result into stdout
     *
     *
     * @param string $template
     * @param array $vars
     * @return Aspect\Render
     */
    public function display($template, array $vars = array()) {
        return $this->getTemplate($template)->display($vars);
    }

    /**
     *
     * @param string $template
     * @param array $vars
     * @internal param int $options
     * @return mixed
     */
    public function fetch($template, array $vars = array()) {
        return $this->getTemplate($template)->fetch($vars);
    }

    /**
     * Return template by name
     *
     * @param string $template
     * @return Aspect\Template
     */
    public function getTemplate($template) {

        if(isset($this->_storage[ $template ])) {
	        /** @var Aspect\Template $tpl  */
	        $tpl = $this->_storage[ $template ];
            if(($this->_options & self::CHECK_MTIME) && !$tpl->isValid()) {
                return $this->_storage[ $template ] = $this->compile($template);
            } else {
                return $this->_storage[ $template ];
            }
        } elseif($this->_options & self::FORCE_COMPILE) {
            return $this->compile($template);
        } else {
            return $this->_storage[ $template ] = $this->_load($template);
        }
    }

    /**
     * Add custom template into storage
     * @param Aspect\Render $template
     */
    public function addTemplate(Aspect\Render $template) {
        $this->_storage[ $template->getName() ] = $template;
        $template->setStorage($this);
    }

    /**
     * Return template from storage or create if template doesn't exists.
     *
     * @param string $tpl
     * @throws \RuntimeException
     * @return Aspect\Template|mixed
     */
    protected function _load($tpl) {
        $file_name = $this->_getHash($tpl);
        if(!is_file($this->_compile_dir."/".$file_name)) {
            return $this->compile($tpl);
        } else {
            /** @var Aspect\Render $tpl */
            $tpl = include($this->_compile_dir."/".$file_name);
            $tpl->setStorage($this);
            return $tpl;
        }
    }

    /**
     * Generate unique name of compiled template
     *
     * @param string $tpl
     * @return string
     */
    private function _getHash($tpl) {
        $hash = $tpl.":".$this->_options;
        return sprintf("%s.%u.%d.php", basename($tpl), crc32($hash), strlen($hash));
    }

	/**
	 * Compile and save template
	 *
	 * @param string $tpl
	 * @param bool $store
	 * @throws RuntimeException
	 * @return \Aspect\Template
	 */
    public function compile($tpl, $store = true) {
	    $provider = $this->getProvider(strstr($tpl, ":", true));
        $template = new Template($this, $provider->loadCode($tpl), $tpl);
	    if($store) {
	        $tpl_tmp = tempnam($this->_compile_dir, basename($tpl));
	        $tpl_fp = fopen($tpl_tmp, "w");
	        if(!$tpl_fp) {
	            throw new \RuntimeException("Can not open temporary file $tpl_tmp. Directory ".$this->_compile_dir." is writable?");
	        }
	        fwrite($tpl_fp, $template->getTemplateCode());
	        fclose($tpl_fp);
		    $file_name = $this->_compile_dir."/".$this->_getHash($tpl);
	        if(!rename($tpl_tmp, $file_name)) {
	            throw new \RuntimeException("Can not to move $tpl_tmp to $tpl");
	        }
	    }
        return $template;
    }

    /**
     * Remove all compiled templates.
     *
     * @param string $scm
     * @return int
     */
    public function compileAll($scm = null) {
        //return FS::rm($this->_compile_dir.'/*');
    }

    /**
     * @param string $tpl
     * @return bool
     */
    public function clearCompiledTemplate($tpl) {
        $file_name = $this->_compile_dir."/".$this->_getHash($tpl);
        if(file_exists($file_name)) {
            return unlink($file_name);
        } else {
            return true;
        }
    }

    /**
     * @return int
     */
    public function clearAllCompiles() {

    }

    /**
     * Compile code to template
     *
     * @param string $code
     * @param string $name
     * @return Aspect\Template
     */
    public function compileCode($code, $name = 'Runtime compile') {
        return new Template($this, $code, $name);
    }

}