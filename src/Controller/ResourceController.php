<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 *
 */
class ResourceController
extends BaseController
{
    use \App\Utils\RenderTeiTrait;

    protected $subCollection = '/data/volumes';

    /**
     * Make unique across language so we can line-up different languages under same id
     */
    protected function nextInSequence($client, $collection, $prefix)
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

    protected function buildChildResources($client, $volumeId, $id, $lang)
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

    protected function buildParentPath($client, $resource, $lang)
    {
        $parts = explode('/', $resource['data']['shelfmark']);
        array_shift($parts); // pop site prefix

        // split of order within
        $parts = array_map(function ($orderId) {
            list($order, $id) = explode(':', $orderId, 2);

            return $id;
        }, $parts);

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

    protected function updateDocumentShelfmark($client, $resource, $shelfmark)
    {
        $xql = $this->renderView('Resource/update-shelfmark.xql.twig', [
        ]);

        $query = $client->prepareQuery($xql);
        $query->bindVariable('resource', $resource);
        $query->bindVariable('shelfmark', $shelfmark);
        $res = $query->execute();
        $res->release();

        return true;
    }

    protected function reorderChildResources($client, $volume, $lang, $hasPart, $postData)
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
                    $parts = explode('/', $child['shelfmark']);
                    list($order, $ignore) = explode(':', end($parts), 2);
                    if ($order != $newOrder) {
                        $newOrderAndId = sprintf('%03d:%s',
                                                 $newOrder[$childId], $childId);
                        $parts[count($parts) - 1] = $newOrderAndId;
                        $newShelfmark = implode('/', $parts);

                        if ($child['shelfmark'] != $newShelfmark) {
                            $this->updateDocumentShelfmark($client,
                                                           $client->getCollection() . '/' . $volume . '/' . $childId . '.' . $lang . '.xml',
                                                           $newShelfmark);

                            $updated = true;
                        }
                    }
                }
            }
        }

        return $updated;
    }

    /**
     * @Route("/resource/{volume}/{id}.dc.xml", name="resource-detail-dc",
     *          requirements={"volume" = "volume\-\d+", "id" = "(introduction|chapter|document|image|map|)\-\d+"})
     * @Route("/resource/{volume}/{id}.scalar.json", name="resource-detail-scalar",
     *          requirements={"volume" = "volume\-\d+", "id" = "(introduction|chapter|document|image|map)\-\d+"})
     * @Route("/resource/{volume}/{id}/media.scalar.json", name="resource-embedded-media-scalar",
     *          requirements={"volume" = "volume\-\d+", "id" = "(introduction|chapter|document|image|map)\-\d+"})
     * @Route("/resource/{volume}/{id}.tei.xml", name="resource-detail-tei",
     *          requirements={"volume" = "volume\-\d+", "id" = "(introduction|chapter|document|image|map)\-\d+"})
     * @Route("/resource/{volume}/{id}.pdf", name="resource-detail-pdf",
     *          requirements={"volume" = "volume\-\d+", "id" = "(introduction|chapter|document|image|map)\-\d+"})
     * @Route("/resource/{volume}/{id}.html", name="resource-detail-html",
     *          requirements={"volume" = "volume\-\d+", "id" = "(introduction|chapter|document|image|map)\-\d+"})
     * @Route("/resource/{volume}/{id}", name="resource-detail",
     *          requirements={"volume" = "volume\-\d+", "id" = "(introduction|chapter|document|image|map)\-\d+"})
     * @Route("/resource/{volume}/{id}", name="resource-create",
     *          requirements={"volume" = "volume\-\d+", "id" = "(introduction|chapter|document|image|map)\-\d+"})
     */
    public function detailAction(Request $request,
                                 \App\Utils\MpdfConverter $pdfConverter,
                                 $volume, $id)
    {
        $textRazorApiKey = $this->container->hasParameter('app.textrazor')
            ? $this->getParameter('app.textrazor')['api_key']
            : null;
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
            return $this->teiToDublinCore($client, $resourcePath);
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
                'volume' => $this->fetchVolume($client, $volume, $id, $lang),
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
        ]);
    }

    protected function fetchTeiHeader($client, $resourcePath)
    {
        if ($client->hasDocument($resourcePath)) {
            $content = $client->getDocument($resourcePath, [ 'omit-xml-declaration' => 'no' ]);

            return \App\Entity\TeiHeader::fromXmlString($content);
        }

        return null;
    }

    protected function updateTeiHeaderContent($client, $resourcePath, $content, $data, $update = true)
    {
        $teiHelper = new \App\Utils\TeiHelper();
        $content = $teiHelper->adjustHeaderString($content, $data);

        $xml = $this->prettyPrintTei($content->saveXML());

        return $client->parse((string)$xml, $resourcePath, $update);
    }

    /**
     * Naive implementation - fetches XML and updates it
     * Goal would be to use https://exist-db.org/exist/apps/doc/update_ext.xml instead
     */
    protected function updateTeiHeader($entity, $client, $resourcePath)
    {
        if ($client->hasDocument($resourcePath)) {
            $content = $client->getDocument($resourcePath, [ 'omit-xml-declaration' => 'no' ]);

            $data = [
                'title' => $entity->getTitle(),
                'translator' => $entity->getTranslator(),
                'slug' => $entity->getDtaDirName(),
                'terms' => $entity->getTerms(),
            ];

            return $this->updateTeiHeaderContent($client, $resourcePath, $content, $data);
        }

        return false;
    }

    /**
     * Naive implementation - takes XML skeleton and updates it
     */
    protected function createTeiHeader($entity, $client, $resourcePath)
    {
        $content = $this->getTeiSkeleton();

        if (false === $content) {
            return false;
        }

        $data = $entity->jsonSerialize();

        return $this->updateTeiHeaderContent($client, $resourcePath, $content, $data, false);
    }

    protected function generateShelfmark($client, $volumeId, $lang, $id)
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

    protected function buildTermChoices($locale, $entity = null)
    {
        $client = $this->getExistDbClient($this->authorityPaths['terms']);

        $xql = $this->renderView('Term/list-choices-json.xql.twig', [
        ]);

        $query = $client->prepareQuery($xql);
        $query->setJSONReturnType();
        $query->bindVariable('collection', $client->getCollection());
        $query->bindVariable('locale', $locale);
        $query->bindVariable('q', '');
        $res = $query->execute();
        $terms = $res->getNextResult();
        $res->release();

        $choices = [];

        foreach ($terms['data'] as $term) {
            $name = $term['name'];
            $value = null;

            foreach ([ 'gnd', 'lcauth', 'wikidata' ] as $vocabulary) {
                $identifier = null;

                if (!empty($term[$vocabulary])) {
                    switch ($vocabulary) {
                        case 'gnd':
                            $identifier = new \App\Utils\Lod\Identifier\GndIdentifier();
                            break;

                        case 'lcauth':
                            $identifier = new \App\Utils\Lod\Identifier\LocLdsSubjectsIdentifier();
                            break;

                        case 'wikidata':
                            $identifier = new \App\Utils\Lod\Identifier\WikidataIdentifier();
                            break;
                    }

                    if (!is_null($identifier)) {
                        $identifier->setValue($term[$vocabulary]);
                        if (is_null($value)) {
                            $value = $identifier->toUri();
                        }
                        else {
                            // TODO: check $entity->getTerms()
                        }
                    }
                }
            }

            if (is_null($value)) {
                $value = $name;
            }

            $choices[$value] = $name;
        }

        return $choices;
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
     *          requirements={"volume" = "volume\-\d+", "id" = "(introduction|chapter|document|image|map)\-\d+"})
     * @Route("/resource/{volume}/add/{genre}", name="collection-add",
     *          requirements={"volume" = "volume\-\d+", "genre" = "(document-collection|image-collection)"})
     */
    public function editAction(Request $request,
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
                'terms' => array_flip($this->buildTermChoices($request->getLocale(), $entity))
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

                $res = $this->createTeiHeader($entity, $client, $resourcePath);
            }
            else {
                $res = $this->updateTeiHeader($entity, $client, $resourcePath);
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

    private function word2doc($fname, $locale)
    {
        $officeDoc = new \App\Utils\BinaryDocument();
        $officeDoc->load($fname);

        $pandocConverter = $this->get(\App\Utils\PandocConverter::class);

        // inject TeiFromWordCleaner
        $myTarget = new class()
        extends \App\Utils\TeiSimplePrintDocument
        {
            use \App\Utils\TeiFromWordCleaner;
        };

        $pandocConverter->setOption('target', $myTarget);

        $teiSimpleDoc = $pandocConverter->convert($officeDoc);

        $conversionOptions = [
            'prettyPrinter' => $this->get('app.tei-prettyprinter'),
            'language' => \App\Utils\Iso639::code1to3($locale),
            'genre' => 'document', // todo: make configurable
        ];

        $converter = new \App\Utils\TeiSimplePrintToDtabfConverter($conversionOptions);
        $teiDtabfDoc = $converter->convert($teiSimpleDoc);

        return $teiDtabfDoc;
    }

    /**
     * @Route("/resource/{volume}/{id}/upload", name="resource-upload-child",
     *        requirements={"volume" = "volume\-\d+", "id" = "(chapter)\-\d+"})
     * @Route("/resource/{volume}/{id}/upload", name="resource-upload",
     *          requirements={"volume" = "volume\-\d+", "id" = "(introduction|document|image)\-\d+"})
     */
    public function uploadAction(Request $request, $volume, $id)
    {
        $update = 'resource-upload' == $request->get('_route');

        $client = $this->getExistDbClient($this->subCollection);

        $lang = \App\Utils\Iso639::code1To3($request->getLocale());
        $resourcePath = $client->getCollection() . '/' . $volume . '/' . $id . '.' . $lang . '.xml';

        $entity = $this->fetchTeiHeader($client, $resourcePath);
        if (is_null($entity)) {
            $request->getSession()
                    ->getFlashBag()
                    ->add('warning', 'No item found for id: ' . $id)
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
                    $terms = []; // for word-upload, we want to carry the existing ones over

                    if ('text/xml' == $mime) {
                        $teiDtabfDoc = new \App\Utils\TeiDocument([
                            'prettyPrinter' => $this->get('app.tei-prettyprinter'),
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

                            // genre can be both document and image
                            $entity = \App\Entity\TeiHeader::fromXmlString((string)$teiDtabfDoc);
                            if (!is_null($entity)) {
                                $genre = $entity->getGenre();
                            }
                        }
                    }
                    else {
                        $teiDtabfDoc = $this->word2doc($file->getRealPath(), $request->getLocale());
                        if (false === $teiDtabfDoc) {
                            $request->getSession()
                                    ->getFlashBag()
                                    ->add('error', "There was an error converting the upload")
                                ;
                        }

                        if ($update) {
                            $terms = $entity->getTerms();
                        }
                    }

                    if (false !== $teiDtabfDoc) {
                        $valid = $teiDtabfDoc->validate($this->get('kernel')->getProjectDir() . '/data/schema/basisformat.rng');

                        if (!$valid) {
                            $request->getSession()
                                    ->getFlashBag()
                                    ->add('warning', 'The conversion was successful, but there might be an issue with the structure of the result')
                                ;
                        }

                        if ($update) {
                            $resourceId = $id;
                            $shelfmark = $entity->getShelfmark();
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

                        $teiHelper = new \App\Utils\TeiHelper();
                        $teiHelper->adjustHeaderStructure($teiDtabfDoc->getDom(), [
                            'id' => $this->siteKey . ':' . $resourceId,
                            'shelfmark' => $shelfmark,
                            'terms' => $terms,
                        ]);

                        $resourcePath = $client->getCollection() . '/' . $volume . '/' . $resourceId . '.' . $lang . '.xml';

                        $res = $client->parse((string)$teiDtabfDoc, $resourcePath, $update);

                        if ($res) {
                            $request->getSession()
                                    ->getFlashBag()
                                    ->add('info', 'The Entry has been created')
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
            'parentPath' => $this->buildParentPath($client,  $this->fetchResource($client, $id, $lang), $lang),
        ]);
    }

    /**
     * @Route("/test/cetei")
     *
     * Simple test for https://github.com/TEIC/CETEIcean
     *
     * Grab CETEI.js from https://github.com/TEIC/CETEIcean/releases
     *
     */
    public function testCeteiAction(Request $request)
    {
        return $this->render('Resource/cetei.html.twig', [
            'volume' => 'volume-2',
            'id' => 'document-5',
        ]);
    }
}
