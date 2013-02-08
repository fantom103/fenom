<?php
namespace Aspect;

defined('T_INSTEADOF')  || define('T_INSTEADOF', 341);
defined('T_TRAIT')      || define('T_TRAIT', 355);
defined('T_TRAIT_C')    || define('T_TRAIT_C', 365);

/**
 * Each token have structure
 *  - Token (constant T_* or text)
 *  - Token name (textual representation of the token)
 *  - Whitespace (whitespace symbols after token)
 *  - Line number of the token
 *
 * @see http://php.net/tokenizer
 * @property array $prev the previous token
 * @property array $curr the current token
 * @property array $next the next token
 */
class Tokenizer {
    const TOKEN = 0;
    const TEXT = 1;
    const WHITESPACE = 2;
    const LINE = 3;

    /**
     * Some text value: foo, bar, new, class ...
     */
    const MACRO_STRING = 1000;
    /**
     * Unary operation: ~, !, ^
     */
    const MACRO_UNARY = 1001;
    /**
     * Binary operation (operation between two values): +, -, *, /, &&, or , ||, >=, !=, ...
     */
    const MACRO_BINARY = 1002;
    /**
     * Equal operation
     */
    const MACRO_EQUALS = 1003;
    /**
     * Scalar values (such as int, float, escaped strings): 2, 0.5, "foo", 'bar\'s'
     */
    const MACRO_SCALAR = 1004;
    /**
     * Increment or decrement: ++ --
     */
    const MACRO_INCDEC = 1005;
    /**
     * Boolean operations: &&, ||, or, xor
     */
    const MACRO_BOOLEAN = 1006;
    /**
     * Math operation
     */
    const MACRO_MATH = 1007;
    /**
     * Condition operation
     */
    const MACRO_COND = 1008;

    public $tokens;
    public $p = 0;
    private $_max = 0;
    private $_last_no = 0;

