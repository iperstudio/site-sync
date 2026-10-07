<?php

declare(strict_types=1);

// Offline integration checks. All transfers use temporary directories.
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
    } elseif (is_dir($path)) {
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') {
                removeTree($path . '/' . $name);
            }
        }
        rmdir($path);
    }
}

/** Capture to files so large stdout/stderr cannot fill a pipe and block a test. */
function execute(array $command, string $cwd, array $environment, string $input = '', int $timeout = 10): array
{
    $streams = [tmpfile(), tmpfile(), tmpfile()];
    check(!in_array(false, $streams, true), 'Could not create capture files.');
    $process = null;
    try {
        fwrite($streams[0], $input);
        rewind($streams[0]);
        $process = proc_open($command, $streams, $pipes, $cwd, $environment);
        check(is_resource($process), 'Could not start test process.');
        $deadline = microtime(true) + $timeout;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process, 9);
                throw new RuntimeException('Timed out: ' . implode(' ', $command));
            }
            usleep(10000);
        } while (true);
        $closedCode = proc_close($process);
        $process = null;
        rewind($streams[1]);
        rewind($streams[2]);
        return [
            'code' => $status['exitcode'] >= 0 ? $status['exitcode'] : $closedCode,
            'stdout' => stream_get_contents($streams[1]),
            'stderr' => stream_get_contents($streams[2]),
        ];
    } finally {
        if (is_resource($process)) {
            proc_terminate($process, 9);
            proc_close($process);
        }
        foreach ($streams as $stream) {
            fclose($stream);
        }
    }
}

function ok(array $result): void
{
    check($result['code'] === 0, $result['stdout'] . $result['stderr']);
}

function invocation(string $record): array
{
    return json_decode(file_get_contents($record), true, flags: JSON_THROW_ON_ERROR);
}

