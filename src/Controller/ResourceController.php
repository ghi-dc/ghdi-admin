<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

use Symfony\Contracts\Translation\TranslatorInterface;

use App\Service\CollectiveAccessService;
use App\Utils\MpdfConverter;

/**
 *
 */
class ResourceController
extends BaseController
{
    /**
     * Make unique across language so we can line-up different languages under same id
     */
    protected function nextInSequence(\ExistDbRpc\Client $client, $collection, $prefix)
    {
        // see https://stackoverflow.com/a/48901690
        $xql = <<<EOXQL
    declare default element namespace "http://www.tei-c.org/ns/1.0";

    declare variable \$collection external;
    declare variable \$prefix external;

    let \$resources :=
        for \$resource in collection(\$collection)/TEI
        where fn:starts-with(util:document-name(\$resource), \$prefix)
        return fn:head(fn:tokenize(util:document-name(\$resource), '\.'))

    return (for \$key in (1 to 9999)!format-number(., '0')
        where not(\$prefix||\$key = \$resources)
        return \$prefix || \$key)[1]

EOXQL;

        $query = $client->prepareQuery($xql);
        $query->bindVariable('collection', $collection);
        $query->bindVariable('prefix', $prefix);
        $res = $query->execute();
        $nextId = $res->getNextResult();

        $res->release();

        if (empty($nextId)) {
            throw new \Exception('Could not generated next id in sequence');
        }

        return $nextId;
    }

    protected function buildPathFromShelfmark($shelfmark)
    {
        $parts = explode('/', $shelfmark);
        array_shift($parts); // pop site prefix

        // split of order within
        $parts = array_map(function ($orderId) {
            list($order, $id) = explode(':', $orderId, 2);

            return $id;
        }, $parts);

        return $parts;
    }

    private function setHasPart($data)
    {
        if (empty($data)) {
            return $data;
        }

        $resourcesById = [];
        $ret = [];

        foreach ($data as $resource) {
            $resourcesById[$resource['id']] = & $resource;

            $parts = $this->buildPathFromShelfmark($resource['shelfmark']);
            $parentId = $parts[count($parts) - 2];
            if (array_key_exists($parentId, $resourcesById)) {
                $parentResource = & $resourcesById[$parentId];
                if (!array_key_exists('hasPart', $parentResource)) {
                    $parentResource['hasPart'] = [];
                }
                $parentResource['hasPart'][] = $resource;

                continue;
            }

            $ret[] = $resource;
        }

        return $ret;
    }

    protected function listChildResources(\ExistDbRpc\Client $client, $volumeId, $id, $lang)
    {
        $xql = $this->renderView('Resource/list-child-resources-json.xql.twig', [
            'prefix' => $this->siteKey,
        ]);

        $query = $client->prepareQuery($xql);
        $query->setJSONReturnType();
        $query->bindVariable('collection', $client->getCollection() . '/' . $volumeId);
        $query->bindVariable('id', $id);
        $query->bindVariable('lang', $lang);
        $res = $query->execute();
        $resources = $res->getNextResult();
        $res->release();

        return !is_null($resources) ? $resources['data'] : null;
    }

    protected function buildChildResources(\ExistDbRpc\Client $client, $volumeId, $id, $lang)
    {
        $data = $this->listChildResources($client, $volumeId, $id, $lang);

        return $this->setHasPart($data);
    }

    protected function buildParentPath(\ExistDbRpc\Client $client, $resource, $lang)
    {
        $parts = $this->buildPathFromShelfmark($resource['data']['shelfmark']);

        $names = [];
        $volume = $parts[0];

        // ignore self, unless it is the volume
        for ($i = 0; $i < max(count($parts) - 1, 1); $i++) {
            $id = $parts[$i];

            $resourcePath = $client->getCollection() . '/' . $volume . '/' . $id . '.' . $lang . '.xml';

            $names[$id] = $this->teiToHtml($client, $resourcePath, $lang, '//tei:titleStmt/tei:title', true);
        }

        return $names;
    }

    protected function updateDocumentShelfmark(\ExistDbRpc\Client $client, $volume, $id, $lang, $shelfmark, $oldShelfmark = null)
    {
        $updates = [
            $client->getCollection() . '/' . $volume . '/' . $id . '.' . $lang . '.xml'
                => $shelfmark,
        ];

        $children = $this->listChildResources($client, $volume, $id, $lang);
        if (!empty($children)) {
            foreach ($children as $child) {
                $newShelfmark = str_replace($oldShelfmark, $shelfmark, $child['shelfmark']);
                if ($child['shelfmark'] !== $newShelfmark) {
                    $resource = $client->getCollection() . '/' . $volume . '/' . $child['id'] . '.' . $lang . '.xml';
                    $updates[$resource] = $newShelfmark;
                }
            }
        }

        $xql = $this->renderView('Resource/update-shelfmark.xql.twig', [
        ]);

        foreach ($updates as $resource => $shelfmark) {
            $query = $client->prepareQuery($xql);
            $query->bindVariable('resource', $resource);
            $query->bindVariable('shelfmark', $shelfmark);
            $res = $query->execute();
            $res->release();
        }

        return true;
    }

    protected function reorderChildResources(\ExistDbRpc\Client $client, $volume, $lang, $hasPart, $postData)
    {
        $order = json_decode($postData, true);

        $updated = false;
        if (false !== $order) {
            $newOrder = [];
            $count = 0;
            foreach ($order as $childId) {
                $newOrder[$childId] = ++$count;
            }

            foreach ($hasPart as $child) {
                $childId = $child['id'];
                if (array_key_exists($childId, $newOrder)) {
                    $parts = explode('/', $shelfmark = $child['shelfmark']);
                    list($order, $ignore) = explode(':', end($parts), 2);
                    if ($order != $newOrder) {
                        $newOrderAndId = sprintf('%03d:%s',
                                                 $newOrder[$childId], $childId);
                        $parts[count($parts) - 1] = $newOrderAndId;
                        $newShelfmark = implode('/', $parts);

                        if ($child['shelfmark'] != $newShelfmark) {
                            $this->updateDocumentShelfmark($client,
                                                           $volume, $childId, $lang,
                                                           $newShelfmark, $shelfmark);

                            $updated = true;
                        }
                    }
                }
            }
        }

        return $updated;
    }

    private function checkUpdateFromCollectiveAccess(CollectiveAccessService $caService, $html)
    {
        $mediaSrc = [];

        $crawler = new \Symfony\Component\DomCrawler\Crawler();
        $crawler->addHtmlContent($html);

        $crawler->filter('audio > source')->each(function ($node, $i) use (&$mediaSrc) {
            $mediaSrc[] = $node->attr('src');
        });

        $crawler->filter('video > source')->each(function ($node, $i) use (&$mediaSrc) {
            $mediaSrc[] = $node->attr('src');
        });

        $crawler->filter('img')->each(function ($node, $i) use (&$mediaSrc) {
            $mediaSrc[] = $node->attr('src');
        });

        if (empty($mediaSrc)) {
            return null;
        }

        $representationIdsByItemId = [];

        $representation = null;
        foreach ($mediaSrc as $src) {
            if (preg_match('/\d+_ca_object_representations_media_(\d+)_[a-z]+\./', $src, $matches)) {
                $representationId = $matches[1];
                if (is_null($representation)) {
                    $representation = $caService->getObjectRepresentation($representationId);
                    if (is_null($representation) || empty($representation['related']) || empty($representation['related']['ca_objects'])) {
                        // could not be found
                        return null;
                    }

                    foreach ($representation['related']['ca_objects'] as $object) {
                        $representationIdsByItemId[$object['object_id']] = array_keys($object['representations']);
                    }
                }

                // now unset all object-ids which don't have a matching representation
                // we are fine if every representation is found a single $objectId remains
                $found = false;
                foreach ($representationIdsByItemId as $objectId => $representationIds) {
                    if (!in_array($representationId, $representationIds)) {
                        unset($representationIdsByItemId[$objectId]);
                    }
                    else {
                        $found = true;
                    }
                }

                if (!$found) {
                    return null;
                }
            }
        }

        $candidates = array_keys($representationIdsByItemId);
        if (!empty($candidates) && 1 == count($candidates)) {
            return $candidates[0];
        }

        return null;
    }

    /**
     * @Route("/resource/{volume}/{id}.dc.xml", name="resource-detail-dc",
     *          requirements={"volume" = "volume\-\d+", "id" = "(introduction|chapter|document|image|audio|video|map|)\-\d+"})
     * @Route("/resource/{volume}/{id}.scalar.json", name="resource-detail-scalar",
     *          requirements={"volume" = "volume\-\d+", "id" = "(introduction|chapter|document|image|audio|video|map)\-\d+"})
     * @Route("/resource/{volume}/{id}/media.scalar.json", name="resource-embedded-media-scalar",
     *          requirements={"volume" = "volume\-\d+", "id" = "(introduction|chapter|document|image|audio|video|map)\-\d+"})
     * @Route("/resource/{volume}/{id}.tei.xml", name="resource-detail-tei",
     *          requirements={"volume" = "volume\-\d+", "id" = "(introduction|chapter|document|image|audio|video|map)\-\d+"})
     * @Route("/resource/{volume}/{id}.pdf", name="resource-detail-pdf",
     *          requirements={"volume" = "volume\-\d+", "id" = "(introduction|chapter|document|image|audio|video|map)\-\d+"})
     * @Route("/resource/{volume}/{id}.html", name="resource-detail-html",
     *          requirements={"volume" = "volume\-\d+", "id" = "(introduction|chapter|document|image|audio|video|map)\-\d+"})
     * @Route("/resource/{volume}/{id}", name="resource-detail",
     *          requirements={"volume" = "volume\-\d+", "id" = "(introduction|chapter|document|image|audio|video|map)\-\d+"})
     * @Route("/resource/{volume}/{id}", name="resource-create",
     *          requirements={"volume" = "volume\-\d+", "id" = "(introduction|chapter|document|image|audio|video|map)\-\d+"})
     */
    public function detailAction(Request $request,
                                 TranslatorInterface $translator,
                                 MpdfConverter $pdfConverter,
                                 CollectiveAccessService $caService,
                                 $volume, $id)
    {
        // TODO: Move to generic EntityLinking Service
        $textRazorApiKey = null;

        try {
            $textRazorApiKey = $this->getParameter('app.textrazor')['api_key'];
        }
        catch (\Exception $e) {
            // ignore if app.textrazor is not set
        }

        $showAddEntities = !empty($textRazorApiKey) ? 1 : 0;

        $client = $this->getExistDbClient($this->subCollection);

        $resource = $this->fetchResource($client, $id, $lang = \App\Utils\Iso639::code1To3($request->getLocale()));

        if (is_null($resource)) {
            // check if we have one in another locale
            $createFrom = [];

            foreach ($this->getParameter('locales') as $alternate) {
                if ($alternate == $request->getLocale()) {
                    continue;
                }

                $resourceAlternate = $this->fetchResource($client, $id, $alternateCode3 = \App\Utils\Iso639::code1To3($alternate));
                if (!is_null($resourceAlternate)) {
                    // we can only copy over if the complete parent-path already exists
                    $parentPathAlternate = $this->buildParentPath($client, $resourceAlternate, $alternateCode3);
                    $parentVolume = null;

                    foreach ($parentPathAlternate as $parentId => $name) {
                        $parentResource = $this->fetchResource($client, $parentId, $lang);

                        if (is_null($parentResource)) {
                            // we have to create this first
                            if ($parentId == $volume) {
                                // either the volume
                                return  $this->redirect($this->generateUrl('volume-detail', [ 'id' => $volume ]));
                            }

                            // or the chapter
                            return  $this->redirect($this->generateUrl('resource-detail', [ 'volume' => $volume, 'id' => $parentId ]));
                        }
                    }

                    if (!empty($_POST['from-locale']) && $_POST['from-locale'] == $alternate) {
                        $from = $client->getCollection() . '/' . $volume . '/' . $id . '.' . $alternateCode3 . '.xml';

                        $content = $client->getDocument($from, [ 'omit-xml-declaration' => 'no' ]);

                        if (false !== $content) {
                            $to = $client->getCollection() . '/' . $volume . '/' . $id . '.' . $lang . '.xml';

                            // set new language
                            // TODO: adjust translated-from if needed
                            $data = [
                                'language' => $lang,
                            ];

                            $res = $this->updateTeiHeaderContent($client, $to, $content, $data, false);

                            if ($res) {
                                $request->getSession()
                                        ->getFlashBag()
                                        ->add('info', 'The Entry has been copied')
                                    ;

                                if (in_array($resourceAlternate['data']['genre'], [ 'document-collection', 'image-collection' ])) {
                                    // go to edit
                                    return $this->redirect($this->generateUrl('resource-edit', [ 'volume' => $volume, 'id' => $id ]));
                                }

                                // go to upload
                                return $this->redirect($this->generateUrl('resource-upload', [ 'volume' => $volume, 'id' => $id ]));
                            }
                        }
                    }

                    $createFrom[$alternate] = \App\Utils\Iso639::nameByCode3($alternateCode3);
                }
            }

            if (!empty($createFrom)) {
                return $this->render('Resource/import.html.twig', [
                    'volume' => $volume,
                    'id' => $id,
                    'createFrom' => $createFrom,
                ]);
            }

            $request->getSession()
                    ->getFlashBag()
                    ->add('warning', 'No item found for id: ' . $id)
                ;

            return $this->redirect($this->generateUrl('volume-detail', [ 'id' => $volume ]));
        }

        $resourcePath = $client->getCollection() . '/' . $volume . '/' . $resource['data']['fname'];

        if ('resource-detail-dc' == $request->get('_route')) {
            return $this->teiToDublinCore($translator, $client, $resourcePath);
        }

        if (in_array($request->get('_route'), [ 'resource-detail-scalar', 'resource-embedded-media-scalar' ])) {
            return $this->teiToScalar($client, $resourcePath,
                                      $lang,
                                      null, 'resource-embedded-media-scalar' == $request->get('_route'));
        }

        if ('resource-detail-tei' == $request->get('_route')) {
            $tei = $client->getDocument($resourcePath, [ 'omit-xml-declaration' => 'no' ]);

            $response = new Response($tei);
            $response->headers->set('Content-Type', 'xml');

            return $response;
        }

        $html = null;
        $action = $request->request->get('action');

        if ($showAddEntities && isset($action) && in_array($action, [ 'link-entities', 'link-entities-save' ])) {
            if ($client->hasDocument($resourcePath)) {
                $tei = $client->getDocument($resourcePath);

                $teiEnhancer = new \App\Utils\TeiEnhancer([
                    'textRazorApiKey' => $textRazorApiKey,
                    'ignore' => [
                        'Q183' => [ '/^deutsch[esnr]*$/i', '/^german[s]*$/i' ],
                    ],
                ]);

                $tei = $teiEnhancer->addEntities($tei);

                if ('link-entities-save' == $action) {
                    $showAddEntities = 0;

                    $tei = $teiEnhancer->normalizeEntities($tei, function ($uri, $type) use ($client) {
                        return $this->normalizeEntityUri($this->getExistDbClient(), $uri, $type);
                    });

                    $res = $client->parse($tei, $resourcePath, true);

                    $this->addFlash('info', 'The resource has been updated');
                }
                else {
                    $showAddEntities = 2;
                    $this->addFlash('info', 'Please review the entities before pressing [Save Named Entities]');

                    $xql = $this->renderView('Resource/tei2html-string.xql.twig', []);
                    $query = $client->prepareQuery($xql);
                    $query->bindVariable('stylespath', $this->getStylesPath());
                    $query->bindVariable('tei', $tei);
                    $query->bindVariable('lang', $lang = \App\Utils\Iso639::code1To3($request->getLocale()));
                    $res = $query->execute();
                    $html = $res->getNextResult();
                    $res->release();
                }
            }
        }

        if (is_null($html)) {
            $html = $this->teiToHtml($client, $resourcePath, $lang);
        }

        // check whether TEI can be regenerated from CollectiveAccess
        $updateFromCollectiveAccess = $this->checkUpdateFromCollectiveAccess($caService, $html);
        if (!is_null($updateFromCollectiveAccess) && isset($action) && in_array($action, [ 'overwrite' ])) {
            $teiResponse = $this->forward('App\Controller\CollectiveAccessController::detailAction', [
                'id'  => $updateFromCollectiveAccess,
                '_route' => 'ca-detail-tei', // so we get TEI and not HTML
            ]);

            if ($teiResponse->isOk()) {
                $content = $teiResponse->getContent();

                $entity = $this->fetchTeiHeader($client, $resourcePath);

                $data = [
                    'id' => $entity->getId(),
                    'shelfmark' => $entity->getShelfmark(),
                    'slug' => $entity->getDtaDirName(),
                    'meta' => $entity->getMeta(),
                ];

                $this->updateTeiHeaderContent($client, $resourcePath, $content, $data);

                // refresh $html after update
                $html = $this->teiToHtml($client, $resourcePath, $lang);
            }
        }

        $html = $this->adjustHtml($html, $this->buildBaseUriMedia($volume, $id));

        if ('resource-detail-pdf' == $request->get('_route')) {
            $html = $this->render('Resource/printview.html.twig', [
                'name' => $this->teiToHtml($client, $resourcePath, $lang, '//tei:titleStmt/tei:title'),
                'volume' => $this->fetchVolume($client, $volume, $lang),
                'resource' => $resource,
                'html' => $html,
            ]);

            return $this->renderPdf($pdfConverter, $html, str_replace('.xml', '.pdf', $resource['data']['fname']), $request->getLocale());
        }

        if ('resource-detail-html' == $request->get('_route')) {
            // simple html for scalar export
            return $this->render('Resource/detail-no-chrome.html.twig', [
                'pageTitle' => $resource['data']['name'],
                'volume' => $this->fetchVolume($client, $volume, $lang),
                'resource' => $resource,
                'html' => $html,
            ]);
        }

        $parts = $this->extractPartsFromHtml($html);
        if (1 === $showAddEntities && !empty($parts['entities'])) {
            // currently avoid multiple calls to add linked entities
            $showAddEntities = 0;
        }

        // child resources
        $hasPart = $this->buildChildResources($client, $volume, $id, $lang);
        if (!empty($hasPart) && $request->isMethod('post')) {
            // check for updated order
            $postData = $request->request->get('order');
            if (!empty($postData)) {
                $updated = $this->reorderChildResources($client, $volume, $lang, $hasPart, $postData);
                if ($updated) {
                    $this->addFlash('info', 'The order has been updated');

                    // fetch again with new order
                    $hasPart = $this->buildChildResources($client, $volume, $id, $lang);
                }
            }
        }

        $entityLookup = $this->buildEntityLookup($parts['entities']);

        $terms = [];
        $uris = !empty($resource['data']['term'])
            ? (is_scalar($resource['data']['term']) ? [ $resource['data']['term'] ] : $resource['data']['term'])
            : [];

        foreach ($uris as $uri) {
            $term = $this->findTermByUri($uri);
            if (!is_null($term)) {
                $terms[] = $term;
            }
        }

        return $this->render('Resource/detail.html.twig', [
            'id' => $id,
            'volume' => $this->fetchVolume($client, $volume, $lang),
            'resource' => $resource,
            'hasPart' => $hasPart,
            'parentPath' => $this->buildParentPath($client, $resource, $lang),
            'webdav_base' => $this->buildWebDavBaseUrl($client),
            'titleHtml' => $this->teiToHtml($client, $resourcePath, $lang, '//tei:titleStmt/tei:title', true),
            'html' => $html,
            'terms' => $terms,
            'entity_lookup' => $entityLookup,
            'showAddEntities' => $showAddEntities,
            'updateFromCollectiveAccess' => $updateFromCollectiveAccess,
        ]);
    }

    protected function fetchTeiHeader(\ExistDbRpc\Client $client, $resourcePath)
    {
        if ($client->hasDocument($resourcePath)) {
            $content = $client->getDocument($resourcePath, [ 'omit-xml-declaration' => 'no' ]);

            return \App\Entity\TeiHeader::fromXmlString($content);
        }

        return null;
    }

    protected function updateTeiHeaderContent(\ExistDbRpc\Client $client, $resourcePath, $content, $data, $update = true)
    {
        $teiHelper = new \App\Utils\TeiHelper();
        $tei = $teiHelper->patchHeaderString($content, $data);

        $xml = $this->prettyPrintTei($tei->saveXML());

        return $client->parse((string)$xml, $resourcePath, $update);
    }

    /**
     * Naive implementation - fetches XML and updates it
     * Goal would be to use https://exist-db.org/exist/apps/doc/update_ext.xml instead
     */
    protected function updateTeiHeader(\ExistDbRpc\Client $client,
                                       $resourcePath,
                                       \App\Entity\TeiHeader $entity)
    {
        if ($client->hasDocument($resourcePath)) {
            $content = $client->getDocument($resourcePath, [ 'omit-xml-declaration' => 'no' ]);

            $data = [
                'title' => $entity->getTitle(),
                'translator' => $entity->getTranslator(),
                'slug' => $entity->getDtaDirName(),
                'terms' => $entity->getTerms(),
                'meta' => $entity->getMeta(),
            ];

            return $this->updateTeiHeaderContent($client, $resourcePath, $content, $data);
        }

        return false;
    }

    /**
     * Naive implementation - takes XML skeleton and updates it
     */
    protected function createTeiHeader(\ExistDbRpc\Client $client, $resourcePath, \App\Entity\TeiHeader $entity)
    {
        $content = $this->getTeiSkeleton();

        if (false === $content) {
            return false;
        }

        return $this->updateTeiHeaderContent($client, $resourcePath, $content, $entity->jsonSerialize(), false);
    }

    protected function generateShelfmark(\ExistDbRpc\Client $client, $volumeId, $lang, $id)
    {
        $prefix = preg_replace('/(\-)\d+$/', '\1', $id);
        $collection = $client->getCollection();

        $xql = <<<EOXQL
    declare default element namespace "http://www.tei-c.org/ns/1.0";

    declare variable \$collection external;
    declare variable \$volume external;
    declare variable \$prefix external;

    fn:head(
        for \$resource in collection(\$collection)/TEI
        where fn:starts-with(util:document-name(\$resource), \$prefix)
        and fn:contains(\$resource//idno['shelfmark' = @type]/text(), \$volume)
        order by \$resource//idno['shelfmark' = @type]/text() descending
        return \$resource//idno['shelfmark' = @type]/text())

EOXQL;

        $query = $client->prepareQuery($xql);
        $query->bindVariable('collection', $collection);
        $query->bindVariable('volume', $volumeId);
        $query->bindVariable('prefix', $prefix);
        $res = $query->execute();

        $shelfmarkHighest = null;
        if ($res->valid()) {
            $shelfmarkHighest = $res->getNextResult();
        }
        $res->release();

        $counter = 1;
        if (!is_null($shelfmarkHighest) && preg_match('/(.*)\/(\d+)(\:[^\/]+)$/', $shelfmarkHighest, $matches)) {
            $counter = (int)$matches[2] + 1;
        }

        $volume = $this->fetchVolume($client, $volumeId, $lang);

        $shelfmark = sprintf('%s/%03d:%s',
                             $volume['data']['shelfmark'], $counter, $id);

        return $shelfmark;
    }

    protected function findTermByUri($uri)
    {
        static $registered = false;

        if (!$registered) {
            \App\Utils\Lod\Identifier\Factory::register(\App\Utils\Lod\Identifier\GndIdentifier::class);
            \App\Utils\Lod\Identifier\Factory::register(\App\Utils\Lod\Identifier\LocLdsSubjectsIdentifier::class);
            \App\Utils\Lod\Identifier\Factory::register(\App\Utils\Lod\Identifier\WikidataIdentifier::class);

            $registered = true;
        }

        $identifier = \App\Utils\Lod\Identifier\Factory::fromUri($uri);

        if (!is_null($identifier)) {
            return $this->findTermByIdentifier($identifier->getValue(), $identifier->getPrefix(), true);
        }
    }

    /**
     * @Route("/resource/{volume}/{id}/edit", name="resource-edit",
     *          requirements={"volume" = "volume\-\d+", "id" = "(introduction|chapter|document|image|audio|video|map)\-\d+"})
     * @Route("/resource/{volume}/add/{genre}", name="collection-add",
     *          requirements={"volume" = "volume\-\d+", "genre" = "(document-collection|image-collection)"})
     */
    public function editAction(Request $request,
                               TranslatorInterface $translator,
                               $volume, $id = null, $genre = null)
    {
        $update = 'resource-edit' == $request->get('_route');

        $client = $this->getExistDbClient($this->subCollection);
        $lang = \App\Utils\Iso639::code1To3($request->getLocale());

        $titleHtml = null;
        if (!is_null($id)) {
            $resourcePath = $client->getCollection() . '/' . $volume . '/' . $id . '.' . $lang . '.xml';

            $entity = $this->fetchTeiHeader($client, $resourcePath);

            $titleHtml = $this->teiToHtml($client, $resourcePath, $lang, '//tei:titleStmt/tei:title', true);
        }
        else {
            $entity = null;
        }

        if (is_null($entity)) {
            if (is_null($id)) {
                $entity = new \App\Entity\TeiHeader();
            }
            else {
                $request->getSession()
                        ->getFlashBag()
                        ->add('warning', 'No entry found for id: ' . $id)
                    ;

                return $this->redirect($this->generateUrl('resource-detail', [ 'volume' => $volume, 'id' => $id ]));
            }
        }

        $formOptions = [];
        // no terms for collections
        if ('collection-add' != $request->get('_route')) {
            $formOptions['choices'] = [
                'terms' => array_flip($this->buildTermChoices($request->getLocale(), $entity)),
                'meta' => array_flip($this->buildMetaChoices($translator, $entity)),
            ];
        }

        $form = $this->createForm(\App\Form\Type\TeiHeaderType::class, $entity, $formOptions);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if (!$update) {
                $lang = \App\Utils\Iso639::code1To3($request->getLocale());

                $id = $this->nextInSequence($client, $client->getCollection(), $prefix = 'chapter-');

                $resourcePath = $client->getCollection() . '/' . $volume . '/' . $id . '.' . $lang . '.xml';

                $entity->setId($this->siteKey . ':' . $id);
                $entity->setLanguage($lang);
                $entity->setGenre($genre);

                $shelfmark = $this->generateShelfmark($client, $volume, $lang, $id);
                $entity->setShelfmark($shelfmark);

                $res = $this->createTeiHeader($client, $resourcePath, $entity);
            }
            else {
                $res = $this->updateTeiHeader($client, $resourcePath, $entity);
            }

            $redirectUrl = $this->generateUrl('resource-detail', [ 'volume' => $volume, 'id' => $id ]);

            if (!$res) {
                $request->getSession()
                        ->getFlashBag()
                        ->add('warning', 'An issue occured while storing id: ' . $id)
                    ;
            }
            else {
                if ($request->getSession()->has('return-after-save')) {
                    $redirectUrl = $this->generateUrl($request->getSession()->remove('return-after-save'));
                }

                $request->getSession()
                        ->getFlashBag()
                        ->add('info', 'Entry ' . ($update ? ' updated' : ' created'));
                    ;
            }

            return $this->redirect($redirectUrl);
        }

        return $this->render('Resource/edit.html.twig', [
            'form' => $form->createView(),
            'id' => $id,
            'entity' => $entity,
            'volume' => $this->fetchVolume($client, $volume, $lang),
            'parentPath' => $this->buildParentPath($client,  $this->fetchResource($client, isset($id) ? $id : $volume, $lang), $lang),
            'titleHtml' => $titleHtml,
        ]);
    }

    private function word2doc(\App\Utils\PandocConverter $pandocConverter, $fname, $locale)
    {
        $officeDoc = new \App\Utils\BinaryDocument();
        $officeDoc->load($fname);

        // inject TeiFromWordCleaner
        $myTarget = new class()
        extends \App\Utils\TeiSimplePrintDocument
        {
            use \App\Utils\TeiFromWordCleaner;
        };

        $pandocConverter->setOption('target', $myTarget);

        $teiSimpleDoc = $pandocConverter->convert($officeDoc);

        $conversionOptions = [
            'prettyPrinter' => $this->getTeiPrettyPrinter(),
            'language' => \App\Utils\Iso639::code1to3($locale),
            'genre' => 'document', // todo: make configurable
        ];

        $converter = new \App\Utils\TeiSimplePrintToDtabfConverter($conversionOptions);
        $teiDtabfDoc = $converter->convert($teiSimpleDoc);

        return $teiDtabfDoc;
    }

    /**
     * @Route("/resource/{volume}/{id}/add", name="resource-upload-child",
     *        requirements={"volume" = "volume\-\d+", "id" = "(chapter|document)\-\d+"})
     * @Route("/resource/{volume}/add/{id}", name="resource-add-introduction",
     *        requirements={"volume" = "volume\-\d+", "id" = "(introduction)"})
     * @Route("/resource/{volume}/{id}/upload", name="resource-upload",
     *          requirements={"volume" = "volume\-\d+", "id" = "(introduction|document|image|audio|video)\-\d+"})
     */
    public function uploadAction(Request $request,
                                 \App\Utils\PandocConverter $pandocConverter,
                                 $volume, $id)
    {
        $update = 'resource-upload' == $request->get('_route');

        $client = $this->getExistDbClient($this->subCollection);

        $lang = \App\Utils\Iso639::code1To3($request->getLocale());
        $resourceId = $id;
        if ('introduction' == $id) {
            // TODO: check if there is already an introduction for $volume
            // if yes, redirect
            $resourceId = $volume;
        }

        $resourcePath = $client->getCollection() . '/' . $volume . '/' . $resourceId . '.' . $lang . '.xml';

        $entity = $this->fetchTeiHeader($client, $resourcePath);
        if (is_null($entity)) {
            $request->getSession()
                    ->getFlashBag()
                    ->add('warning', 'No item found for id: ' . $resourceId)
                ;

            return $this->redirect($this->generateUrl('volume-detail', [ 'id' => $volume ]));
        }

        if ($request->isMethod('post')) {
            $file = $request->files->get('file');
            if (is_null($file)) {
                $request->getSession()
                        ->getFlashBag()
                        ->add('warning', 'No upload found, please try again')
                    ;
            }
            else {
                $mime = $file->getMimeType();
                if (!in_array($mime, [
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'application/docx',
                        'text/xml',
                    ]))
                {
                    $request->getSession()
                            ->getFlashBag()
                            ->add('error', "Uploaded file wasn't recognized as a Word-File (.docx) or as a TEI-File (.xml)")
                        ;
                }
                else {
                    $genre = 'document';

                    // we want to carry over some of the existing metadata
                    // TODO: we need to switch to the opposite logic
                    // as in edit-mode where we start with the original
                    // and update certain properties from upload
                    // in order to keep additional metadata by default
                    $terms = [];
                    $meta = [];
                    $authors = [];
                    $dtaDirname = null;

                    if ('text/xml' == $mime) {
                        $teiDtabfDoc = new \App\Utils\TeiDocument([
                            'prettyPrinter' => $this->getTeiPrettyPrinter(),
                        ]);

                        $success = $teiDtabfDoc->load($file->getRealPath());
                        if (!$success) {
                            $request->getSession()
                                    ->getFlashBag()
                                    ->add('error', "There was an error loading the upload")
                                ;
                            $teiDtabfDoc = false;
                        }
                        else {
                            $teiDtabfDoc->prettify();

                            if ($update) {
                                $terms = $entity->getTerms();
                            }

                            // genre can be both document and image
                            $newEntity = \App\Entity\TeiHeader::fromXmlString((string)$teiDtabfDoc);
                            if (!is_null($newEntity)) {
                                $genre = $newEntity->getGenre();
                                $newTerms = $newEntity->getTerms();
                                if (!empty($newTerms)) {
                                    // image may have Terms embedded from Collective Access, use these
                                    $terms = $newTerms;
                                }
                            }
                        }
                    }
                    else {
                        $teiDtabfDoc = $this->word2doc($pandocConverter, $file->getRealPath(), $request->getLocale());

                        if (false === $teiDtabfDoc) {
                            $request->getSession()
                                    ->getFlashBag()
                                    ->add('error', "There was an error converting the upload")
                                ;
                        }

                        if ($update) {
                            $authors = $entity->getAuthors();
                            $terms = $entity->getTerms();
                            $genre = $entity->getGenre();
                        }
                    }

                    if (false !== $teiDtabfDoc) {
                        $valid = $teiDtabfDoc->validate($this->getProjectDir() . '/data/schema/basisformat.rng');

                        if (!$valid) {
                            $request->getSession()
                                    ->getFlashBag()
                                    ->add('warning', 'The conversion was successful, but there might be an issue with the structure of the result')
                                ;
                        }

                        $meta = [];
                        $slug = null;

                        if ($update) {
                            $resourceId = $id;
                            $shelfmark = $entity->getShelfmark();
                            $meta = $entity->getMeta();
                            $slug = $entity->getDtaDirName();
                        }
                        else {
                            $resourceId = $this->nextInSequence($client, $client->getCollection(), $prefix = $genre . '-');

                            // shelf-mark - append at the end
                            $counter = 1;

                            $hasPart = $this->buildChildResources($client, $volume, $id, $lang);
                            if (!empty($hasPart)) {
                                $lastChild = end($hasPart);
                                $parts = explode('/', $lastChild['shelfmark']);
                                if (preg_match('/^(\d+)\:/', end($parts), $matches)) {
                                    $counter = $matches[1] + 1;
                                }
                            }

                            $shelfmark = implode('/', [
                                $entity->getShelfmark(),
                                sprintf('%03d:%s',
                                        $counter, $resourceId),
                            ]);
                        }

                        /*
                         * TODO: bring update logic
                         * in line with edit-method
                         * where we call updateTeiHeader()
                         * instead of trying to carry everything over
                         */
                        $teiHelper = new \App\Utils\TeiHelper();
                        $teiHelper->patchHeaderStructure($teiDtabfDoc->getDom(), [
                            'id' => $this->siteKey . ':' . $resourceId,
                            'authors' => $authors,
                            'shelfmark' => $shelfmark,
                            'slug' => $slug,
                            'genre' => $genre,
                            'terms' => $terms,
                            'meta' => $meta,
                        ]);

                        $resourcePath = $client->getCollection() . '/' . $volume . '/' . $resourceId . '.' . $lang . '.xml';

                        $res = $client->parse((string)$teiDtabfDoc, $resourcePath, $update);

                        if ($res) {
                            $request->getSession()
                                    ->getFlashBag()
                                    ->add('info',
                                          $update ? 'The Entry has been updated' : 'The Entry has been created')
                                ;

                            return $this->redirect($this->generateUrl('resource-detail', [ 'volume' => $volume, 'id' => $resourceId ]));
                        }

                        $request->getSession()
                                ->getFlashBag()
                                ->add('error', 'The new Entry could not be created')
                            ;
                    }
                }
            }
        }

        return $this->render('Resource/upload.html.twig', [
            'entity' => $entity,
            'name' => $this->teiToHtml($client, $resourcePath, $lang, '//tei:titleStmt/tei:title'),
            'volume' => $volume,
            'id' => $id,
            'parentPath' => $this->buildParentPath($client,  $this->fetchResource($client, $resourceId, $lang), $lang),
        ]);
    }
}
