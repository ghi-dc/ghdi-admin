<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

use Symfony\Contracts\Translation\TranslatorInterface;

/*
 * Start implementing DTS API https://w3id.org/dts
 *
 * Currently leaving out navigation and collections
 * only for drilling down (no parent-collection queries)
 */
class DtsApiController
extends ResourceController
{
    protected $defaultContext = [
        '@vocab' => 'http://www.w3.org/ns/hydra/core#', // @vocab: make hydra: default prefix
        'dts' => 'https://w3id.org/dts/api#',
    ];

    /**
     * @Route("/api/dts", name="dts-entry-point")
     */
    public function entryPointAction(Request $request)
    {
        return new JsonResponse([
            "@context" => $this->generateUrl('dts-entry-point-context'),
            "@id" => $this->generateUrl($request->get('_route')),
            "@type" => "EntryPoint",
            "collections" => $this->generateUrl('dts-collections'),
            "documents"=> $this->generateUrl('dts-document'),
            // "navigation" => "/api/dts/navigation",
        ]);
    }

    /**
     * @Route("/api/dts/contexts/EntryPoint.jsonld", name="dts-entry-point-context")
     */
    public function entryPointContextAction(Request $request)
    {
        return new JsonResponse([
            '@context' => $this->defaultContext,
        ]);
    }

    /* TODO: share with VolumeController */
    protected function buildResources(\ExistDbRpc\Client $client, $id, $lang)
    {
        $xql = $this->renderView('Volume/list-resources-json.xql.twig', [
            'prefix' => $this->siteKey,
        ]);

        $query = $client->prepareQuery($xql);
        $query->setJSONReturnType();
        $query->bindVariable('collection', $client->getCollection() . '/' . str_replace($this->siteKey . ':', '', $id));
        $query->bindVariable('lang', $lang);
        $query->bindVariable('getTerms', false);
        $res = $query->execute();
        $resources = $res->getNextResult();
        $res->release();

        return $resources;
    }

    /**
     * @Route("/api/dts/collections", name="dts-collections")
     *
     * TODO: https://distributed-text-services.github.io/specifications/Collections-Endpoint.html#parent-collection-query
     */
    public function collectionsAction(Request $request, TranslatorInterface $translator)
    {
        $id = $request->get('id');

        if (empty($id)) {
            $id = $this->siteKey;
        }

        $locale = $request->getLocale();

        if ($id == $this->siteKey) {
            $title = /** @Ignore */$translator->trans($this->getParameter('app.site.name'), [], 'additional');
            $response = [
                '@context' => $this->defaultContext + [
                    'dc' => 'http://purl.org/dc/terms/',
                ],
                '@id' => $id,
                '@type' => 'Collection',
                'totalItems' => 0,
                'dts:totalParent' => 0,
                'dts:totalChildren' => 0,
                'title' => $title,
                'dts:dublincore' => [
                    'dc:publisher' => 'GHI',
                    'dc:title' => [
                        [
                            '@language' => $locale,
                            '@value' => $title
                        ],
                    ],
                ],
                'member' => [],
            ];

            // query volumes
            $client = $this->getExistDbClient($this->subCollection);

            $xql = $this->renderView('Volume/list-json.xql.twig', [
                'prefix' => $this->siteKey,
            ]);

            $query = $client->prepareQuery($xql);
            $query->setJSONReturnType();
            $query->bindVariable('lang', \App\Utils\Iso639::code1To3($locale));
            $query->bindVariable('q', '');
            $query->bindVariable('collection', $client->getCollection());
            $res = $query->execute();
            $result = $res->getNextResult();
            $res->release();

            if (!is_null($result)) {
                $response['dts:total'] = $response['dts:totalChildren'] = count($result['data']);
                foreach ($result['data'] as $resource) {
                    $response['member'][] = [
                        '@id' => join(':', [ $this->siteKey, $resource['id'] ]),
                        'title' => $resource['name'],
                        '@type' => 'Collection',
                        // TODO: description
                        // TODO: count children
                        'dts:totalParents' => 1, // tree with single parent
                    ];
                }
            }

            return new JsonResponse($response);
        }

        // lookup by id
        $client = $this->getExistDbClient($this->subCollection);

        $xql = $this->renderView('XQuery/lookup-by-id.xql.twig', [
            'prefix' => $this->siteKey,
        ]);

        $query = $client->prepareQuery($xql);
        $query->setJSONReturnType();
        $query->bindVariable('lang', $lang = \App\Utils\Iso639::code1To3($locale));
        $query->bindVariable('id', $id);
        $query->bindVariable('collection', $client->getCollection());
        $res = $query->execute();
        $result = $res->getNextResult();
        $res->release();

        if (!is_null($result) && count($result['data']) > 0) {
            $resource = $result['data'][0];
            $genre = $resource['genre'];
            if ('volume' ==  $genre || preg_match('/\-collection$/', $genre)) {
                $response = [
                    '@context' => $this->defaultContext + [
                        'dc' => 'http://purl.org/dc/terms/',
                    ],
                    '@id' => $id,
                    '@type' => 'Collection',
                    'dts:totalParent' => 1,
                    'dts:totalChildren' => 0,
                    'title' => $resource['name'],
                    'dts:dublincore' => [
                        'dc:title' => [
                            [
                                '@language' => $locale,
                                '@value' => $resource['name'],
                            ],
                        ],
                        // 'dc:description' => $resource['description'],
                    ],
                    'member' => [],
                ];

                $path = explode('/', $resource['shelfmark']);
                if ('volume' == $resource['genre']) {
                    $volumeId = $id;
                }
                else {
                    $volumeParts = explode(':', $path[1], 2);
                    $volumeId = $volumeParts[1];
                }

                $resources = $this->buildResources($client, $volumeId, $lang);
                if (!is_null($resources)) {
                    foreach ($resources['data'] as $resource) {
                        $parts = explode('/', $resource['shelfmark']);
                        if (count($parts) != count($path) + 1) {
                            if ('volume' == $genre || count($parts) != count($path) + 2) {
                                // neither a direct child nor an attachment
                                // TODO: count so we can set member-count
                                continue;
                            }
                        }

                        for ($i = 0; $i < count($path); $i++) {
                            if ($path[$i] != $parts[$i]) {
                                // not the same parent
                                continue 2;
                            }
                        }

                        $response['member'][] = [
                            '@id' => join(':', [ $this->siteKey, $resource['id'] ]),
                            'title' => $resource['name'],
                            '@type' => preg_match('/\-collection$/', $resource['genre'])
                                ? 'Collection' : 'Resource',
                            // TODO: description
                            // TODO: count children
                            'dts:totalParents' => 1, // tree with single parent
                        ];
                    }
                }

                $response['dts:total'] = $response['dts:totalChildren'] = count($response['member']);

                return new JsonResponse($response);
            }
        }

        $response = new JsonResponse();
        $response->setStatusCode(404);

        return $response;
    }

    protected function xmlErrorResponse()
    {
        $xml = <<<EOT
<?xml version="1.0" encoding="UTF-8" standalone="no" ?>
<error statusCode="400" xmlns="https://w3id.org/dts/api">
  <title>Invalid request parameters</title>
  <description>The query parameters were not correct.</description>
</error>
EOT;

        $response = new Response($xml);
        $response->headers->set('Content-Type', 'xml');

        return $response;
    }

    /**
     * @Route("/api/dts/document", name="dts-document")
     */
    public function documentAction(Request $request)
    {
        $id = $request->get('id');

        if (empty($id)) {
            return $this->xmlErrorResponse();
        }

        // lookup by id
        $client = $this->getExistDbClient($this->subCollection);

        $xql = $this->renderView('XQuery/lookup-by-id.xql.twig', [
            'prefix' => $this->siteKey,
        ]);

        $query = $client->prepareQuery($xql);
        $query->setJSONReturnType();
        $query->bindVariable('lang', $lang = \App\Utils\Iso639::code1To3($locale = $request->getLocale()));
        $query->bindVariable('id', $id);
        $query->bindVariable('collection', $client->getCollection());
        $res = $query->execute();
        $result = $res->getNextResult();
        $res->release();

        if (!is_null($result) && count($result['data']) > 0) {
            $resource = $result['data'][0];
            if ('volume' == $resource['genre']) {
                $volumeId = $resource['id'];
            }
            else {
                $path = explode('/', $resource['shelfmark']);
                $volumeParts = explode(':', $path[1], 2);
                $volumeId = $volumeParts[1];
            }

            $resourcePath = $client->getCollection() . '/' . $volumeId . '/' . $resource['id'];
            $tei = $client->getDocument(join('.', [ $resourcePath , $lang, 'xml' ]), [
                'omit-xml-declaration' => 'no',
            ]);

            $response = new Response((string)$this->prettyPrintTei($tei));
            $response->headers->set('Content-Type', 'xml');

            return $response;
        }

        return $this->xmlErrorResponse();
    }
}