$package = dirname(__DIR__);
$cli = $package . '/bin/site-sync';
$base = realpath(sys_get_temp_dir()) . '/site-sync-tests-' . bin2hex(random_bytes(8));
mkdir($base);
$exitCode = 0;
try {
    $project = $base . '/project with spaces';
    mkdir($project);
    $mockbin = $base . '/mockbin';
    mkdir($mockbin);
    $record = $base . '/rsync.json';
    $mock = $mockbin . '/rsync';
    file_put_contents($mock, <<<'MOCK'
#!/usr/bin/env php
<?php
file_put_contents(getenv('SYNC_RECORD'), json_encode([
    'args' => array_slice($argv, 1),
    'cwd' => getcwd(),
    'old_args' => getenv('RSYNC_OLD_ARGS'),
    'protect_args' => getenv('RSYNC_PROTECT_ARGS'),
], JSON_THROW_ON_ERROR));
if (getenv('SYNC_STRESS')) {
    fwrite(STDERR, str_repeat('E', 1048576));
}
exit((int) (getenv('SYNC_EXIT') ?: 0));
MOCK);
    chmod($mock, 0755);
    $environment = array_replace(getenv(), [
        'PATH' => $mockbin . ':' . getenv('PATH'),
        'SYNC_RECORD' => $record,
    ]);
    $run = function (array $args, string $answer = '', ?string $cwd = null, array $extra = [], ?string $binary = null) use ($cli, $project, $environment, $record): array {
        if (file_exists($record)) {
            unlink($record);
        }
        return execute([PHP_BINARY, $binary ?? $cli, ...$args], $cwd ?? $project, array_replace($environment, $extra), $answer);
    };
    $notCalled = function () use ($record): void {
        check(!file_exists($record), 'rsync must not run for a rejected operation.');
    };

    ok($run(['--help']));
    $answers = implode("\n", ['127.0.0.1', '', 'example.test', 'no', 'content with spaces', 'remote content', '', '', 'yes']) . "\n";
    ok($run(['init'], $answers));
    $config = $project . '/site-sync.php';
    $original = file_get_contents($config);
    check(str_contains($original, "'staging' => NULL"), 'Staging should be optional.');
    check($run(['init'])['code'] === 1, 'init must not overwrite configuration.');
    check(file_get_contents($config) === $original, 'init modified existing configuration.');
    ok($run(['init', '--edit'], str_repeat("\n", 8) . "no\n"));
    check(file_get_contents($config) === $original, 'Declined edit changed configuration.');
    ok($run(['init', '--edit'], str_repeat("\n", 8) . "yes\n"));
    check(file_get_contents($config) === $original, 'Defaults did not preserve configuration.');
    ok($run(['init', '--edit'], str_repeat("\n", 3) . "yes\n127.0.0.2\n\nstaging.test\n" . str_repeat("\n", 4) . "yes\n"));
    mkdir($project . '/content with spaces');
    mkdir($project . '/site/accounts', 0777, true);
    $nested = $project . '/site/nested';
    mkdir($nested);
    foreach (['pull', 'push'] as $direction) {
        foreach (['production', 'staging'] as $target) {
            foreach (['content', 'accounts'] as $name) {
                $answer = $direction === 'push' && $target === 'production' ? "yes yes yes\n" : "yes\n";
                ok($run([$direction, $target, $name], $answer, $nested));
                $call = invocation($record);
                check($call['cwd'] === $project, 'Project discovery failed.');
                check($call['old_args'] === '1' && $call['protect_args'] === '0', 'Remote quoting environment is incorrect.');
                $local = $project . '/' . ($name === 'content' ? 'content with spaces' : 'site/accounts') . '/';
                $operands = array_slice($call['args'], -2);
                check($operands[$direction === 'push' ? 0 : 1] === $local, 'Local path or transfer direction is incorrect.');
                check(in_array('--delete', $call['args'], true), 'Missing --delete.');
                check(str_contains($operands[$direction === 'push' ? 1 : 0], $target === 'production' ? '127.0.0.1' : '127.0.0.2'), 'Wrong remote host.');
            }
        }
    }
    ok($run(['push', 'production', 'content'], "yes\n"));
    $notCalled();
    ok($run(['pull', 'production', 'content'], "no\n"));
    $notCalled();
    check($run(['push', 'production', 'content'])['code'] === 1, 'EOF must not authorize a transfer.');
    $notCalled();
    ok($run(['push', 'production', 'content', '--dry-run', '--project', $project], cwd: $base));
    check(in_array('--dry-run', invocation($record)['args'], true), 'Missing --dry-run.');
    $stress = $run(['pull', 'production', 'content', '--dry-run'], extra: ['SYNC_STRESS' => '1']);
    ok($stress);
    check(strlen($stress['stderr']) === 1048576, 'stderr output was truncated.');
    $result = $run(['pull', 'production', 'content', '--dry-run'], extra: ['SYNC_EXIT' => '23']);
    check($result['code'] === 1 && str_contains($result['stderr'], 'exit code: 23'), 'rsync failure was not reported.');
    check(!str_contains($result['stdout'], 'Preview completed.'), 'Failure reported success.');
    foreach ([['pull', 'production', 'missing'], ['pull', 'unknown', 'content'], ['--unknown'], ['pull', 'production', 'content', '--project=' . $base . '/missing']] as $args) {
        check($run($args)['code'] === 1, 'Invalid arguments were accepted.');
        $notCalled();
    }
    file_put_contents($config, str_replace("'local' => 'content with spaces'", "'local' => '../outside'", file_get_contents($config)));
    check($run(['push', 'production', 'content'], "yes yes yes\n")['code'] === 1, 'Path traversal was accepted.');
    $notCalled();
    file_put_contents($config, str_replace("'local' => '../outside'", "'local' => 'linked'", file_get_contents($config)));
    mkdir($base . '/outside');
    symlink($base . '/outside', $project . '/linked');
    check($run(['push', 'production', 'content'], "yes yes yes\n")['code'] === 1, 'External symlink was accepted.');
    $notCalled();
    file_put_contents($config, $original);
    check($run(['pull', 'staging', 'content'], "yes\n")['code'] === 1, 'Unconfigured staging was accepted.');
    $notCalled();
    rmdir($project . '/content with spaces');
    check($run(['push', 'production', 'content'], "yes yes yes\n")['code'] === 1, 'Missing source was accepted.');
    $notCalled();
    mkdir($project . '/content with spaces');

    // Real Composer bin proxy in a consumer, using an offline path repository.
    $consumer = $base . '/consumer';
    mkdir($consumer);
    file_put_contents($consumer . '/composer.json', json_encode([
        'name' => 'test/consumer',
        'repositories' => [
            ['type' => 'path', 'url' => $package, 'options' => ['symlink' => false]],
            ['packagist.org' => false],
        ],
        'require-dev' => ['iperstudio/site-sync' => '*@dev'],
    ], JSON_THROW_ON_ERROR));
    ok(execute(['composer', 'install', '--no-interaction', '--no-plugins', '--no-scripts'], $consumer, $environment, timeout: 30));
    $proxy = $consumer . '/vendor/bin/site-sync';
    ok($run(['--help'], cwd: $consumer, binary: $proxy));
    ok($run(['pull', 'production', 'content', '--dry-run', '--project=' . $project], cwd: $consumer, binary: $proxy));
    check(array_slice(invocation($record)['args'], -1)[0] === $project . '/content with spaces/', 'Composer proxy selected the wrong root.');

    // Real rsync protocol with a local shell replacing SSH; no network connection.
    $transport = $base . '/local-ssh';
    file_put_contents($transport, <<<'TRANSPORT'
#!/usr/bin/env php
<?php
$index = array_search('rsync', $argv, true);
if ($index === false) {
    fwrite(STDERR, "Missing rsync server command.\n");
    exit(1);
}
$process = proc_open(['sh', '-c', implode(' ', array_slice($argv, $index))], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
exit(is_resource($process) ? proc_close($process) : 1);
TRANSPORT);
    chmod($transport, 0755);
    $remoteRoot = $base . "/remote site with 'quote";
    $remoteContent = $remoteRoot . '/content with spaces';
    mkdir($remoteContent, 0777, true);
    $localContent = $project . '/content with spaces';
    file_put_contents($localContent . '/example.txt', 'local version');
    file_put_contents($remoteContent . '/obsolete.txt', 'delete me');
    file_put_contents($config, "<?php return " . var_export([
        'environments' => ['production' => ['host' => 'localhost', 'user' => 'ploi', 'root' => $remoteRoot]],
        'paths' => ['content' => ['local' => 'content with spaces', 'remote' => 'content with spaces']],
    ], true) . ";\n");
    $realEnvironment = array_replace(getenv(), ['RSYNC_RSH' => $transport, 'RSYNC_PROTECT_ARGS' => '1']);
    $realRun = fn (array $args, string $answer = ''): array => execute([PHP_BINARY, $cli, ...$args], $project, $realEnvironment, $answer);
    ok($realRun(['push', 'production', 'content', '--dry-run']));
    check(file_exists($remoteContent . '/obsolete.txt') && !file_exists($remoteContent . '/example.txt'), 'Dry run changed destination files.');
    ok($realRun(['push', 'production', 'content'], "yes yes yes\n"));
    check(file_get_contents($remoteContent . '/example.txt') === 'local version', 'Push did not copy content.');
    check(!file_exists($remoteContent . '/obsolete.txt'), 'Push did not delete obsolete content.');
    unlink($remoteContent . '/example.txt');
    file_put_contents($remoteContent . '/from-remote.txt', 'remote version');
    ok($realRun(['pull', 'production', 'content'], "yes\n"));
    check(file_get_contents($localContent . '/from-remote.txt') === 'remote version', 'Pull did not copy content.');
    check(!file_exists($localContent . '/example.txt'), 'Pull did not delete obsolete content.');
    echo "PASS: wizard, edit, discovery, Composer proxy, confirmations, dry-run, failures and real rsync transfers.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    $exitCode = 1;
} finally {
    removeTree($base);
}
exit($exitCode);
