<?php

declare(strict_types=1);

namespace Lvandi\MysqlDiffBackup;

use RuntimeException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class FileManager
{

    /**
     * Base backups directory
     *
     * @var string
     */
    private $backupDir;

    /**
     * Backup Files Repository
     *
     * @var string
     */
    private $backupRepository;

    /**
     * @var string
     */
    private $backupFormat;

    /**
     * Permissions set to saved files
     *
     * @var integer
     */
    private $savePermissions = 0664;
    
    /**
     * Create a new instance of the FileManager helper class
     *
     * @param string $backupDir
     */
    public function __construct(string $backupDir)
    {
        $this->backupDir = rtrim($backupDir, '/');
        $this->backupRepository = $this->backupDir . '/repo';
        $this->backupFormat = date('Y-m-d');
    }

    /**
     * Check that all directories are in place and delete old temp files
     *
     * @param string $baseDir
     * @param string $repoDir
     * @param string $backupFormat
     * @return void
     */
    public function checkRepoStructure() : void
    {

        if (!is_dir($this->backupDir) || !is_writable($this->backupDir)) {
            throw new RuntimeException(
                'The temporary directory you have configured (' . $this->backupDir . ') is either non existant or not writable'
            );
        }

        if (!is_dir($this->backupRepository)) {
            $mr = @mkdir($this->backupRepository, 0755, true);
            if (!$mr) {
                throw new RuntimeException('Cannot create the Repository ' . $this->backupRepository);
            }
        }

        if (!is_writable($this->backupRepository)) {
            throw new RuntimeException('Cannot write to Repository ' . $this->backupRepository);
        }

        if (is_dir($this->backupDir . '/' . $this->backupFormat)) {
            $this->recursiveRemoveDirectory($this->backupDir . '/' . $this->backupFormat);
        }
    }

    /**
     * Check if a database repository exists, if not create a new directory
     *
     * @param string $db
     * @return void
     */
    public function prepareDbRepository(string $db) : void
    {
        $this->dumpDir = $this->backupRepository . '/' . $db;

        if (!is_dir($this->dumpDir)) {
            // @todo throw Exception
            mkdir($this->dumpDir, 0755);
        }
    }

    /**
     * @param string $path
     * @return void
     */
    public function removeDatabaseFilesIfAny($path)
    {
        if (!is_dir($path)) {
            return;
        }

        $this->recursiveRemoveDirectory($path);
    }

    /**
     * Check if the repository version of a table is current
     *
     * @param string $db
     * @param string $table
     * @param string $checksum
     * @param string $engine
     * @return boolean
     */
    public function isRepoVersionCurrent(
        string $db,
        string $table,
        string $checksum,
        string $engine
    ) : bool {
        return is_file(
            $this->backupRepository . '/' . $db . '/' . $table . '.' . $checksum . '.' . strtolower($engine) . '.sql'
        );
    }

    /**
     * Move temporary table-dump files to database repository
     *
     * @param string $temp
     * @param array $table
     * @param string $checksum
     * @return void
     */
    public function moveTempFileToRepo(string $temp, array $table, string $checksum) : void
    {
        chmod($temp, $this->savePermissions);
        rename($temp, $this->dumpDir . '/' . $table['Name'] . '.' . $checksum . '.' . strtolower($table['Engine']) . '.sql');
        // set the file timestamp if supported
        if (!is_null($table['Update_time'])) {
            @touch($this->dumpDir . '/' . $table['Name'] . '.' . $checksum . '.' . strtolower($table['Engine']) . '.sql', strtotime($table['Update_time']));
        }
    }

    /**
     * Delete old table files from repository
     * 
     * @param array $liveDatabases
     * @return array
     */
    public function deleteOldTableDumps(array $liveDatabases) : array
    {
        $deleted = [];
        $dir_handle = @opendir($this->dumpDir) or die("Unable to open $this->dumpDir\n");
        while ($file = readdir($dir_handle)) {
            if ($file != '.' && $file != '..') {
                if (!in_array(substr($file, 0, -4), $liveDatabases)) {
                    // $this->debugMessage('- Found old table - deleting ' . $file);
                    array_push($deleted, $file);
                    unlink($this->dumpDir . '/' . $file);
                }
            }
        }
        @closedir($dir_handle);

        return $deleted;
    }

    /**
     * Delete old Database repository
     * 
     * @param array $liveDatabases
     * @return void
     */
    public function deleteOldDbDumps(array $liveDatabases) : void
    {
        // now we remove any old databases
        $dir_handle = @opendir($this->backupRepository) or die("Unable to open $this->backupRepository");
        
        while ($dir = readdir($dir_handle)) {
            if ($dir != '.' && $dir != '..' && is_dir($this->backupRepository . '/'  . $dir)) {
                if (!isset($liveDatabases[$dir])) {
                    $this->recursiveRemoveDirectory($this->backupRepository . '/' . $dir);
                }
            }
        }
        @closedir($dir_handle);
    }

    /**
     * Build the base header for SQL files
     * 
     * @return string
     */
    public function getBaseHeader($host, $version) : string
    {
        $header = '-- MysqlDiffBackup Dump' . \PHP_EOL;
        $header .= '-- Host: ' . $host . \PHP_EOL;
        $header .= '-- Date: ' . date('F j, Y, g:i a') . \PHP_EOL;
        $header .= '-- -------------------------------------------------' . \PHP_EOL;
        $header .= '-- Server version ' . $version . \PHP_EOL . \PHP_EOL;
        $header .= '/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;' . \PHP_EOL;
        $header .= '/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;' . \PHP_EOL;
        $header .= '/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;' . \PHP_EOL;
        $header .= '/*!40101 SET NAMES utf8 */;' . \PHP_EOL;
        $header .= '/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;' . \PHP_EOL;
        $header .= '/*!40103 SET TIME_ZONE=\'+00:00\' */;' . \PHP_EOL;
        $header .= '/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;' . \PHP_EOL;
        $header .= '/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;' . \PHP_EOL;
        $header .= '/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE=\'NO_AUTO_VALUE_ON_ZERO\' */;' . \PHP_EOL;
        $header .= '/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;' . \PHP_EOL;

        return $header;
    }

    /**
     * Compress backup using tar and rotare files
     * 
     * @param string $tarBinary
     * @param integer $backupsToKeep
     * @return void
     */
    public function compressBackup(string $tarBinary, int $backupsToKeep) : void
    {
        $ret = exec($tarBinary . ' -Jcf ' . $this->backupDir . '/' . $this->backupFormat . '.tar.xz ' . $this->backupDir . '/' . $this->backupFormat . ' > /dev/null');
        $this->rotateFiles($backupsToKeep);
    }

    /**
     * 
     * @param string $db
     * @param string $sql
     * @return void
     */
    public function writeToFile(string $db, string $sql) : void
    {
        $temp_dump_dir = mkdir($this->backupDir . '/' . $this->backupFormat, 0755, true);
        $fp = fopen($this->backupDir . '/' . $this->backupFormat . '/' . $db . '.sql', 'wb');
        fwrite($fp, $sql);
        fclose($fp);
    }

    /**
     * Get a list of database directory from repository
     * 
     * @param string $repository
     * @return array
     */
    public function getDatabaseDirs()
    {
        $dirs = [];

        if (!$repo_dir = opendir($this->backupRepository)) {
            throw new RuntimeException('Unable to open ' . $this->backupRepository);
        }

        while ($dir = readdir($repo_dir)) {
            if ($dir != '.' && $dir != '..' && is_dir($this->backupRepository . '/' . $dir)) {
                array_push($dirs, $dir);
            }
        }

        closedir($repo_dir);
        sort($dirs);

        return $dirs;
    }

    /**
     * Merge repository files to full SQL dump
     * 
     * @param string $db
     * @return void
     */
    public function mergeRepoFilesToFullDump(string $db) : void
    {
        $repo_files = $this->getDatabaseRepoFiles($db);
        $sqlfiles = [];
        $viewfiles = [];

        foreach ($repo_files as $file) {
            if (preg_match('/^([a-zA-Z0-9_\-]+)\.([0-9]+)\-([a-z0-9]+)\.([a-z0-9]+)\.sql/', $file->getFileName(), $sqlmatch)) {
                if ($sqlmatch[4] == 'view') {
                    array_push($viewfiles, $this->backupRepository . '/' . $db . '/' . $file->getFileName());
                } else {
                    array_push($sqlfiles, $this->backupRepository . '/' . $db . '/' . $file->getFileName());
                }
            }
        }

        /* Add all sql dumps in database */
        foreach ($sqlfiles as $f) {
            $this->chunkedCopyTo($f, $this->backupDir . '/' . $this->backupFormat . '/' . $db . '.sql');
        }

        /* Add View tables after */
        foreach ($viewfiles as $f) {
            $this->chunkedCopyTo($f, $this->backupDir . '/' . $this->backupFormat . '/' . $db . '.sql');
        }
    }

    /**
     * Get all sql files from database repository
     * 
     * @param string $db
     * @return RecursiveIteratorIterator
     */
    private function getDatabaseRepoFiles(string $db)
    {
        $dir = new RecursiveDirectoryIterator($this->backupRepository . '/' . $db, RecursiveDirectoryIterator::SKIP_DOTS);
        return new RecursiveIteratorIterator($dir);
    }

    /**
     * Recursive remove directory
     * 
     * @param string $directory
     * @param boolean $empty
     * @return boolean
     */
    private function recursiveRemoveDirectory(string $directory, bool $empty = false) : bool
    {
        if (substr($directory, -1) == '/') {
            $directory = substr($directory, 0, -1);
        }
        if (!file_exists($directory) || !is_dir($directory)) {
            return false;
        } elseif (!is_readable($directory)) {
            return false;
        } else {
            $handle = opendir($directory);
            while (false !== ($item = readdir($handle))) {
                if ($item != '.' && $item != '..') {
                    $path = $directory . '/' . $item;
                    if (is_dir($path)) {
                        $this->recursiveRemoveDirectory($path);
                    } else {
                        unlink($path);
                    }
                }
            }
            closedir($handle);
            if ($empty == false) {
                if (!rmdir($directory)) {
                    return false;
                }
            }
            return true;
        }
    }

    /**
     * Chunk copy files to database backups.
     * To prevent memory overload, fila A is copied 10MB at a time to file B.
     *
     * @param string $from
     * @param string $to
     * @return int number of bytes written
     */
    private function chunkedCopyTo(string $from, string $to) : int
    {
        $buffer_size = 10485760; // 10 megs at a time, you can adjust this.
        $ret = 0;
        $fin = fopen($from, 'rb');
        $fout = fopen($to, 'a');

        if (!$fin || !$fout) {
            throw new RuntimeException('Unable to copy '. $fin . ' to ' . $fout);
        }

        while (!feof($fin)) {
            $ret += fwrite($fout, fread($fin, $buffer_size));
        }

        fclose($fin);
        fclose($fout);

        return $ret;
    }

    /**
     * Rotate Backups in order to keep only $backupsToKeep
     * 
     * @param int $backupsToKeep
     * @return void
     */
    private function rotateFiles(int $backupsToKeep) : void
    {
        if (!$this->recursiveRemoveDirectory($this->backupDir . '/' . $this->backupFormat)) {
        }

        $filelist = [];
        if (!is_dir($this->backupDir)) {
            throw new RuntimeException($this->backupDir . ' is not a directory');
        }

        if ($dh = opendir($this->backupDir)) {
            while (($file = readdir($dh)) !== false) {
                if (($file != '.') && ($file != '..') && (filetype($this->backupDir . '/' . $file) == 'file')) {
                    $filelist[] = $file;
                }
            }
            closedir($dh);
            sort($filelist); // Make sure it's listed in the correct order
            if (count($filelist) > $this->backupsToKeep) {
                $too_many = (count($filelist) - $backupsToKeep);
                for ($j = 0; $j < $too_many; $j++) {
                    unlink($this->backupDir . '/' . $filelist[$j]);
                }
            }
            unset($filelist);
        }
    }
}
