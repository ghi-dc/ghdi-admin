<?php

// src/Command/ExistDbIndexCommand.php
namespace App\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

class ExistDbIndexCommand
extends ExistDbCommand
{
    protected function configure()
    {
        $this
            ->setName('existdb:index')
            ->addArgument(
                'collection',
                InputArgument::REQUIRED,
                'What collection (volumes|persons|organization|places|terms) do you want to index'
            )
            ->setDescription('Import collection.xconf and re-index')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $existDbClient = $this->getExistDbClient();
        $existDbBase = $existDbClient->getCollection();
        if (!$existDbClient->existsAndCanOpenCollection()) {
            $output->writeln(sprintf('<error>Base-Collection does not exist or cannot be opened (%s)</error>',
                                     $existDbBase));

            return -3;
        }

        switch ($collection = $input->getArgument('collection')) {
            case 'volumes':
                $filenameFull = $this->getContainer()->get('kernel')->getProjectDir()
                    . '/data/tei/collection.xconf';
                $subCollection = $existDbBase . '/data/' . $collection;
                break;

            case 'persons':
            case 'organizations':
            case 'places':
            case 'terms':
                $filenameFull = $this->getContainer()->get('kernel')->getProjectDir()
                    . '/data/authority/' . $collection . '/collection.xconf';
                $subCollection = $existDbBase . '/data/authority/' . $collection;
                break;

            default:
                $output->writeln(sprintf('<error>Invalid collection (%s)</error>',
                                         $action));
                return -1;
        }

        if (!file_exists($filenameFull)) {
            $output->writeln(sprintf('<error>File does not exist (%s)</error>',
                                     $filenameFull));
            return -3;
        }


        $configCollection = '/db/system/config' . $subCollection;
        $configFull = $configCollection . '/collection.xconf';

        $overwrite = false; // TODO: get from options
        if ($existDbClient->hasDocument($configFull) && !$overwrite) {
            $output->writeln(sprintf('<info>Config already exists (%s)</info>',
                                     $configFull));

            return 0;
        }

        // create collection if needed
        if (!$existDbClient->existsAndCanOpenCollection($configCollection)) {
            $res = $existDbClient->createCollection($configCollection);
            if (!$existDbClient->existsAndCanOpenCollection($configCollection)) {
                $output->writeln(sprintf('<error>System Collection could not be created or cannot be opened (%s)</error>',
                                         $configCollection));
                return -3;
            }
        }

        $res = $existDbClient->storeDocument(file_get_contents($filenameFull), $configFull, $overwrite);
        if (!$res) {
            $output->writeln(sprintf('<error>Error adding %s</error>',
                                     $configFull));
            return -4;
        }

        $output->writeln(sprintf('<info>Config added (%s)</info>',
                                 $configFull));

        if (!$existDbClient->reindexCollection($subCollection)) {
            $output->writeln(sprintf('<error>Error re-indexing %s</error>',
                                     $subCollection));
            return -5;
        }

        $output->writeln(sprintf('<info>Re-indexed %s</info>',
                                 $subCollection));
    }
}
