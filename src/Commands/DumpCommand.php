<?php

namespace Lvandi\MysqlDiffBackup\Commands;

use Monolog\Logger;
use Lvandi\MysqlDiffBackup\Dumper;
use Monolog\Handler\StreamHandler;
use Lvandi\MysqlDiffBackup\FileManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DumpCommand extends Command
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'app:create-user';

    protected function configure()
    {
        $this->addOption(
            'host', 
            null, 
            InputOption::VALUE_REQUIRED, 
            'Database hostname', 
            \getenv('MYSQL_HOST')
        )->addOption(
            'username', 
            'u', 
            InputOption::VALUE_REQUIRED, 
            'Database username', 
            \getenv('MYSQL_USER')
        )->addOption(
            'password', 
            'p', 
            InputOption::VALUE_REQUIRED, 
            'Database password', 
            \getenv('MYSQL_PASS')
        )->addOption(
            'debug', 
            'd', 
            InputOption::VALUE_NONE, 
            'Show debug messages'
        )->addOption(
            'keep', 
            'k', 
            InputOption::VALUE_REQUIRED, 
            'Number of backups to keep', 
            Dumper::RETENTION
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if(!$input->getOption('host')){
            $output->writeln('No host selected');
            return 1;
        }

        $logger = new Logger('MysqlDiffBackup');
        $logger->pushHandler(new StreamHandler('./log.txt', Logger::WARNING));
        
        $fm = new FileManager('./backups/mysql');

        $dumper = new Dumper(
            $input->getOption('host'), 
            $input->getOption('username'), 
            $input->getOption('password'), 
            $fm
        );

        if($input->getOption('debug')){
            $dumper->setDebug(true);
        }

        $dumper->setLogger($logger)
            ->setBackupsToKeep($input->getOption('keep'));

        $dumper->dumpDatabases();

        return 0;
    }
}