    /**
     * @see http://docs.php.net/manual/en/tokens.php
     * @var array groups of tokens
     */
    private static $_macros = array(
        self::MACRO_STRING => array(
            \T_ABSTRACT => 1,    \T_ARRAY => 1,       \T_AS => 1,      \T_BREAK => 1,       \T_BREAK => 1,       \T_CASE => 1,
            \T_CATCH => 1,       \T_CLASS => 1,       \T_CLASS_C => 1, \T_CLONE => 1,       \T_CONST => 1,       \T_CONTINUE => 1,
            \T_DECLARE => 1,     \T_DEFAULT => 1,     \T_DIR => 1,     \T_DO => 1,          \T_ECHO => 1,        \T_ELSE => 1,
            \T_ELSEIF => 1,      \T_EMPTY => 1,       \T_ENDDECLARE => 1, \T_ENDFOR => 1,   \T_ENDFOREACH => 1,  \T_ENDIF => 1,
            \T_ENDSWITCH => 1,   \T_ENDWHILE => 1,    \T_EVAL => 1,    \T_EXIT => 1,        \T_EXTENDS => 1,     \T_FILE => 1,
            \T_FINAL => 1,       \T_FOR => 1,         \T_FOREACH => 1, \T_FUNCTION => 1,    \T_FUNC_C => 1,      \T_GLOBAL => 1,
            \T_GOTO => 1,        \T_HALT_COMPILER => 1, \T_IF => 1,    \T_IMPLEMENTS => 1,  \T_INCLUDE => 1,     \T_INCLUDE_ONCE => 1,
            \T_INSTANCEOF => 1,  \T_INSTEADOF => 1,   \T_INTERFACE => 1, \T_ISSET => 1,     \T_LINE => 1,        \T_LIST => 1,
            \T_LOGICAL_AND => 1, \T_LOGICAL_OR => 1,  \T_LOGICAL_XOR => 1, \T_METHOD_C => 1, \T_NAMESPACE => 1,  \T_NS_C => 1,
            \T_NEW => 1,         \T_PRINT => 1,       \T_PRIVATE => 1, \T_PUBLIC => 1,      \T_PROTECTED => 1,   \T_REQUIRE => 1,
            \T_REQUIRE_ONCE => 1,\T_RETURN => 1,      \T_RETURN => 1,  \T_STRING => 1,      \T_SWITCH => 1,      \T_THROW => 1,
            \T_TRAIT => 1,       \T_TRAIT_C => 1,     \T_TRY => 1,     \T_UNSET => 1,       \T_UNSET => 1,       \T_VAR => 1,
            \T_WHILE => 1
        ),
        self::MACRO_INCDEC => array(
            \T_INC => 1, \T_DEC => 1
        ),
        self::MACRO_UNARY => array(
            "!" => 1, "~" => 1, "-" => 1
        ),
        self::MACRO_BINARY => array(
            \T_BOOLEAN_AND => 1, \T_BOOLEAN_OR => 1,  \T_IS_GREATER_OR_EQUAL => 1,         \T_IS_EQUAL => 1,    \T_IS_IDENTICAL => 1,
            \T_IS_NOT_EQUAL => 1,\T_IS_NOT_IDENTICAL => 1,            \T_IS_SMALLER_OR_EQUAL => 1,             \T_LOGICAL_AND => 1,
            \T_LOGICAL_OR => 1,  \T_LOGICAL_XOR => 1,  \T_SL => 1,     \T_SR => 1,
            "+" => 1, "-" => 1, "*" => 1, "/" => 1, ">" => 1, "<" => 1, "^" => 1, "%" => 1, "&" => 1
        ),
        self::MACRO_BOOLEAN => array(
            \T_LOGICAL_OR => 1,  \T_LOGICAL_XOR => 1, \T_BOOLEAN_AND => 1, \T_BOOLEAN_OR => 1
        ),
        self::MACRO_MATH => array(
            "+" => 1, "-" => 1, "*" => 1, "/" => 1, "^" => 1, "%" => 1, "&" => 1, "|" => 1
        ),
        self::MACRO_COND => array(
            \T_IS_EQUAL => 1,    \T_IS_IDENTICAL => 1, ">" => 1, "<" => 1, \T_SL => 1,     \T_SR => 1,
            \T_IS_NOT_EQUAL => 1,\T_IS_NOT_IDENTICAL => 1,            \T_IS_SMALLER_OR_EQUAL => 1,
        ),
        self::MACRO_EQUALS => array(
            \T_AND_EQUAL => 1,   \T_CONCAT_EQUAL => 1,\T_DIV_EQUAL => 1,                   \T_MINUS_EQUAL => 1, \T_MOD_EQUAL => 1,
            \T_MUL_EQUAL => 1,   \T_OR_EQUAL => 1,    \T_PLUS_EQUAL => 1,                  \T_SL_EQUAL => 1,    \T_SR_EQUAL => 1,
            \T_XOR_EQUAL => 1,   '=' => 1
        ),
        self::MACRO_SCALAR => array(
            \T_LNUMBER => 1,     \T_DNUMBER => 1,     \T_CONSTANT_ENCAPSED_STRING => 1
        )
    );

    /**
     * Special tokens
     * @var array
     */
    private static $spec = array(
        'true' => 1, 'false' => 1, 'null' => 1, 'TRUE' => 1, 'FALSE' => 1, 'NULL' => 1
    );

    /**
     * Translate expression to tokens list.
     *
     * @static
     * @param string $query
     * @return array
     */
    public static function decode($query) {
        $tokens = array(-1 => array(\T_WHITESPACE, '', '', 1));
        $_tokens = token_get_all("<?php ".$query);
        $line = 1;
        array_shift($_tokens);
        $i = 0;
        foreach($_tokens as &$token) {
            if(is_string($token)) {
                $tokens[] = array(
                    $token,
                    $token,
                    "",
                    $line,
                );
                $i++;
            } elseif ($token[0] === \T_WHITESPACE) {
                $tokens[$i-1][2] = $token[1];
            } else {
                $tokens[] = array(
                    $token[0],
                    $token[1],
                    "",
                    $line =  $token[2],
                );
                $i++;
            }

        }

        return $tokens;
    }

