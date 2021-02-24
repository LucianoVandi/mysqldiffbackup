<?php

declare(strict_types=1);

namespace Lvandi\MysqlDiffBackup;

use PDO;
use PDOException;
use RuntimeException;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerAwareInterface;

class Dumper implements LoggerAwareInterface
{
    const RETENTION = 7;
    
    /**
     * @var FileManager
     */
    private $filemanager;

    /**
     * @var PDO
     */
    private $pdo;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Enable debug messages
     *
     * @var boolean
     */
    private $showDebug = false;

    /**
     * MySQL host
     *
     * @var string
     */
    private $dbHost = '';

    /**
     * MySQL username
     *
     * @var string
     */
    private $dbUser = '';

    /**
     * MySQL password
     *
     * @var string
     */
    private $dbPass = '';

    /**
     * Array of database names to back up (supports wildcards)
     * Defaults to all. Eg: ['database1', test*']
     *
     * @var array
     */
    private $includeDatabases = ['*'];

    /**
     * Array of database names to ignore (supports wildcards)
     * Completely ignores database and all it's tables. Eg: ['database1', test*']
     *
     * @var array
     */
    private $ignoreDatabases = [];

    /**
     * Array of complete tables to ignore (includes database names - supports wildcards)
     * Format must include database "table.database". Eg: ['database1.mytable', 'test.ignore*']
     *
     * @var array
     */
    private $ignoreTables = [];

    /**
     * Array of tables to ignore data (includes database names - supports wildcards)
     * Table structure will be backed up, but no data. Format must include database "table.database"
     * Eg: ['database1.mytable', 'test.ignore*']
     *
     * @var array
     */
    private $emptyTables = [];

    /**
     * Number of backups copy to keep.
     *
     * @var integer
     */
    private $backupsToKeep = 180;

    /**
     * Timezone used for dumps
     *
     * @var string
     */
    private $timezone = 'Europe/Rome';

    /**
     * @var boolean
     */
    private $createDatabase = true;

    /**
     * Base header for sql files
     *
     * @var string
     */
    private $header = '';

    /**
     * Path of the tar binary, used for compression.
     *
     * @var string
     */
    private $tarBinary;

    /**
     * Path of the mysqldump binary
     *
     * @var string
     */
    private $mysqldumpBinary;

    /**
     * Create a new instance of Dumper
     * 
     * @param string $dbHost
     * @param string $dbUser
     * @param string $dbPass
     * @param FileManager $fileManager
     */
    public function __construct(string $dbHost, string $dbUser, string $dbPass, FileManager $fileManager)
    {
        $this->dbHost = $dbHost;
        $this->dbUser = $dbUser;
        $this->dbPass = $dbPass;
        $this->filemanager = $fileManager;
        $this->checkMysqlDumpIsInstalled();
        date_default_timezone_set($this->timezone);
    }

    /**
     * Dump databases, check tables versions, compress and rotate backups
     * 
     * @return void
     */
    public function dumpDatabases() : void
    {
        // Set defaults for ignored dbs and empty tables
        array_push($this->ignoreDatabases, 'information_schema');
        array_push($this->emptyTables, 'mysql.general_log');
        array_push($this->emptyTables, 'mysql.slow_log');

        $this->connectDatabase();
        $this->filemanager->checkRepoStructure();
        
        $version = $this->runDatabaseQuery('SELECT VERSION()')->fetch();
        $this->header = $this->filemanager->getBaseHeader($this->dbHost, $version['VERSION()']);

        // Get all databases from server and iterate each db
        $dbs = $this->runDatabaseQuery('SHOW DATABASES')->fetchAll();
        foreach ($dbs as $row) {
            // Check for database by "inclusion" strategy
            if (!$this->matchRegEx($this->includeDatabases, $row['Database'])) {
                $this->debugMessage('- Database not included in backup ' . $row['Database']);
                continue;
            }

            // Check for database by "exclusion" strategy
            if ($this->matchRegEx($this->ignoreDatabases, $row['Database'])) {
                $this->debugMessage('- Ignoring database ' . $row['Database'] .' and remove existing copy if any.');
                $this->filemanager->removeDatabaseFilesIfAny($row['Database']);
            } else {
                $this->liveDatabases[$row['Database']] = [];
                $this->syncTables($row['Database']);
            }
        }

        // now we remove any old databases => @todo
        $this->filemanager->deleteOldDbDumps($this->liveDatabases);

        $dirs = $this->filemanager->getDatabaseDirs();
        foreach ($dirs as $db) {
            $sql = $this->header;

            if ($this->createDatabase) {
                $sql .= '/*!40000 DROP DATABASE IF EXISTS `' . $db . '`*/; ' . \PHP_EOL;
                $sql .= 'CREATE DATABASE `' . $db . '`;' . \PHP_EOL . \PHP_EOL;
                $sql .= 'USE `' . $db . '`;' . \PHP_EOL . \PHP_EOL;
            }

            $this->filemanager->writeToFile($db, $sql);
            $this->filemanager->mergeRepoFilesToFullDump($db);
        }

        $this->debugMessage('Compressing backups. It can take a while, please hold on.');
        $this->filemanager->compressBackup($this->tarBinary, $this->backupsToKeep);
    }

