<?php

error_reporting(E_ALL);

/// CONFIGURATION

// working directory to use (generated scripts might put files in there)
$cwd     = __DIR__ . '/cwd';

// directory to put resulting generated scripts in
$results = __DIR__ . '/results';

// location of temporary files that will be generated
$generatedFile = $cwd . '/generated.php';
$stdoutFile    = $cwd . '/stdout';
$stderrFile    = $cwd . '/stderr';

// Code to prepend before generated code
// {{ expression }} are inline expressions which are evaluated and substituted
// They are used to have all values generated statically in the inserted code
// so everything stays reproducable
$initCode = <<<'EOC'
<?php

error_reporting(E_ALL);
set_time_limit(5);
ini_set('memory_limit', '256M');
chdir({{ "'" . $GLOBALS['cwd'] . "'" }});

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
$resourceFileTemp = fopen('php://temp', 'wr');

// callbacks
$callbackInvalid = "\0\1";
$callbackString = 'strlen';
$callbackClosure = function() { return 123; };

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
    'resource' => array(
        'resourceFileTemp',
    ),
    'callback' => array(
        'callbackInvalid', 'callbackString', 'callbackClosure',
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

/// SCRIPT

class InvokablePDO extends PDO {
    public function __invoke($query) {
        $stmt = $this->prepare($query);
        $stmt->execute(array_slice(func_get_args(), 1));
        return $stmt;
    }
}

function normalizeType($type, $function) {
    // use a general number type instead of float/int (they are usually
    // usable interchangably and we might catch some strange edge case
    // bugs through this)
    if ($type == 'float' || $type == 'int') {
        return 'number';
    }

    // use specific resources
    if ($type == 'resource'
        && preg_match('/^(ftp|socket|proc|sem|shm|xml|xmlwriter)_/', $function, $matches)
    ) {
        return $matches[1] . '_resource';
    }

    return $type;
}

$db = new InvokablePDO('sqlite:' . realpath('php_manual_en.sqlite'));
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

while (true) {
    while (true) {
        // start off with just initial code segment
        // substitute {{ }} inline expressions
        $code = preg_replace_callback(
            '(\{\{(.*?)\}\})',
            function ($matches) {
                return eval('return ' . $matches[1] . ';');
            },
            $initCode
        ) . "\n\n";

        // and use initial variables
        $vars = $initVars;

        for ($i = 1; $i <= $n; ++$i) {
            while (true) {
                $function = $functions[array_rand($functions)];

                if (!$functionInfo = $db('SELECT return_type FROM functions WHERE name = ?', $function)->fetch()) {
                    // unknown function, skip
                    continue;
                }

                $args = array();
                foreach ($db('SELECT type FROM params WHERE function_name = ?', $function) as $param) {
                    $type = $param['type'];

                    if ($type == 'mixed') {
                        $type = array_rand($vars);
                    }

                    $type = normalizeType($type, $function);

                    if (!isset($vars[$type])) {
                        // don't know that type right now, choose another function
                        continue 2;
                    }

                    $applicableVars = $vars[$type];
                    $args[] = '$' . $applicableVars[array_rand($applicableVars)];
                }

                $returnType = normalizeType($functionInfo['return_type'], $function);
                $returnVarName = $returnType . '_' . $function . '_' . $i;

                $code .= "\necho \"Running $i/$n ($function).\\n\";"
                      .  "\n$$returnVarName = $function(" . implode(', ', $args) . ");";

                $vars[$returnType][] = $returnVarName;

                break;
            }
        }

        file_put_contents($generatedFile, $code);

        $output = array();
        exec("php -f $generatedFile 1>$stdoutFile 2>$stderrFile", $output, $return);

        $stderr = file_get_contents($stderrFile);

        echo $stderr;

        $lastLine = `tail -n 1 $stdoutFile`;
        if (preg_match('(fatal error)i', $lastLine)) {
            echo 'Last line of output:', "\n", $lastLine;
        }
        if (preg_match('(thrown in)', $lastLine)) {
            echo 'Last ten lines of output:', "\n", `tail -n 10 $stdoutFile`;
        }

        echo 'Return: ', $return, "\n";

        if ($return == 139) {
            $type = 'segfault';
            break;
        } elseif ($return != 0 && $return != 255) {
            $type = 'unsuccessful';
            break;
        } elseif (preg_match('(memory leak)', $stderr)) {
            $type = 'memory_leak';
            break;
        } elseif (preg_match('(inconsistent)', $stderr)) {
            $type = 'inconsistent';
            break;
        } elseif (preg_match('(zero-terminated)', $stderr)) {
            $type = 'zero_terminated';
            break;
        }
    }

    copy($generatedFile, $results . '/investigate_' . uniqid() . '_' . $type . '.php');
}
