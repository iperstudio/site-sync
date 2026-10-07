<?php

declare(strict_types=1);

namespace Iperstudio\SiteSync;

use RuntimeException;
use Throwable;

final class Application
{
    private const CONFIG = 'site-sync.php';

    public function run(array $arguments): int
    {
        try {
            [$args, $options] = $this->parse($arguments);
            if (!$args || isset($options['help'])) {
                $this->help();
                return 0;
            }
            if ($args[0] === 'init') {
                if (count($args) !== 1 || isset($options['dry-run'])) {
                    throw new RuntimeException('Usage: site-sync init [--edit] [--project=PATH]');
                }
                return $this->init($options);
            }
            if ($args[0] === 'aliases') {
                if (count($args) !== 1 || isset($options['edit']) || isset($options['dry-run'])) {
                    throw new RuntimeException('Usage: site-sync aliases [--project=PATH]');
                }
                $root = $this->root($options, true);
                $source = $this->prepareAliases($root, $this->read($root));
                if ($source !== null && $this->yesNo('Save Composer shortcuts? (yes/no)', 'yes')) {
                    $this->writeFile($root . '/composer.json', $source);
                    echo "Composer shortcuts saved.\n";
                }
                return 0;
            }
            if (count($args) !== 3 || !in_array($args[0], ['pull', 'push'], true) || isset($options['edit'])) {
                throw new RuntimeException('Usage: site-sync pull|push staging|production TYPE [--dry-run] [--project=PATH]');
            }
            return $this->sync($args[0], $args[1], $args[2], $options);
        } catch (Throwable $error) {
            fwrite(STDERR, 'Error: ' . $error->getMessage() . "\n");
            return 1;
        }
    }

    private function parse(array $arguments): array
    {
        $args = $options = [];
        for ($i = 0; $i < count($arguments); $i++) {
            $arg = $arguments[$i];
            if (in_array($arg, ['--help', '-h', '--edit', '--dry-run'], true)) {
                $options[ltrim($arg === '-h' ? '--help' : $arg, '-')] = true;
            } elseif ($arg === '--project') {
                $options['project'] = $arguments[++$i] ?? throw new RuntimeException('--project requires a directory.');
            } elseif (str_starts_with($arg, '--project=')) {
                $options['project'] = substr($arg, 10);
            } elseif (str_starts_with($arg, '-')) {
                throw new RuntimeException('Unknown option: ' . $arg);
            } else {
                $args[] = $arg;
            }
        }
        return [$args, $options];
    }

    private function help(): void
    {
        echo <<<'HELP'
Site Sync — content synchronization over SSH using rsync

  site-sync init [--edit] [--project=PATH]
  site-sync aliases [--project=PATH]
  site-sync pull staging|production TYPE [--dry-run] [--project=PATH]
  site-sync push staging|production TYPE [--dry-run] [--project=PATH]

TYPE is a configured path name, such as content or accounts.
The directory containing site-sync.php is the project root.
Without --project, synchronization searches the current directory and its parents.
init creates a config in the current directory; --edit finds an existing config.
init offers Composer shortcuts when composer.json exists; aliases adds them later.
Dry runs preview changes without modifying files or requiring confirmation.
Actual synchronization uses --delete: destination files absent from the source are removed.
Production pushes require "yes yes yes"; other operations require "yes".

HELP;
    }

