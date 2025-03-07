<?php

// src/Command/ExistDbImportCommand.php

namespace App\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Symfony\Component\String\u;

/**
 * Implement
 *  existdb:import
 * for pre-populationg existdb.
 */
class ExistDbImportCommand extends ExistDbCommand
{
    protected function configure(): void
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

                $output->writeln(sprintf(
                    '<info>Base-Collection was created (%s)</info>',
                    $existDbBase
                ));

                return 0;
            }

            $output->writeln(sprintf(
                '<info>Base-Collection already exists (%s)</info>',
                $existDbBase
            ));

            return 0;
        }

        if (!$existDbClient->existsAndCanOpenCollection()) {
            $output->writeln(sprintf(
                '<error>Base-Collection does not exist or cannot be opened (%s)</error>',
                $existDbBase
            ));

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
                $filename = !empty($resource) ? $resource : '';
                break;

            default:
                $output->writeln(sprintf(
                    '<error>Invalid collection (%s)</error>',
                    $collection
                ));

                return -1;
        }

        $filenameFull = $this->projectDir . '/data/authority/' . $collection . '/' . $filename;
        if ('' !== $filename && !file_exists($filenameFull)) {
            $output->writeln(sprintf(
                '<error>File does not exist (%s)</error>',
                $filenameFull
            ));

            return -3;
        }

        return (int) $this->checkCollectionAndStore($output, $existDbClient, $existDbBase . '/data/authority/' . $collection, $filename, $filenameFull, $overwrite);
    }

    protected function checkCollectionAndStore(
        OutputInterface $output,
        $existDbClient,
        $subCollection,
        $resource,
        $filenameFull,
        $overwrite,
        $createCollection = true,
        $isBinary = false
    ) {
        $existDbClient->setCollection($subCollection);
        if (!$existDbClient->existsAndCanOpenCollection()) {
            if (!$createCollection) {
                $output->writeln(sprintf(
                    '<error>Collection does not exist or cannot be opened (%s)</error>',
                    $subCollection
                ));

                return -3;
            }

            $res = $existDbClient->createCollection();
            if (!$existDbClient->existsAndCanOpenCollection()) {
                $output->writeln(sprintf(
                    '<error>Collection could not be created or cannot be opened (%s)</error>',
                    $subCollection
                ));

                return -3;
            }
        }

        if (u($filenameFull)->endsWith('/')) {
            return 0;
        }

        if ($existDbClient->hasDocument($resource) && !$overwrite) {
            $output->writeln(sprintf(
                '<info>Resource already exists (%s)</info>',
                $subCollection . '/' . $resource
            ));

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
            $output->writeln(sprintf(
                '<info>Error adding %s</info>',
                $subCollection . '/' . $resource
            ));

            return -4;
        }

        $output->writeln(sprintf(
            '<info>Resource added (%s)</info>',
            $subCollection . '/' . $resource
        ));

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
                    if (0 != $subRes) {
                        return $subRes;
                    }

                    $res = 0;
                }
            }

            if (is_null($res)) {
                $output->writeln(sprintf(
                    '<error>No xsl-files found (%s)</error>',
                    $inputDir
                ));

                return -6;
            }

            return $res;
        }

        $filenameFull = $inputDir . '/' . $resource;
        if (!file_exists($filenameFull)) {
            $output->writeln(sprintf(
                '<error>File does not exist (%s)</error>',
                $filenameFull
            ));

            return -3;
        }

        $existDbClient = $this->getExistDbClient();
        $existDbBase = $existDbClient->getCollection();

        return $this->checkCollectionAndStore(
            $output,
            $existDbClient,
            $subCollection = $existDbBase . '/' . $collection,
            $resource,
            $filenameFull,
            $overwrite
        );
    }

    protected function importAssets(OutputInterface $output, $resource, $overwrite = false)
    {
        $collection = 'assets';

        $inputDir = $this->projectDir
            . '/data/' . $collection;

        if (empty($resource)) {
            $output->writeln(sprintf(
                '<error>Please pass the name of the resource</error>'
            ));

            return -6;
        }

        $filenameFull = $inputDir . '/' . $resource;
        if (!file_exists($filenameFull)) {
            $output->writeln(sprintf(
                '<error>File does not exist (%s)</error>',
                $filenameFull
            ));

            return -3;
        }

        $existDbClient = $this->getExistDbClient();
        $existDbBase = $existDbClient->getCollection();

        // TODO: pass $isBinary depending of the type of the actual file
        return $this->checkCollectionAndStore(
            $output,
            $existDbClient,
            $subCollection = $existDbBase . '/' . $collection,
            $resource,
            $filenameFull,
            $overwrite,
            true,
            $isBinary = true
        );
    }

    /**
     * Returns whether a path is absolute.
     *
     * @param string $path a path string
     *
     * @return bool returns true if the path is absolute, false if it is
     *              relative or empty
     *
     * @since 1.0 Added method.
     * @since 2.0 Method now fails if $path is not a string.
     */
    protected function isAbsolutePath(string $path)
    {
        if ('' === $path) {
            return false;
        }

        // Strip scheme
        if (false !== ($pos = strpos($path, '://'))) {
            $path = substr($path, $pos + 3);
        }

        // UNIX root "/" or "\" (Windows style)
        if ('/' === $path[0] || '\\' === $path[0]) {
            return true;
        }

        // Windows root
        if (strlen($path) > 1 && ctype_alpha($path[0]) && ':' === $path[1]) {
            // Special case: "C:"
            if (2 === strlen($path)) {
                return true;
            }

            // Normal case: "C:/ or "C:\"
            if ('/' === $path[2] || '\\' === $path[2]) {
                return true;
            }
        }

        return false;
    }

    protected function importVolume(OutputInterface $output, $resource)
    {
        if (empty($resource)) {
            $output->writeln(sprintf('<error>Resource not specified</error>'));

            return -3;
        }

        if ($this->isAbsolutePath($resource)) {
            $filenameFull = $resource;
            $resource = basename($resource);
        }
        else {
            $filenameFull = $this->projectDir . '/data/tei/' . $resource;
        }

        if (!file_exists($filenameFull)) {
            $output->writeln(sprintf(
                '<error>File does not exist (%s)</error>',
                $filenameFull
            ));

            return -3;
        }

        $entity = \App\Entity\TeiHeader::fromXml($filenameFull);

        if (is_null($entity)) {
            $output->writeln(sprintf(
                '<error>%s could not be loaded</error>',
                $filenameFull
            ));

            return -2;
        }

        if (empty($entity->getGenre()) || empty($entity->getId())) {
            $output->writeln(sprintf(
                '<error>DTAID or classCode for genre missing (%s)</error>',
                $resource
            ));

            return -1;
        }

        if (empty($entity->getLanguage())
            || !in_array($entity->getLanguage(), ['eng', 'deu'])) {
            $output->writeln(sprintf(
                '<error>Invalid language %s (%s)</error>',
                $entity->getLanguage(),
                $resource
            ));

            return -1;
        }

        $dtaidStem = in_array($entity->getGenre(), ['document-collection', 'image-collection'])
            ? 'chapter' : $entity->getGenre();

        $reDtaid = sprintf('/^%s:%s\-\d+$/', $this->siteKey, $dtaidStem);
        if (!preg_match($reDtaid, $entity->getId())) {
            $output->writeln(sprintf(
                '<error>DTAID %s does not match the pattern %s</error>',
                $entity->getId(),
                $reDtaid
            ));

            return -1;
        }

        $articleUidLocal = preg_replace(sprintf('/^%s:/', $this->siteKey), '', $entity->getId());

        $resourceNameExpected = sprintf(
            '%s.%s.xml',
            $articleUidLocal,
            $entity->getLanguage()
        );

        if ($resourceNameExpected != $resource) {
            $output->writeln(sprintf(
                '<error>resource %s does not match the expected value %s</error>',
                $resource,
                $resourceNameExpected
            ));

            return -1;
        }

        $existDbClient = $this->getExistDbClient();
        $existDbBase = $existDbClient->getCollection();

        $overwrite = false; // TODO: get from options

        switch ($entity->getGenre()) {
            case 'volume':
                $collection = $articleUidLocal;

                return $this->checkCollectionAndStore($output, $existDbClient, $subCollection = $existDbBase . '/data/volumes/' . $collection, $resource, $filenameFull, $overwrite);
                break;

            case 'document-collection':
            case 'image-collection':
            case 'introduction':
            case 'document':
            case 'image':
            case 'audio':
            case 'video':
            case 'map':
                $parts = explode('/', $entity->getShelfmark());
                if ($this->siteKey != $parts[0] || !preg_match('/^\d+\:(volume\-\d+)$/', $parts[1], $matches)) {
                    $output->writeln(sprintf(
                        '<error>Could not determine volume from shelfmark %s/error>',
                        $entity->getShelfmark()
                    ));

                    return -1;
                }

                $collection = $matches[1];

                return $this->checkCollectionAndStore($output, $existDbClient, $subCollection = $existDbBase . '/data/volumes/' . $collection, $resource, $filenameFull, $overwrite);
                break;
        }

        $output->writeln(sprintf(
            '<error>Not handling genre %s</error>',
            $entity->getGenre()
        ));

        return -1;
    }
}
