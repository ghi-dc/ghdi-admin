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

class SolrPopulateCommand
extends ExistDbCommand
{
    /**
     * @var \FS\SolrBundle\SolrInterface
     */
    private $solr;
    private $adminClient;
    private $twig;
    private $slugify;
    private $frontendDataDir;

    public function __construct(string $siteKey,
                                \App\Service\ExistDbClientService $existDbClientService,
                                ParameterBagInterface $params,
                                KernelInterface $kernel,
                                \Symfony\Contracts\HttpClient\HttpClientInterface $adminClient,
                                \FS\SolrBundle\SolrInterface $solr,
                                \Twig\Environment $twig,
                                \Cocur\Slugify\SlugifyInterface $slugify)
    {
        // you *must* call the parent constructor
        parent::__construct($siteKey, $existDbClientService, $params, $kernel);

        $this->solr = $solr;
        $this->adminClient = $adminClient;
        $this->twig = $twig;
        $this->slugify = $slugify;
        
        $this->frontendDataDir = realpath($this->params->get('app.frontend.data_dir'));
        if (empty($this->frontendDataDir)) {
            die(sprintf('app.frontend.data_dir (%s) does not exist',
                        $this->params->get('app.frontend.data_dir')));
        }
    }

    protected function configure()
    {
        $this
            ->setName('solr:populate')
            ->setDescription('Populate Solr Index')
            ->addOption(
                'locale',
                null,
                InputOption::VALUE_REQUIRED,
                'what locale (en or de)',
                'de'
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

    protected function fetchDocument($urlDocument)
    {
        $apiResponse = $this->adminClient->request('GET', $urlDocument);
        
        $xml = $apiResponse->getContent();

        $entity = \App\Entity\TeiFull::fromXmlString($xml, false);
                        
        if (!is_null($entity)) {
            $fname = sprintf('%s.%s.xml', $entity->getId(true), $entity->getLanguage());
            $teiPath = join('/', [ $this->frontendDataDir, 'volumes', $entity->getVolumeId(), $fname ]);

            $reindex = true;
            // compare with filesystem and check if we reindex
            if (file_exists($teiPath)) {
                // TODO: compare publication date'
                $reindex = false;
            }
            
            if ($reindex) {
                file_put_contents($teiPath, $xml);
                
                return $entity;
            }                    
        }
    }
    
    protected function fetchCollection($locale, $id = null)
    {
        $dtsBase = ('en' != $locale ? $locale . '/' : '')
            . 'api/dts/';
        

        $urlDocument = $dtsBase . 'document';
        $urlCollections = $dtsBase . 'collections';
        
        if (!empty($id)) {
            $urlCollections .= '?id=' . $id;
        }

        $response = $this->adminClient->request('GET', $urlCollections);
        $result = $response->toArray();

        $entities = [];
        
        if (!empty($result['@id'])) {
            $entity = $this->fetchDocument($urlDocument . '?id=' . $result['@id']);
            if (!is_null($entity)) {
                $entities[] = $entity;
            }
        }
        
        if (!empty($result['member'])) {
            foreach ($result['member'] as $info) {                
                                
                if ('Collection' == $info['@type']) {
                    $children = $this->fetchCollection($locale, $info['@id']);
                    foreach ($children as $child) {
                        $entities[] = $child;
                    }
                }
                else {
                    $entity = $this->fetchDocument($urlDocument . '?id=' . $info['@id']);
                    if (!is_null($entity)) {
                        $entities[] = $entity;
                    }
                }
            }
        }
        
        return $entities;
    }

    protected function buildEntities($locale, $id = null)
    {
        return $this->fetchCollection($locale, $id);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $locale = $input->getOption('locale');
        
        $entities = $this->buildEntities($locale, 'ghis:volume-2');

        try {
            // $this->solr->synchronizeIndex($entities); would be more efficient but adds duplicates
            foreach ($entities as $entity) {
                $this->prepareEntity($entity); // set tags for indexing
                
                $this->solr->updateDocument($entity);
            }
        } catch (\Exception $e) {
            $output->writeln(sprintf('A error occurs: %s', $e->getMessage()));

            return -1;
        }

        return 0;
    }
}
