# Site Sync

Interactive PHP CLI for syncing project directories over SSH with rsync. Designed
for Kirby content, accounts and logs, it also works with other projects without depending
on Kirby.

## Requirements

- PHP 8.2 or later and Composer.
- `rsync` and SSH on the local machine and remote server.
- SSH authentication configured separately using your keys or agent.
- macOS or Linux. Native Windows support has not been tested.

## Installation

From your project's root directory:

```sh
composer require --dev iperstudio/site-sync
```

The package supplies the `vendor/bin/site-sync` command through Composer's `bin`
mechanism. No Composer plugin or Kirby plugin installer is required.

## Quick start

```sh
vendor/bin/site-sync init
composer sync pull production content -- --dry-run
composer pull-production-content
```

The setup wizard asks for:

- Whether to configure production and staging (either or both).
- SSH host or IP, SSH user and remote project directory for each selected environment.
- Separate local and remote paths for content, accounts and logs.
- Whether to add Composer shortcuts (defaults to `yes` when `composer.json` exists).

It previews the configuration before saving `site-sync.php`. Use
`vendor/bin/site-sync init --edit` to update it; existing values become defaults
and replacing the file requires confirmation.

To configure only staging, answer `no` to `Configure production?` and `yes` to
`Configure staging?`. At least one environment must be configured. During
`init --edit`, configured environments default to `yes` and absent ones to `no`,
so you can add production later without changing the staging settings.

Generated configuration uses short PHP array syntax (`[]`). Saving with
`init --edit` also rewrites older `array (...)` configurations in this format.

Relative remote project directories, such as `example.com`, are interpreted
relative to the SSH user's home directory. Absolute directories such as
`/home/ploi/example.com` are also accepted. Do not use a `~/` prefix.

Pull commands automatically create missing local destination directories,
including parent directories, after confirmation. Cancelling a pull or using
`--dry-run` does not create directories in the project. Push commands require
the local source directory to exist.

## Commands

```sh
vendor/bin/site-sync --help
vendor/bin/site-sync init
vendor/bin/site-sync init --edit
vendor/bin/site-sync pull production content
vendor/bin/site-sync pull production accounts
vendor/bin/site-sync pull production logs
vendor/bin/site-sync push production content
vendor/bin/site-sync push production accounts
vendor/bin/site-sync pull staging content
vendor/bin/site-sync push staging content
```

Add `--dry-run` to any sync command to preview changes without modifying files.
Commands require the selected environment to be configured.

## Project discovery and configuration

Sync commands search for `site-sync.php` in the current directory and then its
parents. The config's directory is the project root, independently of where the
package is installed. An explicit `--project=/path/to/site` selects that directory
without searching parents. `init` creates a config in the current directory;
`init --edit` searches for an existing one.

Example configuration (the wizard generates the same structure):

```php
<?php
return [
    'environments' => [
        'production' => [
            'host' => '192.0.2.10',
            'user' => 'ploi',
            'root' => 'example.com',
        ],
        'staging' => null,
    ],
    'paths' => [
        'content' => ['local' => 'content', 'remote' => 'content'],
        'accounts' => ['local' => 'site/accounts', 'remote' => 'site/accounts'],
        'logs' => ['local' => 'site/logs', 'remote' => 'site/logs'],
    ],
];
```

Replace the example host and directory with your server settings. Add other named
paths manually, then use them as the command's final argument.

