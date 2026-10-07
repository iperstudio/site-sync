# Site Sync

Interactive PHP CLI for syncing project directories over SSH with rsync. Designed
for Kirby content and accounts, it also works with other projects without depending
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
vendor/bin/site-sync pull production content --dry-run
vendor/bin/site-sync pull production content
```

The setup wizard asks for:

- Production SSH host or IP, SSH user and remote project directory.
- Optional staging server settings.
- Separate local and remote paths for content and accounts.

It previews the configuration before saving `site-sync.php`. Use
`vendor/bin/site-sync init --edit` to update it; existing values become defaults
and replacing the file requires confirmation.

Relative remote project directories, such as `example.com`, are interpreted
relative to the SSH user's home directory. Absolute directories such as
`/home/ploi/example.com` are also accepted. Do not use a `~/` prefix.

Local content directories must already exist. Before a first pull, create the
empty destination directories:

```sh
mkdir -p content site/accounts
```

## Commands

```sh
vendor/bin/site-sync --help
vendor/bin/site-sync init
vendor/bin/site-sync init --edit
vendor/bin/site-sync pull production content
vendor/bin/site-sync pull production accounts
vendor/bin/site-sync push production content
vendor/bin/site-sync push production accounts
vendor/bin/site-sync pull staging content
vendor/bin/site-sync push staging content
```

Add `--dry-run` to any sync command to preview changes without modifying files.
Staging commands require staging to be configured.

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
    ],
];
```

Replace the example host and directory with your server settings. Add other named
paths manually, then use them as the command's final argument.

Paths must be relative subdirectories without `..`. Local paths and symlinks must
resolve inside the project. Environments are production and staging; staging
commands fail if staging is not configured. SSH hosts can be IPv4 addresses or
hostnames. Connections use the default SSH port; IPv6 and per-environment SSH
options are not currently supported.

The config is executable PHP: use trusted configuration files only. No password
or private key is stored. Each team member may keep `site-sync.php` out of Git, or
commit shared server/path settings if appropriate for the project.

## Synchronization behavior

`pull` downloads remote directory contents into the local directory. `push`
uploads local contents into the corresponding remote directory. Both use rsync
archive mode, one filesystem, hard links, progress, itemized changes and `--delete`.
They show the project, source and destination before confirmation.

**Files absent from the source are deleted at the destination.** Preview changes
with `--dry-run` before synchronizing.

- Normal operations require `yes`.
- Production pushes require `yes yes yes`. The prompt deliberately still says
  `yes/no`; answering only `yes` cancels the operation.
- `--dry-run` reports proposed changes and deletions without changing files and
  does not ask for confirmation. It still connects to the remote server.
- Cancelling with an explicit response exits successfully; EOF or a failed
  transfer exits with code 1. Rsync errors are printed live, including its exit code.

## Optional Composer aliases

Define aliases in your project's `composer.json`:

```json
{
  "scripts": {
    "pull-production-content": "site-sync pull production content",
    "push-production-content": "site-sync push production content"
  }
}
```

Then run `composer pull-production-content` or `composer push-production-content`.
Composer puts dependency binaries on PATH when running root scripts. For large
transfers, adjust Composer's process timeout or invoke `vendor/bin/site-sync` directly.

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
