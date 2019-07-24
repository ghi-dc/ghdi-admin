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
                                      \App\Utils\Iso639::code1To3($request->getLocale()),
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
            $xql = $this->renderView('Resource/tei2html.xql.twig', [
            ]);
            $query = $client->prepareQuery($xql);
            $query->bindVariable('stylespath', $this->getStylesPath());
            $resourcePath = $client->getCollection() . '/' . $volume . '/' . $resource['data']['fname'];
            $query->bindVariable('resource', $resourcePath);
            $query->bindVariable('lang', $lang = \App\Utils\Iso639::code1To3($request->getLocale()));
            $res = $query->execute();
            $html = $res->getNextResult();
            $res->release();
        }

        $html = $this->adjustHtml($html);

        if ('resource-detail-pdf' == $request->get('_route')) {
            $templating = $this->container->get('templating');

            $html = $templating->render('Resource/printview.html.twig', [
                'name' => $resource['data']['name'],
                'volume' => $this->fetchVolume($client, $volume, $lang),
                'resource' => $resource,
                'html' => $html,
                // TODO:
                // 'authors' => $authors,
                // 'license' => $license,
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

        return $this->render('Resource/detail.html.twig', [
            'id' => $id,
            'volume' => $this->fetchVolume($client, $volume, $lang),
            'resource' => $resource,
            'hasPart' => $hasPart,
            'webdav_base' => $this->buildWebDavBaseUrl($client),
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

            $entity = new \App\Entity\TeiHeader();
            $entity->setTitle($article->name);

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
        $shelfmarkHighest = $res->getNextResult();
        $res->release();

        $counter = 1;
        if (preg_match('/(.*)\/(\d+)(\:[^\/]+)$/', $shelfmarkHighest, $matches)) {
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

    /**
     * @Route("/test/collection", name="test-collection")
     */
    public function testAction(Request $request)
    {
        $data = [
            // 'myCollection' => ['a', 'b', 'c'],
        ];

        $form = $this->get('form.factory')
                ->create(\App\Form\Type\CollectionTestType::class, $data, [])
                ;

        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                $data = $form->getData();
            }
        }

        return $this->render('Resource/collection-test.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
