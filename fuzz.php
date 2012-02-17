<?php

error_reporting(E_ALL);

require 'config.php';

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
        && preg_match('/^(ftp|socket|proc|sem|shm|xml|xmlwriter|xmlrpc)_/', $function, $matches)
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

        // Return code 255 means that some error occured, so print it
        if ($return == 255) {
            $lastLine = `tail -n 1 $stdoutFile`;

            // if "thrown in" occurs (Exception) print a few more lines
            if (preg_match('(thrown in)', $lastLine)) {
                echo 'Last ten lines of output:', "\n", `tail -n 10 $stdoutFile`;
            } else {
                echo 'Last line of output:', "\n", $lastLine;
            }
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
