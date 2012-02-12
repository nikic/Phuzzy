<?php

error_reporting(E_ALL);

// Code to prepend before generated code
$initCode = <<<'EOC'
<?php

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
$intRandom = mt_rand($intMin, $intMax);

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
$floatRandomSmall = lcg_value();
$floatRandomAny = (lcg_value() - 0.5) * 2 * $floatMax;

// various strings
$stringEmpty = '';
$stringLarge = str_repeat('*', mt_rand(0, 1024 * 1024));
$stringSpecial = "\xff\xfe\x00\x01\x02\x03\r\n\t";
$stringNormal = 'abcdefghijklmnopqrstuvwxyz ABCDEFGHIJKLMNOPQRSTUVWXYZ';

class StringifyableObject { public function __toString() { return 'abc'; } }
$stringObject = new StringifyableObject;

// various arrays
$arrayEmpty = array();
$arrayLarge = array_fill(0, mt_rand(0, 1024 * 1024), '*');
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
    'int' => array(
        'intMax', 'intMaxPre', 'intMinPre', 'intMin',
        'intZero', 'intPlusOne', 'intMinusOne',
        'intRandom',
    ),
    'float' => array(
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
    'sleep', 'usleep', 'time_nanosleep',
    'pcntl_sigwaitinfo', 'pcntl_sigtimedwait',
    'readline',
    'dns_get_record',
    // we don't want anybody to get killed...
    'posix_kill', 'pcntl_alarm',
    // we also want to find memory leaks, so don't generate the "explicit"
    // leaking function
    'leak',
    // we already know that this function leaks, so disable it until the leak
    // is fixed
    'readline_callback_handler_install',
));

class InvokablePDO extends PDO {
    public function __invoke($query) {
        $stmt = $this->prepare($query);
        $stmt->execute(array_slice(func_get_args(), 1));
        return $stmt;
    }
}

$db = new InvokablePDO('sqlite:' . realpath('php_manual_en.sqlite'));
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

while (true) {
    while (true) {
        // start off with just initial code segment
        $code = $initCode . "\n\n";

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
                    } elseif ($type == 'number') {
                        $type = mt_rand(0, 1) == 0 ? 'int' : 'float';
                    } elseif ($type == 'resource') {
                        if (preg_match('/^(ftp|socket|proc|sem|shm)_/', $function, $matches)) {
                            $type = $matches[1] . '_resource';
                        }
                    }

                    if (!isset($vars[$type])) {
                        // don't know that type right now, choose another function
                        var_dump('Missing: ' . $type);
                        continue 2;
                    }

                    $applicableVars = $vars[$type];
                    $args[] = '$' . $applicableVars[array_rand($applicableVars)];
                }

                $returnType = $functionInfo['return_type'];
                $returnVarName = $returnType . '_' . $function . '_' . $i;

                $code .= "\necho \"Running $i/$n ($function).\\n\";"
                      .  "\n$$returnVarName = $function(" . implode(', ', $args) . ");";

                $vars[$returnType][] = $returnVarName;

                break;
            }
        }

        file_put_contents('generated.php', $code);

        $output = array();
        exec('php -f generated.php 1>stdout 2>stderr', $output, $return);

        $stderr = file_get_contents('stderr');

        echo $stderr;
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

    copy('generated.php', 'investigate_' . uniqid() . '_' . $type . '.php');
}