    /**
     * Sync tables
     * @param string $db
     * @return mixed
     */
    private function syncTables(string $db)
    {
        $this->filemanager->prepareDbRepository($db);

        $this->runDatabaseQuery("use `$db`");
        $tbls = $this->runDatabaseQuery('SHOW TABLE STATUS FROM `' . $db . '`');

        if (!$tbls->rowCount()) {
            $this->debugMessage('No tables found for db `' . $db . '`');
            return;
        }

        foreach ($tbls->fetchAll() as $row) {
            $tblName = $row['Name'];
            $checksum_row = $this->runDatabaseQuery('CHECKSUM TABLE `' . $tblName . '`')->fetch();
            $tblChecksum = $checksum_row['Checksum'];

            if (is_null($tblChecksum) || $this->matchRegEx($this->emptyTables, $db . '.' . $tblName)) {
                $tblChecksum = 0;
            }

            $create_sql = $this->runDatabaseQuery('SHOW CREATE TABLE `' . $tblName . '`')->fetch();
            $create_stmt = $create_sql['Create View'] ?? $create_sql['Create Table'];
            
            // @todo: metodo getChecksum?
            $tblChecksum .= '-' . substr(base_convert(md5($create_stmt), 16, 32), 0, 12);

            if ($row['Engine'] == null) {
                $row['Engine'] = 'View';
            }

            // Check if we are ignoring current table
            if ($this->matchRegEx($this->ignoreTables, $db . '.' . $tblName)) {
                $this->debugMessage('- Ignoring table ' . $db . '.' . $tblName);
                continue;
            }

            if ($this->filemanager->isRepoVersionCurrent($db, $tblName, $tblChecksum, $row['Engine'])) {
                $this->debugMessage('- Repo version of ' . $db . '.' . $tblName . ' is current (' . $row['Engine'] . ')');
                array_push($this->liveDatabases[$db], $tblName . '.' . $tblChecksum . '.' . strtolower($row['Engine']));
                continue;
            }

            array_push($this->liveDatabases[$db], $tblName . '.' . $tblChecksum . '.' . strtolower($row['Engine'])); // For later check & delete of missing ones
            $this->debugMessage('+ Backing up new version of ' . $db . '.' . $tblName . ' (' . $row['Engine'] . ')');

            $dump_options = [
                '-C', // compress connection
                '-h' . $this->dbHost, // host
                '-u' . $this->dbUser, // user
                '--compact', // no need for database info for every table
            ];

            if ($this->hex4blob) {
                array_push($dump_options, '--hex-blob');
            }

            if (!$this->dropTables) {
                array_push($dump_options, '--skip-add-drop-table');
            }

            if (strtolower($row['Engine']) == 'csv') {
                $this->debugMessage('- Skipping table locks for CSV table ' . $db . '.' . $tblName);
                array_push($dump_options, '--skip-lock-tables');
            }

            
            if ($this->matchRegEx($this->emptyTables, $db . '.' . $tblName)) {
                $this->debugMessage('- Ignoring data for ' . $db . '.' . $tblName);
                array_push($dump_options, '--no-data');
            } elseif (strtolower($row['Engine']) == 'memory') {
                $this->debugMessage('- Ignoring data for Memory table ' . $db . '.' . $tblName);
                array_push($dump_options, '--no-data');
            } elseif (strtolower($row['Engine']) == 'view') {
                $this->debugMessage('- Ignoring data for View table ' . $db . '.' . $tblName);
                array_push($dump_options, '--no-data');
            }

            // @todo remove old temp files
            $temp = tempnam(sys_get_temp_dir(), 'sqlbackup-');
            putenv('MYSQL_PWD=' . $this->dbPass);

            $exec = passthru($this->mysqldumpBinary . ' ' . implode(' ', $dump_options) . ' ' . $db . ' ' . $tblName . ' > ' . $temp);
            if ($exec != '') {
                @unlink($temp);
                $this->errorMessage('Unable to dump file to ' . $temp . ' ' . $exec);
            } else {
                // make sure only complete files get saved
                $this->filemanager->moveTempFileToRepo($temp, $row, $tblChecksum);
            }
        }

        // delete old tables if existing
        $this->filemanager->deleteOldTableDumps($this->liveDatabases[$db]);
    }