    public function __construct($query, $decode = 0) {
        $this->tokens = self::decode($query, $decode);
        unset($this->tokens[-1]);
        $this->_max = count($this->tokens) - 1;
        $this->_last_no = $this->tokens[$this->_max][3];
    }

    /**
     * Set the filter callback. Token may be changed by reference or skipped if callback return false.
     *
     * @param $callback
     */
    public function filter(\Closure $callback) {
        $tokens = array();
        foreach($this->tokens as $token) {
            if($callback($token) !== false) {
                $tokens[] = $token;
            }
        }
        $this->tokens = $tokens;
        $this->_max = count($this->tokens) - 1;
    }

    /**
     * Return the current element
     *
     * @link http://php.net/manual/en/iterator.current.php
     * @return mixed Can return any type.
     */
    public function current() {
        return $this->curr[1];
    }

    /**
     * Move forward to next element
     *
     * @link http://php.net/manual/en/iterator.next.php
     * @return Tokenizer
     */
    public function next() {
        if($this->p > $this->_max) {
            return $this;
        }
        $this->p++;
        unset($this->prev, $this->curr, $this->next);
        return $this;
    }

    /**
     * Check token type. If token type is one of expected types return true. Otherwise return false
     *
     * @param array $expects
     * @param string|int $token
     * @return bool
     */
    private function _valid($expects, $token) {
        foreach($expects as $expect) {
            if(is_string($expect) || $expect < 1000) {
                if($expect === $token) {
                    return true;
                }
            } else {

                if(isset(self::$_macros[ $expect ][ $token ])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * If the next token is a valid one, move the position of cursor one step forward. Otherwise throws an exception.
     * @param array $tokens
     * @throws TokenizeException
     * @return mixed
     */
    public function _next($tokens) {
        $this->next();
        if(!$this->curr) {
            throw new TokenizeException("Unexpected end of expression");
        }
        if($tokens) {
            if($this->_valid($tokens, $this->key())) {
                return;
            }
        } else {
            return;
        }
        if(count($tokens) == 1 && is_string($tokens[0])) {
            $expect = ", expect '".$tokens[0]."'";
        } else {
            $expect = "";
        }
        throw new TokenizeException("Unexpected token '".$this->current()."'$expect");
    }

    /**
     * Fetch next specified token or throw an exception
     * @return mixed
     */
    public function getNext(/*int|string $token1, int|string $token2, ... */) {
        $this->_next(func_get_args());
        return $this->current();
    }

    /**
     * Concatenate tokens from the current one to one of the specified and returns the string.
     * @param string|int $token
     * @param ...
     * @return string
     */
    public function getStringUntil($token/*, $token2 */) {
        $str = '';
        while($this->valid() && !$this->_valid(func_get_args(), $this->curr[0])) {
            $str .= $this->curr[1].$this->curr[2];
            $this->next();
        }
        return $str;
    }

    /**
     * Return substring. This method doesn't move pointer.
     * @param int $offset
     * @param int $limit
     * @return string
     */
    public function getSubstr($offset, $limit = 0) {
        $str = '';
        if(!$limit) {
            $limit = $this->_max;
        } else {
            $limit += $offset;
        }
        for($i = $offset; $i <= $limit; $i++){
            $str .= $this->tokens[$i][1].$this->tokens[$i][2];
        }
        return $str;
    }

    /**
     * Return token and move pointer
     * @return mixed
     * @throws UnexpectedException
     */
    public function getAndNext() {
        if($this->curr) {
            $cur = $this->curr[1];
            $this->next();
        } else {
            throw new UnexpectedException($this, func_get_args());
        }

        return $cur;
    }

    /**
     * Check if the next token is one of the specified.
     * @param $token1
     * @return bool
     */
    public function isNext($token1/*, ...*/) {
        return $this->next && $this->_valid(func_get_args(), $this->next[0]);
    }

    /**
     * Check if the current token is one of the specified.
     * @param $token1
     * @return bool
     */
    public function is($token1/*, ...*/) {
        return $this->curr && $this->_valid(func_get_args(), $this->curr[0]);
    }

    /**
     * Check if the previous token is one of the specified.
     * @param $token1
     * @return bool
     */
    public function isPrev($token1/*, ...*/) {
        return $this->prev && $this->_valid(func_get_args(), $this->prev[0]);
    }

    /**
     * Get specified token
     *
     * @param string|int $token1
     * @throws UnexpectedException
     * @return mixed
     */
    public function get($token1 /*, $token2 ...*/) {
        if($this->curr && $this->_valid(func_get_args(), $this->curr[0])) {
            return $this->curr[1];
        } else {
            throw new UnexpectedException($this, func_get_args());
        }
    }

    /**
     * Step back
     * @return Tokenizer
     */
    public function back() {
        if($this->p === 0) {
            return $this;
        }
        $this->p--;
        unset($this->prev, $this->curr, $this->next);
        return $this;
    }

    /**
     * Lazy load properties
     *
     * @param string $key
     * @return mixed
     */
    public function __get($key) {
        switch($key) {
            case 'curr':
                return $this->curr = ($this->p <= $this->_max) ? $this->tokens[$this->p] : null;
            case 'next':
                return $this->next = ($this->p + 1 <= $this->_max) ? $this->tokens[$this->p + 1] : null;
            case 'prev':
                return $this->prev = $this->p ? $this->tokens[$this->p - 1] : null;
            default:
                return $this->$key = null;
        }
    }

    /**
     * Return the key of the current element
     * @link http://php.net/manual/en/iterator.key.php
     * @return mixed scalar on success, or null on failure.
     */
    public function key() {
        return $this->curr ? $this->curr[0] : null;
    }

    /**
     * Checks if current position is valid
     * @link http://php.net/manual/en/iterator.valid.php
     * @return boolean The return value will be casted to boolean and then evaluated.
     *       Returns true on success or false on failure.
     */
    public function valid() {
        return (bool)$this->curr;
    }

    /**
     * Rewind the Iterator to the first element. Disabled.
     * @link http://php.net/manual/en/iterator.rewind.php
     * @return void Any returned value is ignored.
     */
    public function rewind() {}

    /**
     * Get token name
     * @static
     * @param int|string $token
     * @return string
     */
    public static function getName($token) {
        if(is_string($token)) {
            return $token;
        } elseif(is_integer($token)) {
            return token_name($token);
        } elseif(is_array($token)) {
            return token_name($token[0]);
        } else {
            return null;
        }
    }

    /**
     * Return whitespace of current token
     * @return null
     */
    public function getWhiteSpace() {
        if($this->curr) {
            return $this->curr[2];
        } else {
            return null;
        }
    }

    /**
     * Skip specific token or throw an exception
     *
     * @throws UnexpectedException
     * @return Tokenizer
     */
    public function skip(/*$token1, $token2, ...*/) {
        if(func_num_args()) {
            if($this->_valid(func_get_args(), $this->curr[0])) {
                $this->next();
                return $this;
            } else {
                throw new UnexpectedException($this, func_get_args());
            }
        } else {
            $this->next();
            return $this;
        }
    }

    /**
     * Skip specific token or do nothing
     *
     * @param int|string $token1
     * @return Tokenizer
     */
    public function skipIf($token1/*, $token2, ...*/) {
        if($this->_valid(func_get_args(), $this->curr[0])) {
            $this->next();
        }
        return $this;
    }

    /**
     * Check current token's type
     *
     * @param int|string $token1
     * @return Tokenizer
     * @throws UnexpectedException
     */
    public function need($token1/*, $token2, ...*/) {
        if($this->_valid(func_get_args(), $this->curr[0])) {
            return $this;
        } else {
            throw new UnexpectedException($this, func_get_args());
        }
    }

    /**
     * Count elements of an object
     * @link http://php.net/manual/en/countable.count.php
     * @return int The custom count as an integer.
     * The return value is cast to an integer.
     */
    public function count() {
        return $this->_max;
    }

    /**
     * Get tokens near current token
     * @param int $before count tokens before current token
     * @param int $after count tokens after current token
     * @return array
     */
    public function getSnippet($before = 0, $after = 0) {
        $from = 0;
        $to = $this->p;
        if($before > 0) {
            if($before > $this->p) {
                $from = $this->p;
            } else {
                $from = $before;
            }
        } elseif($before < 0) {
            $from = $this->p + $before;
            if($from < 0) {
                $from = 0;
            }
        }
        if($after > 0) {
            $to = $this->p + $after;
            if($to > $this->_max) {
                $to = $this->_max;
            }
        } elseif($after < 0) {
            $to = $this->_max + $after;
            if($to < $this->p) {
                $to = $this->p;
            }
        } elseif($this->p > $this->_max) {
            $to = $this->_max;
        }
        $code = array();
        for($i=$from; $i<=$to; $i++) {
            $code[] = $this->tokens[ $i ];
        }

        return $code;
    }

    /**
     * Return snippet as string
     * @param int $before
     * @param int $after
     * @return string
     */
    public function getSnippetAsString($before = 0, $after = 0) {
        $str = "";
        foreach($this->getSnippet($before, $after) as $token) {
            $str .= $token[1].$token[2];
        }
        return trim(str_replace("\n", '↵', $str));
    }

    /**
     * Check if current is special value: true, TRUE, false, FALSE, null, NULL
     * @return bool
     */
    public function isSpecialVal() {
        return isset(self::$spec[$this->current()]);
    }

    /**
     * Check if the token is last
     * @return bool
     */
    public function isLast() {
        return $this->p === $this->_max;
    }

    /**
     * Move pointer to the end
     */
    public function end() {
        $this->p = $this->_max;
    }

    /**
     * Return line number of the current token
     * @return mixed
     */
    public function getLine() {
        return $this->curr ? $this->curr[3] : $this->_last_no;
    }

    /**
     * Parse code and append tokens. This method move pointer to offset.
     * @param string $code
     * @param int $offset
     * @return Tokenizer
     */
    public function append($code, $offset = -1) {
        if($offset != -1) {
            $code = $this->getSubstr($offset).$code;
            if($this->p > $offset) {
                $this->p = $offset;
            }

            $this->tokens = array_slice($this->tokens, 0, $offset);
        }
        $tokens = self::decode($code);
        unset($tokens[-1], $this->prev, $this->curr, $this->next);
        $this->tokens = array_merge($this->tokens, $tokens);
        $this->_max = count($this->tokens) - 1;
        $this->_last_no = $this->tokens[$this->_max][3];
        return $this;
    }
}

/**
 * Tokenize error
 */
class TokenizeException extends \RuntimeException {}

/**
 * Unexpected token
 */
class UnexpectedException extends TokenizeException {
    public function __construct(Tokenizer $tokens, $expect = null, $where = null) {
        if($expect && count($expect) == 1 && is_string($expect[0])) {
            $expect = ", expect '".$expect[0]."'";
        } else {
            $expect = "";
        }
        if(!$tokens->curr) {
            $this->message = "Unexpected end of ".($where?:"expression")."$expect";
        } elseif($tokens->curr[1] === "\n") {
            $this->message = "Unexpected new line$expect";
        } elseif($tokens->curr[0] === T_WHITESPACE) {
            $this->message = "Unexpected whitespace$expect";
        } else {
            $this->message = "Unexpected token '".$tokens->current()."' in ".($where?:"expression")."$expect";
        }
    }
};