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

    protected function buildResources($client, $id, $lang)
    {
        $xql = $this->renderView('Volume/list-resources-json.xql.twig', [
        ]);

        $query = $client->prepareQuery($xql);
        $query->setJSONReturnType();
        $query->bindVariable('collection', $client->getCollection() . '/' . $id);
        $query->bindVariable('lang', $lang);
        $res = $query->execute();
        $resources = $res->getNextResult();
        $res->release();

        return $resources;
    }

    protected function renderPdf($html, $filename = '', $dest = 'I', $locale = 'en')
    {
        /*
        // for debugging
        echo $html;
        exit;
        */

        // mpdf
        $pdfGenerator = new \App\Utils\PdfGenerator([
            'fontDir' => [
                $this->get('kernel')->getProjectDir()
                    . '/data/font',
            ],
            'fontdata' => [
                'gentium' => [
                    'R' => 'GenBasR.ttf',
                    'B' => 'GenBasB.ttf',
                    'I' => 'GenBasI.ttf',
                    'BI' => 'GenBasBI.ttf',
                ],
            ],
            'default_font' => 'gentium',
        ]);

        /*
        // hyphenation
        list($lang, $region) = explode('_', $display_lang, 2);
        $pdfGenerator->SHYlang = $lang;
        $pdfGenerator->SHYleftmin = 3;
        */

        // imgs
        $fnameLogo = $this->get('kernel')->getProjectDir() . '/public/img/logo-small.' . $locale . '.gif';
        $pdfGenerator->imageVars['logo_top'] = file_get_contents($fnameLogo);

        // silence due to https://github.com/mpdf/mpdf/issues/302 when using tables
        @$pdfGenerator->writeHTML($html);

        $pdfGenerator->Output($filename, 'I');
    }

    protected function adjustMedia($html, $baseUrl, $imgClass = 'image-responsive')
    {
        $crawler = new \Symfony\Component\DomCrawler\Crawler();
        $crawler->addHtmlContent($html);

        $crawler->filter('audio > source')->each(function ($node, $i) use ($baseUrl) {
            $src = $node->attr('src');
            $node->getNode(0)->setAttribute('src', $baseUrl . '/' . $src);
        });

        $crawler->filter('video > source')->each(function ($node, $i) use ($baseUrl) {
            $src = $node->attr('src');
            $node->getNode(0)->setAttribute('src', $baseUrl . '/' . $src);
        });

        $crawler->filter('img')->each(function ($node, $i) use ($baseUrl, $imgClass) {
            $src = $node->attr('src');
            $node->getNode(0)->setAttribute('src', $baseUrl . '/' . $src);
            if (!empty($imgClass)) {
                $node->getNode(0)->setAttribute('class', $imgClass);
            }
        });

        return $crawler->html();
    }

    protected function adjustHtml($html)
    {
        // run even if there is nothing to remove since xslt creates
        // self-closing tags like <div/> which are not valid in HTML5
        $html = $this->removeByCssSelector($html, [
            // 'h2 + br',
            // 'h3 + br',
            // 'div#license',
        ], true);

        $html = $this->adjustMedia($html, 'http://germanhistorydocs.ghi-dc.org/images/');

        return $html;
    }

    /**
     * @Route("/resource/{volume}/{id}.dc.xml", name="resource-detail-dc",
     *          requirements={"volume" = "volume\-\d+", "id" = "(introduction|document|image|map)\-\d+"})
     * @Route("/resource/{volume}/{id}.tei.xml", name="resource-detail-tei",
     *          requirements={"volume" = "volume\-\d+", "id" = "(introduction|document|image|map)\-\d+"})
     * @Route("/resource/{volume}/{id}.pdf", name="resource-detail-pdf",
     *          requirements={"volume" = "volume\-\d+", "id" = "(introduction|document|image|map)\-\d+"})
     * @Route("/resource/{volume}/{id}", name="resource-detail",
     *          requirements={"volume" = "volume\-\d+", "id" = "(introduction|chapter|document|image|map)\-\d+"})
     */
    public function detailAction(Request $request, $volume, $id)
    {
        $textRazorApiKey = $this->getParameter('app.textrazor')['api_key'];
        $showAddEntities = !empty($textRazorApiKey) ? 1 : 0;

        $client = $this->getExistDbClient($this->subCollection);

        $xql = $this->renderView('Resource/detail-json.xql.twig', [
        ]);

        $query = $client->prepareQuery($xql);
        $query->setJSONReturnType();
        $query->bindVariable('collection', $client->getCollection());
        $query->bindVariable('id', 'ghdi:' . $id);
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

        $parts = $this->extractPartsFromHtml($html);
        if (1 === $showAddEntities && !empty($parts['entities'])) {
            // currently avoid multiple calls to add linked entities
            $showAddEntities = 0;
        }

        $entityLookup = $this->buildEntityLookup($parts['entities']);

        return $this->render('Resource/detail.html.twig', [
            'id' => $id,
            'volume' => $this->fetchVolume($client, $volume, $lang),
            'resource' => $resource,
            'webdav_base' => $this->buildWebDavBaseUrl($client),
            'html' => $html,
            'entity_lookup' => $entityLookup,
            'showAddEntities' => $showAddEntities,
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