The `logs` defaults are `site/logs` locally and remotely, matching the standard
location used by [KirbyLog](https://github.com/johannschopplich/kirbylog#readme).
Override either path in the wizard or in `site-sync.php` if your Kirby roots or
KirbyLog's `johannschopplich.kirbylog.dir` option use a different directory.
Site Sync does not install or load KirbyLog; it synchronizes the configured files.

For existing projects, run `composer sync init -- --edit` (or
`vendor/bin/site-sync init --edit`) to add the logs paths and accept the Composer
shortcuts. You can then use `composer pull-staging-logs`,
`composer pull-production-logs` for the environments you configured.

Logs support **pull only** and do not use `--delete`: files downloaded earlier
remain locally even after the server removes or rotates them. Files with matching
names are updated from the server; their contents are not concatenated. For example,
an active daily log can be downloaded again as new entries arrive. Use separate
local directories when collecting logs from servers with identical filenames.
The CLI rejects `push ... logs`, including through an old Composer shortcut.

Paths must be relative subdirectories without `..`. Local paths and symlinks must
resolve inside the project. Environments are production and staging; either can
be `null` when unused. Commands fail if their environment is not configured.
SSH hosts can be IPv4 addresses or
hostnames. Connections use the default SSH port; IPv6 and per-environment SSH
options are not currently supported.

The config is executable PHP: use trusted configuration files only. No password
or private key is stored. Each team member may keep `site-sync.php` out of Git, or
commit shared server/path settings if appropriate for the project.

## Synchronization behavior

`pull` downloads remote directory contents into the local directory. `push`
uploads local contents into the corresponding remote directory. Both use rsync
archive mode, one filesystem, hard links, progress and itemized changes.
They show the project, source and destination before confirmation.

For content, accounts and other custom paths, **files absent from the source are
deleted at the destination** (`--delete`). Logs pulls preserve local files absent
from the server. Preview changes
with `--dry-run` before synchronizing.

- Normal operations require `yes`.
- Production pushes require `yes yes yes`. The prompt deliberately still says
  `yes/no`; answering only `yes` cancels the operation.
- `--dry-run` reports proposed changes and deletions without changing files and
  does not ask for confirmation. It still connects to the remote server.
- Cancelling with an explicit response exits successfully; EOF or a failed
  transfer exits with code 1. Rsync errors are printed live, including its exit code.

## Composer shortcuts

When your project has a `composer.json`, `init` asks:

```text
Add Composer shortcuts? (yes/no) [yes]:
```

Accept to generate a general `sync` script and named shortcuts for every
configured environment and path. For example:

```sh
composer sync -- --help
composer sync init -- --edit
composer sync pull staging content -- --dry-run
composer pull-staging-content
composer push-staging-accounts
composer pull-staging-content -- --dry-run
```

Pass options after `--` so Composer forwards them to Site Sync. Production
shortcuts are generated only when production is configured, and likewise for staging.
For `logs`, only pull shortcuts are generated.
Custom path names containing characters other than letters, digits, underscores
or hyphens can be used through `composer sync` but do not get named shortcuts.

To add shortcuts to an already configured project without repeating the wizard:

```sh
vendor/bin/site-sync aliases
```

Both `init` and `aliases` accept `--project=/path/to/site`. Existing Composer
settings and scripts are preserved. If a shortcut name is already in use, Site Sync
reports the conflict and keeps the existing script. Repeating alias generation
does not change scripts already generated. Shortcuts are never removed automatically.

If there is no `composer.json`, `init` saves the configuration and skips shortcuts.
The `aliases` command requires an existing `composer.json` and `site-sync.php`.

Composer puts dependency binaries on PATH when running root scripts. Generated
shortcuts disable Composer's process timeout for that invocation, allowing long
transfers. The original `vendor/bin/site-sync` commands remain available.

## Development

From a checkout of this repository:

```sh
php bin/site-sync --help
composer validate --strict
composer test
```

The test suite requires PHP, Composer and rsync. Integration checks use
temporary projects, a fake rsync and real rsync transfers through a local shell
transport. No remote server is contacted. They also install the package through
an offline Composer path repository to exercise the generated bin proxy.

To test a checkout in another project, add a Composer path repository:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "/path/to/site-sync"
    }
  ]
}
```

Then run `composer require --dev iperstudio/site-sync:@dev` from that project.

Remote paths are explicitly quoted for the SSH shell. The child process sets
`RSYNC_OLD_ARGS=1` and `RSYNC_PROTECT_ARGS=0` to keep that behavior consistent
across old and modern rsync, as described in the
[rsync manual](https://download.samba.org/pub/rsync/rsync.1).

## License

MIT. See [LICENSE](LICENSE).
