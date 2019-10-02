<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
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

    protected function getAssetsPath()
    {
        return $this->getParameter('app.existdb.base') . '/assets';
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

    protected function teiToHtml($client, $resourcePath, $lang, $path = null)
    {
        $xql = $this->renderView('Resource/tei2html.xql.twig', [
            'path' => $path,
        ]);
        $query = $client->prepareQuery($xql);
        $query->bindVariable('stylespath', $this->getStylesPath());
        $resourcePath =
        $query->bindVariable('resource', $resourcePath);
        $query->bindVariable('lang', $lang);
        $res = $query->execute();
        $html = $res->getNextResult();
        $res->release();

        return $html;
    }

    /**
     * anvc/scalar is picky about newlines and whitespaces in html content
     *
     * The following is an attempt to tweak so the display looks good
     *
     */
    protected function minify($html, $inlineContent = false)
    {
        $htmlMin = new \voku\helper\HtmlMin();
        $htmlMin->doOptimizeViaHtmlDomParser(true);
        $htmlMin->doRemoveWhitespaceAroundTags(false);

        $htmlContent = $htmlMin->minify($html);

        // TODO: we might just use $htmlContent instead of going through \Symfony\Component\DomCrawler\Crawler
        if ($inlineContent) {
            $htmlContent = '<div>' . $htmlContent . '</div>';
        }

        $crawler = new \Symfony\Component\DomCrawler\Crawler();
        $crawler->addHtmlContent($htmlContent);

        $ret = $crawler->filter($inlineContent ? 'body > div' : 'body')->html();

        // remove empty p
        $ret = preg_replace('/<p>\s*<\/p>/', '', $ret);

        // remove newlines after tags
        $ret = preg_replace('/(<[^>]+>\s*)\R+/', '\1', $ret);

        // replace newlines before tags with space
        $ret = preg_replace('/\s*\R+\s*(<[^>]+>)/', ' \1', $ret);

        $ret = preg_replace('/(<\/p>)(<p>)/', '\1 \2', $ret);
        $ret = preg_replace('/(<\/p>)(<br>)/', '\1 \2', $ret);

        return $ret;
    }

    private function addScalarHasPart($resources)
    {
        $hasPart = [];

        foreach ($resources as $resource) {
            $part = [
                'scalar:metadata:slug' => $resource['id'],
            ];

            if (!empty($resource['resources'])) {
                $part['dcterms:hasPart'] = $this->addScalarHasPart($resource['resources']);
            }

            $hasPart[] = $part;
        }

        return $hasPart;
    }

    protected function teiToScalar($client, $resourcePath, $lang,
                                   $children = null, $embeddedFigure = false)
    {
        $ret = [];

        // some metadata
        $teiHelper = new \App\Utils\TeiHelper();
        $article = $teiHelper->analyzeHeaderString($client->getDocument($resourcePath), true);

        // TODO: we want to move to $article->slug but this needs slug-rename logic
        $uid = explode(':', $article->uid, 2);
        $ret['scalar:metadata:slug'] = $uid[1];

        $fieldDescr = [
            'dcterms:title' => [ 'xpath' => '//tei:titleStmt/tei:title', 'inlineContent' => true ],
            'sioc:content' => [ 'xpath' => '']
        ];

        if ($embeddedFigure) {
            $ret['scalar:metadata:slug'] = 'media/' . preg_replace('/^(image|map)\-/', 'media-', $ret['scalar:metadata:slug']);
            unset($fieldDescr['sioc:content']);

            $xql = $this->renderView('XQuery/figures2scalar.xql.twig', [
            ]);

            $query = $client->prepareQuery($xql);
            $query->setJSONReturnType();
            $query->bindVariable('stylespath', $this->getStylesPath());
            $query->bindVariable('resource', $resourcePath);
            $query->bindVariable('lang', $lang);
            $res = $query->execute();
            $figures = $res->getNextResult();
            $res->release();

            if (1 == count($figures['data'])) {
                // we can't currently handle more than one figure due to the 1:1 correspondance of image-1234 <-> media-1234
                $media = $figures['data'][0];

                $url = $media['url'];
                $scheme = parse_url($url, PHP_URL_SCHEME);
                if (empty($scheme)) {
                    $url = 'http://germanhistorydocs.ghi-dc.org/images/' . $url;
                }

                $ret['scalar:metadata:url'] = $url;
                foreach ([ 'description', 'creator', 'date' ] as $key) {
                    if (!empty($media[$key])) {
                        $ret['dcterms:' . $key ] = $this->minify($media[$key], 'description' != $key);
                    }
                }
            }
        }

        // title and content
        foreach ($fieldDescr as $key => $descr) {
            $xql = $this->renderView('Resource/tei2scalar.xql.twig', [
                'path' => $descr['xpath'],
            ]);
            $query = $client->prepareQuery($xql);
            $query->bindVariable('stylespath', $this->getStylesPath());
            $query->bindVariable('resource', $resourcePath);
            $query->bindVariable('lang', $lang);
            $res = $query->execute();
            $html = $res->getNextResult();
            $res->release();

            $ret[$key] = $this->minify($this->markCombiningE($html),
                                       array_key_exists('inlineContent', $descr)
                                       ? $descr['inlineContent'] : false);
        }

        if (!empty($children)) {
            $hasPart = [];

            foreach ($children as $key => $child) {
                $part = [
                    'scalar:metadata:slug' => $key,
                ];

                if (!empty($child['resources'])) {
                    $part['dcterms:hasPart'] = $this->addScalarHasPart($child['resources']);
                }

                $hasPart[] = $part;
            }

            $ret['dcterms:hasPart'] = $hasPart;
        }

        return new JsonResponse($ret);
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
