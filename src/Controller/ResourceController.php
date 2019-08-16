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

        return $resources['data'];
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
     */
    public function detailAction(Request $request, $volume, $id)
    {
        $textRazorApiKey = $this->container->hasParameter('app.textrazor')
            ? $this->getParameter('app.textrazor')['api_key']
            : null;
        $showAddEntities = !empty($textRazorApiKey) ? 1 : 0;

        $client = $this->getExistDbClient($this->subCollection);

        $xql = $this->renderView('Resource/detail-json.xql.twig', [
            'prefix' => $this->siteKey,
        ]);

        $query = $client->prepareQuery($xql);
        $query->setJSONReturnType();
        $query->bindVariable('collection', $client->getCollection());
        $query->bindVariable('id', implode(':', [ $this->siteKey,  $id ]));
        $query->bindVariable('lang', $lang = \App\Utils\Iso639::code1To3($request->getLocale()));
        $res = $query->execute();
        $resource = $res->getNextResult();
        $res->release();
        if (is_null($resource)) {
            $request->getSession()
                    ->getFlashBag()
                    ->add('warning', 'No item found for id: ' . $id)
                ;

            return $this->redirect($this->generateUrl('volume-list'));
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
            $tei = $client->getDocument($resourcePath);

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

        $html = $this->adjustHtml($html);

        if ('resource-detail-pdf' == $request->get('_route')) {
            $templating = $this->container->get('templating');

            $html = $templating->render('Resource/printview.html.twig', [
                'name' => $this->teiToHtml($client, $resourcePath, $lang, '//tei:titleStmt/tei:title'),
                'volume' => $this->fetchVolume($client, $volume, $lang),
                'resource' => $resource,
                'html' => $html,
            ]);

            $this->renderPdf($html, str_replace('.xml', '.pdf', $resource['data']['fname']), 'I', $request->getLocale());

            return;
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

        $entityLookup = $this->buildEntityLookup($parts['entities']);

        // child resources
        $hasPart = $this->buildChildResources($client, $volume, $id, $lang);
        if (!empty($hasPart) && $request->isMethod('post')) {
            // check for updated order
            $postData = $request->request->get('order');
            if (!empty($postData)) {
                $order = json_decode($postData, true);
                if (false !== $order) {
                    $newOrder = [];
                    $count = 0;
                    foreach ($order as $childId) {
                        $newOrder[$childId] = ++$count;
                    }

                    $updated = false;
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

                    if ($updated) {
                        $this->addFlash('info', 'The order has been updated');

                        // fetch again with new order
                        $hasPart = $this->buildChildResources($client, $volume, $id, $lang);
                    }
                }
            }
        }

        return $this->render('Resource/detail.html.twig', [
            'id' => $id,
            'volume' => $this->fetchVolume($client, $volume, $lang),
            'resource' => $resource,
            'hasPart' => $hasPart,
            'webdav_base' => $this->buildWebDavBaseUrl($client),
            'titleHtml' => $this->teiToHtml($client, $resourcePath, $lang, '//tei:titleStmt/tei:title'),
            'html' => $html,
            'entity_lookup' => $entityLookup,
            'showAddEntities' => $showAddEntities,
        ]);
    }

    protected function fetchTeiHeader($client, $resourcePath)
    {
        if ($client->hasDocument($resourcePath)) {
            $content = $client->getDocument($resourcePath);

            $teiHelper = new \App\Utils\TeiHelper();
            $article = $teiHelper->analyzeHeaderString($content, true);

            if (false === $article) {
                return null;
            }

            // TODO: add additional properties
            $entity = new \App\Entity\TeiHeader();
            $entity->setId($article->uid);
            $entity->setTitle($article->name);
            $entity->setShelfmark($article->shelfmark);
            $entity->setGenre($article->genre);

            return $entity;
        }

        return null;
    }

    private function updateTeiHeaderContent($client, $resourcePath, $content, $data, $update = true)
    {
        $teiHelper = new \App\Utils\TeiHelper();
        $content = $teiHelper->adjustHeaderString($content, $data);

        return $client->parse($content->saveXML(), $resourcePath, $update);
    }

    /**
     * Naive implementation - fetches XML and updates it
     * Goal would be to use https://exist-db.org/exist/apps/doc/update_ext.xml instead
     */
    protected function updateTeiHeader($entity, $client, $resourcePath)
    {
        if ($client->hasDocument($resourcePath)) {
            $content = $client->getDocument($resourcePath);

            $data = [
                'title' => $entity->getTitle(),
            ];

            return $this->updateTeiHeaderContent($client, $resourcePath, $content, $data);
        }

        return false;
    }

    /*
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

    /**
     * @Route("/resource/{volume}/{id}/edit", name="resource-edit",
     *          requirements={"volume" = "volume\-\d+", "id" = "(introduction|chapter|document|image|map)\-\d+"})
     * @Route("/resource/{volume}/add/{genre}", name="collection-add",
     *          requirements={"volume" = "volume\-\d+", "genre" = "(document-collection|image-collection)"})
     */
    public function editAction(Request $request, $volume, $id = null, $genre = null)
    {
        $update = 'resource-edit' == $request->get('_route');

        $client = $this->getExistDbClient($this->subCollection);

        if (!is_null($id)) {
            $lang = \App\Utils\Iso639::code1To3($request->getLocale());
            $resourcePath = $client->getCollection() . '/' . $volume . '/' . $id . '.' . $lang . '.xml';

            $entity = $this->fetchTeiHeader($client, $resourcePath);
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

        $form = $this->get('form.factory')
                ->create(\App\Form\Type\TeiHeaderType::class, $entity)
                ;
        if ($request->getMethod() == 'POST') {
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
        }

        return $this->render('Resource/edit.html.twig', [
            'form' => $form->createView(),
            'entity' => $entity,
            'volume' => $volume,
            'id' => $id,
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
                            $teiHelper = new \App\Utils\TeiHelper();
                            $article = $teiHelper->analyzeHeaderString((string)$teiDtabfDoc);
                            $genre = $article->genre;
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
        ]);
    }

    /**
     * @Route("/test/binary")
     */
    public function testAction(Request $request)
    {
        $client = $this->getExistDbClient($this->subCollection);
        $path = $this->getAssetsPath();

        header('Content-type: image/jpeg');
        echo $client->getBinaryResource($path . '/logo-print.de.jpg');

        exit;
    }
}
