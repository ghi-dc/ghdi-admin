<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

/**
 *
 */
abstract class BaseController
extends Controller
{
    protected $siteKey = null;

    public function __construct(string $siteKey)
    {
        $this->siteKey = $siteKey;
    }

    protected function getExistDbClient($subCollection = null)
    {
        $user = $this->get('security.token_storage')->getToken()->getUser();

        $existDbClientService = $this->get(\App\Service\ExistDbClientService::class);
        $existDbClient = $existDbClientService->getClient($user->getUsername(), $user->getPassword());

        $collection = $this->getParameter('app.existdb.base');
        if (!empty($subCollection)) {
            $collection .= $subCollection;
        }
        $existDbClient->setCollection($collection);

        return $existDbClient;
    }

    protected function getStylesPath()
    {
        return $this->getParameter('app.existdb.base') . '/styles';
    }

    protected function getSerializer()
    {
        return \JMS\Serializer\SerializerBuilder::create()
            ->setPropertyNamingStrategy(
                new \JMS\Serializer\Naming\SerializedNameAnnotationStrategy(
                    new \JMS\Serializer\Naming\IdenticalPropertyNamingStrategy()
                )
            )
            ->build();
    }

    private function unparse_url($parsed_url) {
        $scheme   = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
        $host     = isset($parsed_url['host']) ? $parsed_url['host'] : '';
        $port     = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
        $user     = isset($parsed_url['user']) ? $parsed_url['user'] : '';
        $pass     = isset($parsed_url['pass']) ? ':' . $parsed_url['pass']  : '';
        $pass     = ($user || $pass) ? "$pass@" : '';
        $path     = isset($parsed_url['path']) ? $parsed_url['path'] : '';
        $query    = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
        $fragment = isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';

        return "$scheme$user$pass$host$port$path$query$fragment";
    }

    protected function buildWebDavBaseUrl($client)
    {
        if ($this->container->hasParameter('app.existdb.webdav')) {
            return $this->getParameter('app.existdb.webdav')
                . $client->getCollection();
        }

        $parts = parse_url($client->getUri());

        // don't show user and pass
        unset($parts['user']);
        unset($parts['pass']);

        // webdav instead of xmlrpc
        $parts['path'] = rtrim(str_replace('/xmlrpc/', '/webdav/', $parts['path']), '/')
            . $client->getCollection();

        return $this->unparse_url($parts);
    }

    protected function fetchVolume($client, $id, $lang)
    {
        $xql = $this->renderView('Volume/detail-json.xql.twig', [
            'prefix' => $this->siteKey,
        ]);

        $query = $client->prepareQuery($xql);
        $query->setJSONReturnType();
        $query->bindVariable('collection', $client->getCollection());
        $query->bindVariable('id', implode(':', [ $this->siteKey,  $id ]));
        $query->bindVariable('lang', $lang);
        $res = $query->execute();
        $volume = $res->getNextResult();
        $res->release();

        return $volume;
    }

    protected function getTeiSkeleton()
    {
        // TODO: maybe get from exist instead of filesystem, so it can differ among projects
        $fnameSkeleton =   $this->container->get('kernel')->getProjectDir()
            . '/data/tei/skeleton.tei.xml';

        return file_get_contents($fnameSkeleton);
    }

    protected function teiToDublinCore($client, $resourcePath)
    {
        $translator = $this->get('translator');

        $xql = $this->renderView('XQuery/tei2dc.xql.twig', []);

        $query = $client->prepareQuery($xql);
        $query->bindVariable('site', /** @Ignore */$translator->trans($this->getParameter('app.site.name'), [], 'additional'));

        $query->bindVariable('resource', $resourcePath);

        $query->bindVariable('path', dirname($resourcePath));
        $query->bindVariable('basename', basename($resourcePath));

        $res = $query->execute();
        $xml = $res->getNextResult();
        $res->release();

        $response = new Response($xml);
        $response->headers->set('Content-Type', 'text/xml');

        return $response;
    }

    protected function extractPartsFromHtml($html)
    {
        $crawler = new \Symfony\Component\DomCrawler\Crawler();
        $crawler->addHtmlContent($html);

        // extract entities
        $entities = $crawler->filterXPath("//span[@class='entity-ref']")->each(function ($node, $i) {
            $entity = [];
            $type = $node->attr('data-type');
            if (!empty($type)) {
                $entity['type'] = $type;
            }
            $uri = $node->attr('data-uri');
            if (!empty($uri)) {
                $entity['uri'] = $uri;
            }

            return $entity;
        });

        return [
            'entities' => $entities,
        ];
    }

    protected function buildEntityLookup($entities)
    {
        $entitiesByType = [
            'person' => [],
            'place' => [],
            'organization' => [],
            'date' => [],
        ];

        foreach ($entities as $entity) {
            if (!array_key_exists($entity['type'], $entitiesByType)) {
                continue;
            }

            if (!array_key_exists($entity['uri'], $entitiesByType[$entity['type']])) {
                $entitiesByType[$entity['type']][$entity['uri']] = [ 'count' => 0 ];
            }

            ++$entitiesByType[$entity['type']][$entity['uri']]['count'];
        }

        foreach ($entities as $entity) {
            if (!array_key_exists($entity['type'], $entitiesByType)) {
                continue;
            }
            if (!array_key_exists($entity['uri'], $entitiesByType[$entity['type']])) {
                $entitiesByType[$entity['type']][$entity['uri']] = [ 'count' => 0 ];
            }
            ++$entitiesByType[$entity['type']][$entity['uri']]['count'];
        }

        foreach ($entitiesByType as $type => $uriCount) {
            switch ($type) {
                default:
                    foreach ($uriCount as $uri => $count) {
                        $details = [
                            'url' => $uri,
                        ];
                        $entitiesByType[$type][$uri] += $details;
                    }
            }
        }

        return $entitiesByType;
    }

    public function normalizeEntityUri($client, $uri, $type)
    {
        static $normalized = [];

        if (!array_key_exists($type, $normalized)) {
            $normalized[$type] = [];
        }

        if (array_key_exists($uri, $normalized[$type])) {
            return $normalized[$type][$uri];
        }

        $uriSrc = $uri;

        $xql = null;
        $params = null;
        $resultKey = 'gnd';

        if (preg_match('~www.wikidata.org/entity/(Q\d+)$~', $uri, $matches)) {
            $qid = $matches[1];

            switch ($type) {
                case 'person':
                    $params['collection'] = $this->getParameter('app.existdb.base') . '/data/authority/persons';
                    $params['type'] = 'wikidata';
                    $params['value'] = $qid;
                    $xql = $this->renderView('Person/lookup-by-identifier-json.xql.twig', []);

                    break;

                case 'organization':
                    $params['collection'] = $this->getParameter('app.existdb.base') . '/data/authority/organizations';
                    $params['type'] = 'wikidata';
                    $params['value'] = $qid;
                    $xql = $this->renderView('Organization/lookup-by-identifier-json.xql.twig', []);

                    break;

                case 'place':
                    $params['collection'] = $this->getParameter('app.existdb.base') . '/data/authority/places';
                    $params['type'] = 'wikidata';
                    $params['value'] = $qid;
                    $xql = $this->renderView('Place/lookup-by-identifier-json.xql.twig', []);

                    $resultKey = 'tgn';

                    break;
            }
        }

        if (!empty($xql)) {
            $query = $client->prepareQuery($xql);

            $query->setJSONReturnType();
            foreach ($params as $key => $value) {
                $query->bindVariable($key, $value);
            }
            $res = $query->execute();
            $info = $res->getNextResult();
            $res->release();

            $gnd = $tgn = null;

            if (!empty($info)) {
                if (array_key_exists($resultKey, $info['data'])
                    && !empty($info['data'][$resultKey]))
                {
                    switch ($resultKey) {
                        case 'tgn':
                            $tgn = $info['data'][$resultKey];
                            break;

                        case 'gnd':
                            $gnd = $info['data'][$resultKey];
                            break;
                    }
                }
            }

            if ('place' == $type) {
                // tgn is primary
            }
            else if (is_null($gnd)) {
                // no internal storage, so try to lookup
                // TODO: differentiate between person and organization
                $bio = new \App\Utils\BiographicalData();

                try {
                    $gnds = $bio->lookupGndByQid($qid);
                    if (1 == count($gnds)) {
                        $gnd = $gnds[0];
                    }
                }
                catch (\Exception $e) {
                    ; // ignore
                }
            }

            if (!is_null($gnd)) {
                $uri = sprintf('http://d-nb.info/gnd/%s', $gnd);
            }

            if (!is_null($tgn)) {
                $uri = sprintf('http://vocab.getty.edu/tgn/%s', $tgn);
            }
        }

        $normalized[$type][$uriSrc] = $uri;

        return $uri;
    }
}
