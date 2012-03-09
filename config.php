<?php

// location of sqlite file containing function information
$sqliteFile = __DIR__ . '/php_manual_en.sqlite';

// working directory to use (generated scripts might put files in there)
$cwd = __DIR__ . '/cwd';

// directory to put resulting generated scripts in
$results = __DIR__ . '/results';

// location of temporary files that will be generated
$generatedFile = $cwd . '/generated.php';
$stdoutFile    = $cwd . '/output';
$stderrFile    = $cwd . '/errors';

// Code to prepend before generated code
// {{ expr }} sequences will be replaced by the result of evaluating expr. They
// are useful for including randomly generated values in the generated files (so
// the random numbers are not generated during runtime and everything stays
// reproducable).
$initCode = <<<'EOC'
<?php

error_reporting(E_ALL);
set_time_limit(5);
ini_set('memory_limit', '256M');

// various integers
$intMax = PHP_INT_MAX;
$intMaxPre = PHP_INT_MAX - 1;
$intMinPre = -PHP_INT_MAX;
$intMin = -PHP_INT_MAX - 1;
$intZero = 0;
$intPlusOne = 1;
$intMinusOne = -1;
$intRandom = {{ mt_rand(-PHP_INT_MAX - 1, PHP_INT_MAX) }};

// various floats
$floatPositiveInfinity = INF;
$floatNegativeInfinity = -INF;
$floatNaN = NAN;
$floatMax = 1.7976931348623E+308;
$floatMin = -1.7976931348623E+308;
$floatPlusZero = 0.0;
$floatMinusZero = -0.0;
$floatPlusOne = 1.0;
$floatMinusOne = -1.0;
$floatRandomSmall = {{ lcg_value() }};
$floatRandomAny = {{ (lcg_value() - 0.5) * 2 * 1.7976931348623E+308 }};

// various strings
$stringEmpty = '';
$stringLarge = str_repeat('*', {{ mt_rand(0, 1024 * 1024) }});
$stringSpecial = "\xff\xfe\x00\x01\x02\x03\r\n\t";
$stringNormal = 'abcdefghijklmnopqrstuvwxyz ABCDEFGHIJKLMNOPQRSTUVWXYZ';

class StringifyableObject { public function __toString() { return 'abc'; } }
$stringObject = new StringifyableObject;

// various arrays
$arrayEmpty = array();
$arrayLarge = array_fill(0, {{ mt_rand(0, 1024 * 1024) }}, '*');
$arrayStrange = array(-1 => -5, 100 => 17, 0 => 'a', 'a' => 0, 1 => 'b', 'b' => 1);

// file resources
$fileResourceTemp = fopen('php://temp', 'wr');

// callbacks
$callbackInvalid = "\0\1";
$callbackStrlen = 'strlen';
$callbackClosure = function() { return 123; };
$callbackByRef = function(&$a, &$b, &$c) { return 321; };

// bools ...
$boolTrue = true;
$boolFalse = false;
EOC;

// Initial type variables
$initVars = array(
    'number' => array(
        'intMax', 'intMaxPre', 'intMinPre', 'intMin',
        'intZero', 'intPlusOne', 'intMinusOne',
        'intRandom',
        'floatPositiveInfinity', 'floatNegativeInfinity', 'floatNaN',
        'floatMax', 'floatMin',
        'floatPlusZero', 'floatMinusZero', 'floatPlusOne', 'floatMinusOne',
        'floatRandomSmall', 'floatRandomAny',
    ),
    'string' => array(
        'stringEmpty', 'stringLarge', 'stringSpecial', 'stringNormal',
    ),
    'array' => array(
        'arrayEmpty', 'arrayLarge', 'arrayStrange',
    ),
    'fileResource' => array(
        'fileResourceTemp',
    ),
    'callback' => array(
        'callbackInvalid', 'callbackStrlen', 'callbackClosure', 'callbackByRef',
    ),
    'bool' => array(
        'boolTrue', 'boolFalse',
    )
);

// How many calls to generate
$n = 50;

// Which functions to use
$functions = get_defined_functions();
// no userland functions
$functions = $functions["internal"];
// banned functions
$functions = array_diff($functions, array(
    // can take lots of time
    'sleep', 'usleep', 'time_nanosleep', 'time_sleep_until',
    'pcntl_sigwaitinfo', 'pcntl_sigtimedwait',
    'readline', 'readline_read_history',
    'dns_get_record',
    // we don't want anybody to get killed...
    'posix_kill', 'pcntl_alarm',
    // not supported anymore as of PHP 5.4, will only throw a fatal error
    'set_magic_quotes_runtime',
    // we already know that this function leaks, so disable it until the leak
    // is fixed
    'readline_callback_handler_install',
));

// Prefixes of functions operating on specific resources
$resourceFunctionPrefixes = array(
    'ftp', 'socket', 'proc', 'sem', 'shm', 'xml', 'xmlwriter', 'xmlrpc', 
);
