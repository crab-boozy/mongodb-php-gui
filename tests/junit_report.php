<?php

/**
 * Minimal JUnit XML reporter shared by the regression suites.
 *
 * Results are only written when the MPG_JUNIT environment variable points to
 * a destination file; otherwise every function here is a no-op, so the suites
 * behave exactly as before when run locally.
 *
 * Usage:
 *   require __DIR__ . '/junit_report.php';
 *   mpg_junit_record('some check', true);
 *   mpg_junit_write('suite-name');   // call once, at the end
 */

function mpg_junit_record(string $name, bool $ok, string $message = '') : void {

    $report = &mpg_junit_state();
    $report['tests']++;

    if ( !$ok ) {
        $report['failures']++;
        $report['cases'][] = ['name' => $name, 'ok' => false, 'message' => $message];
        return;
    }

    $report['cases'][] = ['name' => $name, 'ok' => true, 'message' => ''];

}

function mpg_junit_skip(string $name) : void {

    $report = &mpg_junit_state();
    $report['tests']++;
    $report['skipped']++;
    $report['cases'][] = ['name' => $name, 'skipped' => true];

}

function mpg_junit_write(string $suite) : void {

    $path = getenv('MPG_JUNIT');

    if ( $path === false || $path === '' ) {
        return;
    }

    $report = &mpg_junit_state();
    $escape = static function(string $value) : string {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    };

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= sprintf(
        '<testsuite name="%s" tests="%d" failures="%d" errors="0" skipped="%d">' . "\n",
        $escape($suite),
        $report['tests'],
        $report['failures'],
        $report['skipped']
    );

    foreach ( $report['cases'] as $case ) {
        if ( isset($case['skipped']) && $case['skipped'] ) {
            $xml .= sprintf(
                '  <testcase name="%s"><skipped/></testcase>' . "\n",
                $escape($case['name'])
            );
            continue;
        }

        if ( $case['ok'] ) {
            $xml .= sprintf('  <testcase name="%s"/>' . "\n", $escape($case['name']));
            continue;
        }

        $xml .= sprintf('  <testcase name="%s">' . "\n", $escape($case['name']));
        $xml .= sprintf('    <failure message="%s"/>' . "\n", $escape($case['message']));
        $xml .= '  </testcase>' . "\n";
    }

    $xml .= '</testsuite>' . "\n";

    $dir = dirname($path);

    if ( !is_dir($dir) ) {
        @mkdir($dir, 0777, true);
    }

    // Fail loudly: a silent report loss would hide results in CI.
    if ( false === file_put_contents($path, $xml) ) {
        throw new \RuntimeException("Cannot write JUnit report to $path");
    }

}

/**
 * @return array{tests:int, failures:int, skipped:int, cases:array}
 */
function &mpg_junit_state() : array {

    static $report = ['tests' => 0, 'failures' => 0, 'skipped' => 0, 'cases' => []];

    return $report;

}