    /**
     * @inheritDoc
     *
     * @param \Psr\Log\LoggerInterface $logger
     *
     * @return self
     */
    public function setLogger(LoggerInterface $logger) : self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * Get status of debug
     * 
     * @return boolean
     */
    public function getDebug() : bool
    {
        return $this->showDebug;
    }

    /**
     * Enable or disable debug messages
     * 
     * @param boolean $showDebug
     *
     * @return self
     */
    public function setDebug(bool $showDebug) : self
    {
        $this->showDebug = $showDebug;

        return $this;
    }

    /**
     * Get mySQL host
     *
     * @return  string
     */
    public function getDbHost()
    {
        return $this->dbHost;
    }

    /**
     * Get mySQL username
     *
     * @return  string
     */
    public function getDbUser()
    {
        return $this->dbUser;
    }

    /**
     * Get mySQL password
     *
     * @return  string
     */
    public function getDbPass()
    {
        return $this->dbPass;
    }

    /**
     * Get defaults to all. Eg: ['database1', test*']
     *
     * @return  array
     */
    public function getIncludeDatabases() : array
    {
        return $this->includeDatabases;
    }

    /**
     * Set defaults to all. Eg: ['database1', test*']
     *
     * @param  array  $includeDatabases  Defaults to all. Eg: ['database1', test*']
     *
     * @return  self
     */
    public function setIncludeDatabases(array $includeDatabases) : self
    {
        $this->includeDatabases = $includeDatabases;

        return $this;
    }

    /**
     * Get completely ignores database and all it's tables. Eg: ['database1', test*']
     *
     * @return  array
     */
    public function getIgnoreDatabases() : array
    {
        return $this->ignoreDatabases;
    }

    /**
     * Set completely ignores database and all it's tables. Eg: ['database1', test*']
     *
     * @param  array  $ignoreDatabases  Completely ignores database and all it's tables. Eg: ['database1', test*']
     *
     * @return  self
     */
    public function setIgnoreDatabases(array $ignoreDatabases) : self
    {
        $this->ignoreDatabases = $ignoreDatabases;

        return $this;
    }

    /**
     * Get format must include database "table.database". Eg: ['database1.mytable', 'test.ignore*']
     *
     * @return  array
     */
    public function getIgnoreTables() : array
    {
        return $this->ignoreTables;
    }

    /**
     * Set format must include database "table.database". Eg: ['database1.mytable', 'test.ignore*']
     *
     * @param  array  $ignoreTables  Format must include database "table.database". Eg: ['database1.mytable', 'test.ignore*']
     *
     * @return  self
     */
    public function setIgnoreTables(array $ignoreTables) : self
    {
        $this->ignoreTables = $ignoreTables;

        return $this;
    }

    /**
     * Get eg: ['database1.mytable', 'test.ignore*']
     *
     * @return  array
     */
    public function getEmptyTables() : array
    {
        return $this->emptyTables;
    }

