# MysqlDiffBackup

[![Latest Version on Packagist][ico-version]][link-packagist] [![Software License][ico-license]](LICENSE.md) [![Build Status][ico-travis]][link-travis] [![Coverage Status][ico-scrutinizer]][link-scrutinizer] [![Quality Score][ico-code-quality]][link-code-quality] [![Total Downloads][ico-downloads]][link-downloads]

**This package is inspired by [axllent/phpmybackup](https://github.com/axllent/phpmybackup)**, it adds the option to install via composer and include a CLI tool based on the awesome [symfony/console](https://symfony.com/doc/current/components/console.html) component.

## How it works

**MysqlDiffBackup** runs a full backup of MySQL server. For each table it checks if the repository version is actual and backup only changes.

It is possible to include or exclude databases, ignore specific tables of just dump the structure without saving data.

Full options are showed in the [docs](docs) (@todo).

## Install

Via Composer

``` bash
$ composer require lvandi/mysqldiffbackup
```

## Usage

``` php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Lvandi\MysqlDiffBackup\Dumper;
use Lvandi\MysqlDiffBackup\FileManager;

// To enable loggin we create a new instance of Monolog
$logger = new Logger('name');
$logger->pushHandler(new StreamHandler('./log.txt', Logger::WARNING));

// Create an instance of FileManager 
$fm = new FileManager('./backups');

$dumper = new Dumper('mysql_host', 'mysql_username', 'mysql_password', $fm);
$dumper->setDebug(true)
    ->setLogger($logger)
    ->setBackupsToKeep(7)
    ->setEmptyTables(['mydb.tableToEmpty']);

$dumper->dumpDatabases();
```

## Structure

Set the backup path to `/backups` will lead to the following directory structure:

```text
backups
│
└───repo (base repository)
│   │
│   └───database_name (invidivual database repository)
│       │   table_name_1.sql (individual sql file for each table)
│       │   table_name_2.sql
│       │   ...
│
└───2021-01-02 (temp directory for full SQL dumps - it gets deleted as soon be compressed)
│   │
│   └───database_name (temp directory for invidivual full SQL dump)
│       │   table_name_1.sql (temp individual full SQL dump)
│       │   
│   2021-01-02.tar.xz (compressed dumps)
│   2021-01-01.tar.xz
```

## Change log

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Testing

``` bash
$ composer test
```

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) and [CODE_OF_CONDUCT](CODE_OF_CONDUCT.md) for details.

## Security

If you discover any security related issues, please email vandi.luciano@gmail.com instead of using the issue tracker.

## Credits

- [Luciano][link-author]
- [All Contributors][link-contributors]

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

[ico-version]: https://img.shields.io/packagist/v/lvandi/MysqlDiffBackup.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[ico-travis]: https://img.shields.io/travis/lvandi/MysqlDiffBackup/master.svg?style=flat-square
[ico-scrutinizer]: https://img.shields.io/scrutinizer/coverage/g/lvandi/MysqlDiffBackup.svg?style=flat-square
[ico-code-quality]: https://img.shields.io/scrutinizer/g/lvandi/MysqlDiffBackup.svg?style=flat-square
[ico-downloads]: https://img.shields.io/packagist/dt/lvandi/MysqlDiffBackup.svg?style=flat-square

[link-packagist]: https://packagist.org/packages/lvandi/MysqlDiffBackup
[link-travis]: https://travis-ci.org/lvandi/MysqlDiffBackup
[link-scrutinizer]: https://scrutinizer-ci.com/g/lvandi/MysqlDiffBackup/code-structure
[link-code-quality]: https://scrutinizer-ci.com/g/lvandi/MysqlDiffBackup
[link-downloads]: https://packagist.org/packages/lvandi/MysqlDiffBackup
[link-author]: https://github.com/lucianovandi
[link-contributors]: ../../contributors
