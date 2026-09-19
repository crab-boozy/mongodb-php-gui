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
    $time = mpg_junit_tick();

    if ( !$ok ) {
        $report['failures']++;
        $report['cases'][] = ['name' => $name, 'ok' => false, 'message' => $message, 'time' => $time];
        return;
    }

    $report['cases'][] = ['name' => $name, 'ok' => true, 'message' => '', 'time' => $time];

}

function mpg_junit_skip(string $name) : void {

    $report = &mpg_junit_state();
    $report['tests']++;
    $report['skipped']++;
    $report['cases'][] = ['name' => $name, 'skipped' => true, 'time' => mpg_junit_tick()];

}

/**
 * Wall-clock seconds elapsed since the previous check (0 for the first).
 * JUnit requires a `time` attribute; without it reporters render "NaNms".
 */
function mpg_junit_tick() : float {

    $report = &mpg_junit_state();
    $now = microtime(true);

    if ( $report['last'] === null ) {
        $report['last'] = $now;
        return 0.0;
    }

    $elapsed = $now - $report['last'];
    $report['last'] = $now;

    return $elapsed;

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
    $time = static function(float $value) : string {
        return sprintf('%.3f', $value);
    };

    $totalTime = 0.0;
    foreach ( $report['cases'] as $case ) {
        $totalTime += $case['time'] ?? 0.0;
    }

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= sprintf(
        '<testsuite name="%s" tests="%d" failures="%d" errors="0" skipped="%d" time="%s">' . "\n",
        $escape($suite),
        $report['tests'],
        $report['failures'],
        $report['skipped'],
        $time($totalTime)
    );

    foreach ( $report['cases'] as $case ) {
        $caseTime = $time($case['time'] ?? 0.0);

        if ( isset($case['skipped']) && $case['skipped'] ) {
            $xml .= sprintf(
                '  <testcase name="%s" time="%s"><skipped/></testcase>' . "\n",
                $escape($case['name']),
                $caseTime
            );
            continue;
        }

        if ( $case['ok'] ) {
            $xml .= sprintf('  <testcase name="%s" time="%s"/>' . "\n", $escape($case['name']), $caseTime);
            continue;
        }

        $xml .= sprintf('  <testcase name="%s" time="%s">' . "\n", $escape($case['name']), $caseTime);
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
 * @return array{tests:int, failures:int, skipped:int, cases:array, last:?float}
 */
function &mpg_junit_state() : array {

    static $report = ['tests' => 0, 'failures' => 0, 'skipped' => 0, 'cases' => [], 'last' => null];

    return $report;

}