    /**
     * Set eg: ['database1.mytable', 'test.ignore*']
     *
     * @param array $emptyTables
     *
     * @return self
     */
    public function setEmptyTables(array $emptyTables) : self
    {
        $this->emptyTables = $emptyTables;

        return $this;
    }

    /**
     * Get number of daily backups to keep.
     *
     * @return  integer
     */
    public function getBackupsToKeep() : int
    {
        return $this->backupsToKeep;
    }

    /**
     * Set number of daily backups to keep.
     *
     * @param integer $backupsToKeep
     *
     * @return  self
     */
    public function setBackupsToKeep($backupsToKeep) : self
    {
        $this->backupsToKeep = $backupsToKeep;

        return $this;
    }

    /**
     * Get timezone used for dumps
     *
     * @return  string
     */
    public function getTimezone() : string
    {
        return $this->timezone;
    }

    /**
     * Set timezone used for dumps
     *
     * @param  string  $timezone  Timezone used for dumps
     *
     * @return  self
     */
    public function setTimezone(string $timezone) : self
    {
        $this->timezone = $timezone;
        date_default_timezone_set($this->timezone);

        return $this;
    }

    /**
     * Get the value of createDatabase
     *
     * @return  boolean
     */
    public function getCreateDatabase()
    {
        return $this->createDatabase;
    }

    /**
     * Set the value of createDatabase
     *
     * @param  boolean  $createDatabase
     *
     * @return  self
     */
    public function setCreateDatabase(bool $createDatabase)
    {
        $this->createDatabase = $createDatabase;

        return $this;
    }

    /**
     * Create a PDO Connection to db host
     *
     * @throws PDOException
     * @return void
     */
    private function connectDatabase()
    {
        $options = [
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
        ];

        $dsn = "mysql:host=$this->dbHost;charset=utf8mb4";
        $this->pdo = new PDO($dsn, $this->dbUser, $this->dbPass, $options);
    }

    /**
     * Run database query using PDO.
     * If args array is not empty it runs a prepared statement.
     *
     * @param string $sql
     * @param array $args
     * @return \PDOStatement|bool
     */
    private function runDatabaseQuery(string $sql, array $args = null)
    {
        if (!$args) {
            return $this->pdo->query($sql);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);

        return $stmt;
    }

    /**
     * Check if mysqldump is installed. If so, save the path of bin location.
     *
     * @return void
     */
    private function checkMysqlDumpIsInstalled() : void
    {
        $output['mysqldumpBinary'] = exec('which mysqldump');
        $output['tarBinary'] = exec('which tar');

        foreach ($output as $key => $value) {
            if (strlen($value)) {
                $this->{$key} = $value;
                continue;
            }

            throw new RuntimeException("$key is not installed on your system.");
        }
    }

    /**
     * Write a debug message. If running by CLI also write to sdtout
     *
     * @param string $message
     *
     * @return void
     */
    private function debugMessage(string $message) : void
    {
        if (!$this->showDebug) {
            return;
        }

        if ($this->isCli()) {
            echo "\e[32m" .  $message . \PHP_EOL;
        }

        if (!\is_null($this->logger)) {
            $this->logger->debug($message);
        }
    }

    /**
     * Write an error message. If it runs by cli also write to stdout.
     *
     * @param string $message
     *
     * @return void
     */
    private function errorMessage(string $message) : void
    {
        if ($this->isCli()) {
            echo "\e[31m" .  $message . \PHP_EOL;
        }

        if (!\is_null($this->logger)) {
            $this->logger->error($message);
        }
    }

    /**
     * Build and test a regex from an array
     *
     * @param array $source
     * @param string $test
     * @return int
     */
    private function matchRegEx(array $source, string $test) : int
    {
        $tmp = [];

        foreach ($source as $row) {
            array_push(
                $tmp,
                str_replace(
                    '_STARREDMATCH_',
                    '(.*)',
                    preg_quote(
                        str_replace('*', '_STARREDMATCH_', $row),
                        '/'
                    )
                )
            );
        }

        $exp = '(' . implode('|', $tmp) . ')';

        return preg_match('/^' . $exp . '$/', $test);
    }

    /**
     * Check if is running from CLI
     *
     * @return boolean
     */
    private function isCli() : bool
    {
        return \php_sapi_name() == 'cli';
    }

}
