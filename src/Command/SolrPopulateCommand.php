<?php
// src/Command/SolrCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\HttpKernel\KernelInterface;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class SolrPopulateCommand
extends ExistDbCommand
{
    /**
     * @var \FS\SolrBundle\SolrInterface
     */
    private $solr;
    private $adminClient;
    protected $conversionService;
    protected $imageHeaderService;
    private $twig;
    private $slugify;
    private $frontendDataDir;
    private $frontendMediaDir;

    public function __construct(string $siteKey,
                                ParameterBagInterface $params,
                                KernelInterface $kernel,
                                \App\Service\ExistDbClientService $existDbClientService,
                                HttpClientInterface $adminClient,
                                \FS\SolrBundle\SolrInterface $solr,
                                \App\Service\ImageConversion\ConversionService $conversionService,
                                \App\Service\ImageHeader\ImageHeaderService $imageHeaderService,
                                \Twig\Environment $twig,
                                \Cocur\Slugify\SlugifyInterface $slugify)
    {
        // you *must* call the parent constructor
        parent::__construct($siteKey, $params, $kernel, $existDbClientService);

        $this->adminClient = $adminClient;
        $this->solr = $solr;
        $this->conversionService = $conversionService;
        $this->imageHeaderService = $imageHeaderService;
        $this->twig = $twig;
        $this->slugify = $slugify;

        $this->frontendDataDir = realpath($this->params->get('app.frontend.data_dir'));
        if (empty($this->frontendDataDir)) {
            die(sprintf('app.frontend.data_dir (%s) does not exist',
                        $this->params->get('app.frontend.data_dir')));
        }

        $this->frontendMediaDir = realpath($this->params->get('app.frontend.media_dir'));
        if (empty($this->frontendMediaDir)) {
            die(sprintf('app.frontend.media_dir (%s) does not exist',
                        $this->params->get('app.frontend.media_dir')));
        }
    }

    protected function configure()
    {
        $this
            ->setName('solr:populate')
            ->setDescription('Populate Solr Index')
            ->addArgument(
                'volume',
                InputArgument::REQUIRED,
                'Which volume do you want to index'
            )
            ->addOption(
                'locale',
                null,
                InputOption::VALUE_REQUIRED,
                'what locale (en or de)',
                'de'
            )
            ->addOption(
                'force-reindex',
                null,
                InputOption::VALUE_NONE,
                'Specify to force reindexing existing resources'
            )
            ->addOption(
                'remove',
                null,
                InputOption::VALUE_NONE,
                'Specify to remove an existing resources'
            )
            ->addOption(
                'id',
                null,
                InputOption::VALUE_REQUIRED,
                'Specify a specific resource'
            )
            ;
    }

    /* TODO: share with ResourceController, either through trait or class */
    protected function findIdentifierByUri($uri)
    {
        static $registered = false;

        if (!$registered) {
            \App\Utils\Lod\Identifier\Factory::register(\App\Utils\Lod\Identifier\GndIdentifier::class);
            \App\Utils\Lod\Identifier\Factory::register(\App\Utils\Lod\Identifier\LocLdsSubjectsIdentifier::class);
            \App\Utils\Lod\Identifier\Factory::register(\App\Utils\Lod\Identifier\WikidataIdentifier::class);

            $registered = true;
        }

        return \App\Utils\Lod\Identifier\Factory::fromUri($uri);
    }

    /**
     * Prepare $entity for indexing
     */
    protected function prepareEntity($entity)
    {
        $locale = \App\Utils\Iso639::code3To1($entity->getLanguage());

        // we set the editors as tags
        $editors = $entity->getEditors();
        foreach ($editors as $editor) {
            $tag = new \App\Entity\Tag();
            $tag->setType('editor');
            $tag->setName($editor->getName());
            $tag->setId('editor-' . $this->slugify->slugify($editor->getName()));
            $entity->addTag($tag);
        }

        // we need to expand the terms to proper tags for indexing
        $uris = $entity->getTerms();
        if (!is_null($uris)) {
            $values = [];
            foreach ($uris as $uri) {
                $identifier = $this->findIdentifierByUri($uri);
                if (!is_null($identifier)) {
                    $values[] = $identifier->getValue();
                }
            }

            if (!empty($values)) {
                $template = $this->twig->load('Term/lookup-for-indexing-json.xql.twig');
                $xql = $template->render([]);
                $client = $this->getExistDbClient();
                $query = $client->prepareQuery($xql);
                $query->setJSONReturnType();
                $query->bindVariable('collection', $client->getCollection() . '/data/authority/terms');
                $query->bindVariable('locale', $locale);
                $query->bindVariable('ids', $values);
                $res = $query->execute();
                $result = $res->getNextResult();
                $res->release();

                foreach ($result['data'] as $term) {
                    $tag = new \App\Entity\Tag();
                    $tag->setType('term');
                    $tag->setName($term['name']);
                    $tag->setPath($term['path']);
                    $tag->setId($term['id']);
                    $entity->addTag($tag);
                }
            }
        }
    }

    protected function adjustMediaUrl($content)
    {
        $teiHelper = new \App\Utils\TeiHelper();

        return $teiHelper->adjustMediaUrlString($content, function ($url) {
            if (strpos($url, 'https://ghdi-ca.ghi-dc.org') === 0
                || strpos($url, 'https://germanhistorydocs.ghi-dc.org/images/') === 0)
            {
                // it is a Collective Access or a GHDI legacy link
                $path = parse_url($url, PHP_URL_PATH);
                $fname = basename($path);
                if (preg_match('/_original\./', $fname)) {
                    if (preg_match('/\.xml$/', $fname)) {
                        // SVG
                        $fname = preg_replace('/\.xml$/', '.svg', $fname);
                    }
                    else {
                        $fname = preg_replace('/_original\./', '_frontend.', $fname);
                    }
                }

                return $fname;
            }

            return $url;
        });
    }

    /**
     * Fetches an image stores it into $imagePath
     */
    protected function fetchRemoteImage($url, $mediaPath, $fname)
    {
        if (preg_match('~(^.+/)([^/]+)$~', $url, $matches)) {
            // handles spaces and umlauts e.g. http://germanhistorydocs.ghi-dc.org/images/00004711_Stand auf dem Blutger�ste.jpg
            $url = $matches[1] . rawurlencode($matches[2]);
        }

        // TODO: don't look at extension but look at mime-type instead
        $parts = parse_url($url);
        $path = parse_url($url, PHP_URL_PATH);

        $targetType = 'image/jpeg';
        if (!preg_match('/\.(jpg)$/i', $path, $matches)) {
            if (preg_match('/\.([a-z0-9]+)$/i', $path, $matches)) {
                switch (strtolower($matches[1])) {
                    case 'png':
                        $targetType = 'image/png';
                        break;

                    case 'xml':
                        $targetType = 'image/svg+xml';
                        $fname = preg_replace('/\.xml$/', '.svg', $fname);
                        break;

                    default:
                        die('TODO: handle extension for ' . $url);

                }
            }
            else {
                die('TODO: handle extension for ' . $url);
            }
        }

        if (!file_exists($mediaPath . '/' . $fname)) {
            $handle = @fopen($url, 'r');

            if (false !== $handle) {
                file_put_contents($mediaPath . '/' . $fname, $handle);
            }
        }

        $imageName = null;
        if (file_exists($mediaPath . '/' . $fname)) {
            $file = new \Symfony\Component\HttpFoundation\File\File($mediaPath . '/' . $fname);
            $imageName = $file->getFileName();

            $maxDimension = 1200;

            if ('image/svg+xml' == $targetType) {
                // render PNG for PDF
                $converted = $this->conversionService->convert($file, [
                    'target_type' => 'image/png',
                    // currently, the following adds blank space windows
                    'geometry' => $maxDimension . 'x',
                ]);
            }
            else {
                $maxResolution = 72;

                // check if we need to convert (resize)
                $info = $this->conversionService->identify($file);

                if ((!empty($info['width']) && $info['width'] > $maxDimension)
                    || (!empty($info['height']) && $info['height'] > $maxDimension))
                {
                    $converted = $this->conversionService->convert($file, [
                        'geometry' => $maxDimension . 'x' . $maxDimension,
                        'target_type' => $targetType,
                    ]);

                    $imageName = $converted->getFileName();
                }

                $info = $this->imageHeaderService->getResolution($file = new \Symfony\Component\HttpFoundation\File\File($mediaPath . '/' . $imageName));

                if (!empty($info) && ($info['xresolution'] > $maxResolution || $info['yresolution'] > $maxResolution)) {
                    $res = $this->imageHeaderService->setResolution($file, [ 'xresolution' => $maxResolution, 'yresolution' => $maxResolution ]);
                }
            }
        }

        return $imageName;
    }

    protected function fetchDocument($urlDocument, $forceReindex = false)
    {
        $apiResponse = $this->adminClient->request('GET', $urlDocument);

        $xml = $apiResponse->getContent();

        $entity = \App\Entity\TeiFull::fromXmlString($xml, false);

        if (!is_null($entity)) {
            $fname = sprintf('%s.%s.xml', $entity->getId(true), $entity->getLanguage());
            $teiPath = join('/', [ $this->frontendDataDir, 'volumes', $entity->getVolumeId(), $fname ]);

            $res = $this->adjustMediaUrl($xml);
            $mediaUrls = [];
            if (is_array($res) && !empty($res['urls'])) {
                $xml = (string)($res['document']); // since urls have changed
                $mediaUrls = $res['urls'];
            }

            $reindex = true;
            // compare with filesystem and check if we reindex
            if (file_exists($teiPath)) {
                $hash = md5(file_get_contents($teiPath));

                // TODO: compare publication date
                $reindex = $hash != md5($xml);
            }

            if ($reindex || $forceReindex) {
                $mediaPath = join('/', [ $this->frontendMediaDir, $entity->getVolumeId(), $entity->getId(true) ]);

                foreach ($mediaUrls as $url => $fname) {
                    if (!file_exists($mediaPath)) {
                        mkdir($mediaPath);
                    }

                    if (!file_exists($mediaPath) || !is_writable($mediaPath)) {
                        die($mediaPath . ' does not exist or is not writable');
                    }

                    $imageName = $this->fetchRemoteImage($url, $mediaPath, $fname);
                }
            }


            if ($reindex) {
                file_put_contents($teiPath, $xml);

                return $entity;
            }

            if ($forceReindex) {
                return $entity;
            }
        }
    }

    protected function fetchCollection($locale, $id = null, $forceReindex = false)
    {
        $urlCollections = $this->buildDtsUrlCollections($locale);

        if (!empty($id)) {
            $urlCollections .= '?id=' . $id;
        }

        $response = $this->adminClient->request('GET', $urlCollections);
        $result = $response->toArray();

        $entities = [];
        $urlDocument = $this->buildDtsUrlDocument($locale);

        if (!empty($result['@id'])) {
            $entity = $this->fetchDocument($urlDocument . '?id=' . $result['@id'], $forceReindex);
            if (!is_null($entity)) {
                $entities[] = $entity;
            }
        }

        if (!empty($result['member'])) {
            foreach ($result['member'] as $info) {
                if ('Collection' == $info['@type']) {
                    $children = $this->fetchCollection($locale, $info['@id'], $forceReindex);
                    foreach ($children as $child) {
                        $entities[] = $child;
                    }
                }
                else {
                    $entity = $this->fetchDocument($urlDocument . '?id=' . $info['@id'], $forceReindex);
                    if (!is_null($entity)) {
                        $entities[] = $entity;
                    }
                }
            }
        }

        return $entities;
    }

    protected function buildEntities($locale, $id = null, $forceReindex = false)
    {
        return $this->fetchCollection($locale, $id, $forceReindex);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $volume = $input->getArgument('volume');;
        if (empty($volume)) {
            $output->writeln(sprintf('<error>missing volume</error>'));

            return -1;
        }

        $locale = $input->getOption('locale');
        $forceReindex = $input->getOption('force-reindex');
        $remove = $input->getOption('remove');

        $id = $input->getOption('id');
        if (!empty($id)) {
            $entities = [];
            $urlDocument = $this->buildDtsUrlDocument($locale);
            // TODO: don't require $forceReindex set to true if document is not in solr-index,
            // e.g. because it was removed
            $entity = $this->fetchDocument($urlDocument . '?id=' . $id, $forceReindex || $remove);
            if (!is_null($entity) && $entity->getVolumeId() == $volume) {
                $entities[] = $entity;
            }
        }
        else {
            $remove = false; // currently only allowed with a specific id
            $entities = $this->buildEntities($locale, join(':', [ $this->siteKey, $volume ]), $forceReindex);
        }

        try {
            // $this->solr->synchronizeIndex($entities); would be more efficient but adds duplicates
            foreach ($entities as $entity) {
                if ($remove) {
                    $this->solr->removeDocument($entity);
                }
                else {
                    $this->prepareEntity($entity); // set tags for indexing

                    $this->solr->updateDocument($entity);
                }
            }
        }
        catch (\Exception $e) {
            $output->writeln(sprintf('A error occurs: %s', $e->getMessage()));

            return -1;
        }

        return 0;
    }
}
