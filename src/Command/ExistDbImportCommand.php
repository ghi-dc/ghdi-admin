<?php

// src/Command/ExistDbImportCommand.php
namespace App\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

use function Symfony\Component\String\u;

class ExistDbImportCommand
extends ExistDbCommand
{
    protected function configure()
    {
        $this
            ->setName('existdb:import')
            ->addArgument(
                'collection',
                InputArgument::REQUIRED,
                'What collection do you want to import'
            )
            ->addArgument(
                'resource',
                InputArgument::OPTIONAL,
                'What resource do you want to import'
            )
            ->addOption(
                'overwrite',
                null,
                InputOption::VALUE_NONE,
                'Specify to overwrite existing resources'
            )
            ->setDescription('Import collection (base|volumes|persons|organization|places|terms|styles|assets) into app.existdb.base')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $existDbClient = $this->getExistDbClient();

        $existDbBase = $existDbClient->getCollection();
        $collection = $input->getArgument('collection');

        $overwrite = $input->getOption('overwrite');

        if ('base' == $collection) {
            if (!$existDbClient->existsAndCanOpenCollection()) {
                $res = $existDbClient->createCollection();

                $output->writeln(sprintf('<info>Base-Collection was created (%s)</info>',
                                         $existDbBase));

                return 0;
            }

            $output->writeln(sprintf('<info>Base-Collection already exists (%s)</info>',
                                     $existDbBase));

            return 0;
        }

        if (!$existDbClient->existsAndCanOpenCollection()) {
            $output->writeln(sprintf('<error>Base-Collection does not exist or cannot be opened (%s)</error>',
                                     $existDbBase));

            return -3;
        }

        $resource = $input->getArgument('resource');

        switch ($collection) {
            case 'volumes':
                return (int) $this->importVolume($output, $resource);
                break;

            case 'styles':
                return (int) $this->importStyles($output, $resource, $overwrite);
                break;

            case 'assets':
                return (int) $this->importAssets($output, $resource, $overwrite);
                break;

            case 'persons':
            case 'organizations':
            case 'places':
            case 'terms':
                $filename = !empty($resource) ? $resource :  '';
                break;

            default:
                $output->writeln(sprintf('<error>Invalid collection (%s)</error>',
                                         $collection));

                return -1;
        }

        $filenameFull = $this->projectDir
            . '/data/authority/' . $collection . '/' . $filename;
        if ('' !== $filename && !file_exists($filenameFull)) {
            $output->writeln(sprintf('<error>File does not exist (%s)</error>',
                                     $filenameFull));

            return -3;
        }

        return (int) $this->checkCollectionAndStore($output, $existDbClient, $existDbBase . '/data/authority/' . $collection, $filename, $filenameFull, $overwrite);
    }

    protected function checkCollectionAndStore(OutputInterface $output, $existDbClient, $subCollection,
                                               $resource, $filenameFull, $overwrite, $createCollection = true, $isBinary = false)
    {
        $existDbClient->setCollection($subCollection);
        if (!$existDbClient->existsAndCanOpenCollection()) {
            if (!$createCollection) {
                $output->writeln(sprintf('<error>Collection does not exist or cannot be opened (%s)</error>',
                                         $subCollection));

                return -3;
            }

            $res = $existDbClient->createCollection();
            if (!$existDbClient->existsAndCanOpenCollection()) {
                $output->writeln(sprintf('<error>Collection could not be created or cannot be opened (%s)</error>',
                                         $subCollection));

                return -3;
            }
        }

        if (u($filenameFull)->endsWith('/')) {
            return 0;
        }

        if ($existDbClient->hasDocument($resource) && !$overwrite) {
            $output->writeln(sprintf('<info>Resource already exists (%s)</info>',
                                     $subCollection . '/' . $resource));

            return 0;
        }

        if ($isBinary) {
            $mimeType = mime_content_type($filenameFull);

            $res = $existDbClient->storeBinary(file_get_contents($filenameFull), $resource, $mimeType, $overwrite);
        }
        else {
            $res = $existDbClient->storeDocument(file_get_contents($filenameFull), $resource, $overwrite);
        }

        if (!$res) {
            $output->writeln(sprintf('<info>Error adding %s</info>',
                                     $subCollection . '/' . $resource));

            return -4;
        }

        $output->writeln(sprintf('<info>Resource added (%s)</info>',
                                 $subCollection . '/' . $resource));

        return 0;
    }

    protected function importStyles(OutputInterface $output, $resource, $overwrite = false)
    {
        $collection = 'styles';

        $inputDir = $this->projectDir
            . '/data/' . $collection;

        if (empty($resource)) {
            $res = null;
            foreach (glob($inputDir . '/*.xsl') as $filenameFull) {
                $resource = basename($filenameFull);
                if (!empty($resource)) {
                    $subRes = $this->importStyles($output, $resource, $overwrite);
                    if ($subRes != 0) {
                        return $subRes;
                    }

                    $res = 0;
                }
            }

            if (is_null($res)) {
                $output->writeln(sprintf('<error>No xsl-files found (%s)</error>',
                                         $inputDir));

                return -6;
            }

            return $res;
        }

        $filenameFull = $inputDir . '/' . $resource;
        if (!file_exists($filenameFull)) {
            $output->writeln(sprintf('<error>File does not exist (%s)</error>',
                                     $filenameFull));

            return -3;
        }

        $existDbClient = $this->getExistDbClient();
        $existDbBase = $existDbClient->getCollection();

        return $this->checkCollectionAndStore($output, $existDbClient,
                                              $subCollection = $existDbBase . '/' . $collection,
                                              $resource, $filenameFull, $overwrite);
    }

    protected function importAssets(OutputInterface $output, $resource, $overwrite = false)
    {
        $collection = 'assets';

        $inputDir = $this->projectDir
            . '/data/' . $collection;

        if (empty($resource)) {
            $output->writeln(sprintf('<error>Please pass the name of the resource</error>',
                                     $inputDir));

            return -6;
        }

        $filenameFull = $inputDir . '/' . $resource;
        if (!file_exists($filenameFull)) {
            $output->writeln(sprintf('<error>File does not exist (%s)</error>',
                                     $filenameFull));

            return -3;
        }

        $existDbClient = $this->getExistDbClient();
        $existDbBase = $existDbClient->getCollection();

        // TODO: pass $isBinary depending of the type of the actual file
        return $this->checkCollectionAndStore($output, $existDbClient,
                                              $subCollection = $existDbBase . '/' . $collection,
                                              $resource, $filenameFull, $overwrite, true, $isBinary = true);
    }

    protected function importVolume(OutputInterface $output, $resource)
    {
        if (empty($resource)) {
            $output->writeln(sprintf('<error>Resource not specified</error>'));

            return -3;
        }

        $filenameFull = $this->projectDir
            . '/data/tei/' . $resource;
        if (!file_exists($filenameFull)) {
            $output->writeln(sprintf('<error>File does not exist (%s)</error>',
                                     $filenameFull));

            return -3;
        }

        $teiHelper = new \App\Utils\TeiHelper();

        $article = $teiHelper->analyzeHeader($filenameFull);

        if (false === $article) {
            $output->writeln(sprintf('<error>%s could not be loaded</error>', $filenameFull));
            foreach ($teiHelper->getErrors() as $error) {
                $output->writeln(sprintf('<error>  %s</error>', trim($error->message)));
            }

            return -2;
        }

        if (empty($article->genre) || empty($article->uid)) {
            $output->writeln(sprintf('<error>DTAID or classCode for genre missing (%s)</error>',
                                     $resource));

            return -1;
        }

        if (empty($article->language) || !in_array($article->language, [ 'eng', 'deu' ])) {
            $output->writeln(sprintf('<error>Invalid language %s (%s)</error>',
                                     $resource));

            return -1;
        }

        $dtaidStem = in_array($article->genre, [ 'document-collection', 'image-collection' ])
            ? 'chapter' : $article->genre;

        $reDtaid = sprintf('/^%s:%s\-\d+$/',
                           $this->siteKey, $dtaidStem);
        if (!preg_match($reDtaid, $article->uid)) {
            $output->writeln(sprintf('<error>DTAID %s does not match the pattern %s</error>',
                                     $article->uid, $reDtaid));

            return -1;
        }

        $articleUidLocal = preg_replace(sprintf('/^%s:/', $this->siteKey), '', $article->uid);

        $resourceNameExpected = sprintf('%s.%s.xml',
                                        $articleUidLocal,
                                        $article->language);
        if ($resourceNameExpected != $resource) {
            $output->writeln(sprintf('<error>resource %s does not match the expected value %s</error>',
                                     $resource, $resourceNameExpected));

            return -1;
        }

        $existDbClient = $this->getExistDbClient();
        $existDbBase = $existDbClient->getCollection();

        $overwrite = false; // TODO: get from options

        switch ($article->genre) {
            case 'volume':
                $collection = $articleUidLocal;

                return $this->checkCollectionAndStore($output, $existDbClient, $subCollection = $existDbBase . '/data/volumes/' . $collection, $resource, $filenameFull, $overwrite);
                break;

            case 'document-collection':
            case 'image-collection':
            case 'introduction':
            case 'document':
            case 'image':
            case 'map':
                $parts = explode('/', $article->shelfmark);
                if ($this->siteKey != $parts[0] || !preg_match('/^\d+\:(volume\-\d+)$/', $parts[1], $matches)) {
                    $output->writeln(sprintf('<error>Could not determine volume from shelfmark %s/error>',
                                             $article->shelfmark));
                    return -1;
                }

                $collection = $matches[1];

                return $this->checkCollectionAndStore($output, $existDbClient, $subCollection = $existDbBase . '/data/volumes/' . $collection, $resource, $filenameFull, $overwrite);
                break;

            default:
                $output->writeln(sprintf('<error>Not handling genre %s</error>',
                                         $article->genre));

                return -1;
        }
    }
}