    private function root(array $options, bool $search): string
    {
        $root = realpath($options['project'] ?? getcwd());
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('Project directory does not exist.');
        }
        if ($search && !isset($options['project'])) {
            while (!is_file($root . '/' . self::CONFIG) && dirname($root) !== $root) {
                $root = dirname($root);
            }
        }
        if ($search && !is_file($root . '/' . self::CONFIG)) {
            throw new RuntimeException('No site-sync.php found. Run site-sync init or specify --project.');
        }
        return $root;
    }

    private function read(string $root): array
    {
        $config = require $root . '/' . self::CONFIG;
        if (!is_array($config)) {
            throw new RuntimeException('Configuration must return an array.');
        }
        return $config;
    }

    private function ask(string $question, ?string $default = null): string
    {
        echo $question . ($default !== null ? " [{$default}]" : '') . ': ';
        $line = fgets(STDIN);
        if ($line === false) {
            throw new RuntimeException('Input ended; operation cancelled.');
        }
        $answer = trim($line);
        return $answer === '' && $default !== null ? $default : $answer;
    }

    private function server(string $label, array $existing): array
    {
        $server = [
            'host' => $this->ask("{$label} SSH host / IP", $existing['host'] ?? null),
            'user' => $this->ask("{$label} SSH user", $existing['user'] ?? 'ploi'),
            'root' => $this->ask("{$label} remote project directory (relative to SSH home or absolute)", $existing['root'] ?? null),
        ];
        $this->validateServer($server);
        return $server;
    }

    private function yesNo(string $question, string $default): bool
    {
        $answer = strtolower($this->ask($question, $default));
        if (!in_array($answer, ['yes', 'no'], true)) {
            throw new RuntimeException('Expected yes or no.');
        }
        return $answer === 'yes';
    }

    private function exportValue(mixed $value, int $level = 0): string
    {
        if (!is_array($value)) {
            return $value === null ? 'null' : var_export($value, true);
        }
        if ($value === []) {
            return '[]';
        }
        $lines = ['['];
        foreach ($value as $key => $item) {
            $lines[] = str_repeat('    ', $level + 1) . var_export($key, true)
                . ' => ' . $this->exportValue($item, $level + 1) . ',';
        }
        $lines[] = str_repeat('    ', $level) . ']';
        return implode("\n", $lines);
    }

    /** Return a merged Composer file; existing scripts and other settings are preserved. */
    private function prepareAliases(string $root, array $config): ?string
    {
        $file = $root . '/composer.json';
        if (!is_file($file)) {
            throw new RuntimeException('No composer.json found in the project.');
        }
        $original = file_get_contents($file);
        $composer = json_decode($original, false, 512, JSON_THROW_ON_ERROR);
        if (!$composer instanceof \stdClass || (property_exists($composer, 'scripts') && !$composer->scripts instanceof \stdClass)) {
            throw new RuntimeException('composer.json and its scripts must be JSON objects.');
        }
        $composer->scripts ??= new \stdClass();
        $shortcuts = ['sync' => 'site-sync'];
        foreach (($config['environments'] ?? []) as $environment => $server) {
            if (!is_array($server) || !in_array($environment, ['production', 'staging'], true)) {
                continue;
            }
            foreach (array_keys($config['paths'] ?? []) as $type) {
                // Script names must remain simple command names; arbitrary configured paths
                // are still available through the general composer sync shortcut.
                if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]*\z/', (string) $type)) {
                    echo "Skipping shortcuts for path name: {$type}. Use composer sync instead.\n";
                    continue;
                }
                foreach (['pull', 'push'] as $direction) {
                    $shortcuts["{$direction}-{$environment}-{$type}"] = "site-sync {$direction} {$environment} " . escapeshellarg((string) $type);
                }
            }
        }
        $changed = false;
        foreach ($shortcuts as $name => $command) {
            $script = ['Composer\\Config::disableProcessTimeout', $command];
            if (property_exists($composer->scripts, $name)) {
                if ($composer->scripts->{$name} !== $script) {
                    echo "Keeping existing Composer script: {$name} (name already in use).\n";
                }
                continue;
            }
            $composer->scripts->{$name} = $script;
            echo "Adding Composer shortcut: composer {$name}\n";
            $changed = true;
        }
        if (!$changed) {
            echo "No Composer shortcuts to add.\n";
            return null;
        }
        $source = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        // Retain the existing indentation convention (otherwise use four spaces).
        if (preg_match('/^([ \t]+)"/m', $original, $matches)) {
            $indent = $matches[1];
            $source = preg_replace_callback('/^( +)/m', fn (array $m): string => str_repeat($indent, intdiv(strlen($m[1]), 4)), $source);
        }
        return $source . "\n";
    }

    private function init(array $options): int
    {
        $edit = isset($options['edit']);
        $root = $this->root($options, $edit);
        $file = $root . '/' . self::CONFIG;
        $exists = file_exists($file);
        $existing = $edit ? $this->read($root) : [];
        if ($exists && !$edit) {
            throw new RuntimeException('Configuration already exists. Use init --edit to update it.');
        }
        echo "Project: {$root}\n";
        $environments = [];
        foreach (['production', 'staging'] as $environment) {
            $default = isset($existing['environments'][$environment]) || (!$edit && $environment === 'production') ? 'yes' : 'no';
            $answer = strtolower($this->ask("Configure {$environment}? (yes/no)", $default));
            if (!in_array($answer, ['yes', 'no'], true)) {
                throw new RuntimeException('Expected yes or no.');
            }
            $environments[$environment] = $answer === 'yes'
                ? $this->server(ucfirst($environment), $existing['environments'][$environment] ?? [])
                : null;
        }
        if (!array_filter($environments)) {
            throw new RuntimeException('Configure at least one environment: production or staging.');
        }
        $paths = $existing['paths'] ?? [];
        foreach (['content' => 'content', 'accounts' => 'site/accounts'] as $name => $default) {
            $old = $paths[$name] ?? ['local' => $default, 'remote' => $default];
            $local = $this->ask("{$name}: local path", $old['local']);
            $remote = $this->ask("{$name}: remote path", $old['remote']);
            $this->validatePath($local);
            $this->validatePath($remote);
            $paths[$name] = ['local' => $local, 'remote' => $remote];
        }
        $config = ['environments' => $environments, 'paths' => $paths];
        $composerSource = null;
        if (is_file($root . '/composer.json')) {
            if ($this->yesNo('Add Composer shortcuts? (yes/no)', 'yes')) {
                $composerSource = $this->prepareAliases($root, $config);
            }
        } else {
            echo "No composer.json found; skipping Composer shortcuts.\n";
        }
        echo "\nConfiguration:\n" . $this->exportValue($config) . "\n";
        if (strtolower($this->ask($exists ? 'Overwrite site-sync.php? (yes/no)' : 'Save site-sync.php? (yes/no)', 'no')) !== 'yes') {
            echo "Action aborted.\n";
            return 0;
        }
        $source = "<?php\n\n// Project-specific configuration; SSH authentication uses your existing keys/agent.\nreturn " . $this->exportValue($config) . ";\n";
        $this->writeFile($file, $source);
        echo "Saved {$file}\n";
        if ($composerSource !== null) {
            $this->writeFile($root . '/composer.json', $composerSource);
            echo "Composer shortcuts saved. Use composer sync or the generated shortcuts.\n";
        }
        return 0;
    }

    private function writeFile(string $file, string $source): void
    {
        $root = dirname($file);
        $exists = file_exists($file);
        if ($exists) {
            // Write next to the config and replace it only once the complete file is ready.
            $temp = tempnam($root, '.site-sync-');
            if ($temp === false) {
                throw new RuntimeException('Could not create temporary configuration.');
            }
            try {
                if (file_put_contents($temp, $source) !== strlen($source) || !rename($temp, $file)) {
                    throw new RuntimeException('Could not save configuration.');
                }
            } finally {
                if (file_exists($temp)) {
                    unlink($temp);
                }
            }
        } else {
            $handle = fopen($file, 'x');
            if ($handle === false) {
                throw new RuntimeException('Could not create configuration.');
            }
            try {
                if (fwrite($handle, $source) !== strlen($source)) {
                    throw new RuntimeException('Could not write complete configuration.');
                }
            } finally {
                fclose($handle);
            }
        }
    }

    private function validateServer(array $server): void
    {
        if (!is_string($server['host'] ?? null) || !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9.-]*\z/', $server['host'])) {
            throw new RuntimeException('SSH host must be an IPv4 address or hostname.');
        }
        if (!is_string($server['user'] ?? null) || !preg_match('/\A[a-zA-Z0-9_][a-zA-Z0-9_.-]*\z/', $server['user'])) {
            throw new RuntimeException('Invalid SSH user.');
        }
        $path = $server['root'] ?? '';
        if (!is_string($path) || trim($path) === '' || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            throw new RuntimeException('A remote project directory is required.');
        }
        // The directory is relative to SSH home unless it is explicitly absolute.
        if (str_starts_with($path, '~') || str_starts_with($path, '-') || in_array('..', explode('/', $path), true) || trim($path, '/. ') === '') {
            throw new RuntimeException('Remote project directory must identify a site, without ~ or .. components.');
        }
    }

    private function validatePath(mixed $path): void
    {
        if (!is_string($path) || trim($path, '/. ') === '' || str_starts_with($path, '/') || str_starts_with($path, '~') || str_starts_with($path, '-') || in_array('..', explode('/', $path), true) || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            throw new RuntimeException('Content paths must be non-empty relative directories without .. components.');
        }
    }

    private function sync(string $direction, string $environment, string $type, array $options): int
    {
        if (!in_array($environment, ['production', 'staging'], true)) {
            throw new RuntimeException('Environment must be production or staging.');
        }
        $root = $this->root($options, true);
        $config = $this->read($root);
        $server = $config['environments'][$environment] ?? null;
        if (!is_array($server)) {
            throw new RuntimeException("Environment {$environment} is not configured.");
        }
        $this->validateServer($server);
        $path = $config['paths'][$type] ?? null;
        if (!is_array($path)) {
            throw new RuntimeException("Unknown path: {$type}.");
        }
        $this->validatePath($path['local'] ?? null);
        $this->validatePath($path['remote'] ?? null);
        $local = realpath($root . '/' . $path['local']);
        if ($local === false || !is_dir($local)) {
            throw new RuntimeException('Local directory does not exist. Create it before synchronization: ' . $root . '/' . $path['local']);
        }
        if (!str_starts_with($local, $root . '/')) {
            throw new RuntimeException('Local directory must remain inside the project (including symlink targets).');
        }
        $remotePath = rtrim($server['root'], '/') . '/' . trim($path['remote'], '/') . '/';
        $remoteDisplay = $server['user'] . '@' . $server['host'] . ':' . $remotePath;
        // Quote the path for the remote SSH shell; proc_open's array bypasses the local shell.
        $remote = $server['user'] . '@' . $server['host'] . ':' . escapeshellarg($remotePath);
        $dryRun = isset($options['dry-run']);
        $source = $direction === 'push' ? $local . '/' : $remoteDisplay;
        $destination = $direction === 'push' ? $remoteDisplay : $local . '/';
        echo "Project: {$root}\n{$direction} {$environment} {$type}\nSource: {$source}\nDestination: {$destination}\n";
        echo $dryRun ? "Preview only (--dry-run).\n" : "Files absent from source will be deleted at destination (--delete).\n";
        if (!$dryRun) {
            // Keep the deliberate production confirmation behavior of the original scripts.
            $expected = $direction === 'push' && $environment === 'production' ? 'yes yes yes' : 'yes';
            if (strtolower($this->ask('Proceed? (yes/no)')) !== $expected) {
                echo "Action aborted.\n";
                return 0;
            }
        }
        $command = ['rsync', '-axHv', '--itemize-changes', '--progress', '--delete'];
        if ($dryRun) {
            $command[] = '--dry-run';
        }
        $command[] = '--';
        array_push($command, ...($direction === 'push' ? [$local . '/', $remote] : [$remote, $local . '/']));
        // Explicit remote quoting works with old macOS rsync and modern rsync.
        $environmentVariables = getenv();
        $environmentVariables['RSYNC_OLD_ARGS'] = '1';
        $environmentVariables['RSYNC_PROTECT_ARGS'] = '0';
        $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $root, $environmentVariables);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start rsync.');
        }
        $code = proc_close($process);
        if ($code !== 0) {
            throw new RuntimeException("rsync failed (exit code: {$code}).");
        }
        echo $dryRun ? "Preview completed.\n" : "Synchronization completed.\n";
        return 0;
    }
}
