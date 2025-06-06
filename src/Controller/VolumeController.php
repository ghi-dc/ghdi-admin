<?php

// src/Controller/VolumeController.php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use OpenSpout\Common\Entity\Style\Style;

use function Symfony\Component\String\u;

/**
 * List and display Volume.
 */
class VolumeController extends ResourceController
{
    protected $subCollection = '/data/volumes';

    protected function listMatchingResources($q, $locale, $volumeId = null)
    {
        $client = $this->getExistDbClient($this->subCollection);

        $xql = $this->renderView('Volume/list-json.xql.twig', [
            'q' => $q,
            'prefix' => $this->siteKey,
        ]);

        $collection = $client->getCollection();
        if (!empty($volumeId)) {
            $collection .= '/' . $volumeId;
        }

        $query = $client->prepareQuery($xql);
        $query->setJSONReturnType();
        $query->bindVariable('collection', $collection);
        $query->bindVariable('lang', \App\Utils\Iso639::code1To3($locale));
        $query->bindVariable('q', $q);
        $res = $query->execute();
        $result = $res->getNextResult();
        $res->release();

        return $result;
    }

    #[Route(path: '/volume', name: 'volume-list')]
    public function listAction(Request $request)
    {
        $q = trim($request->request->get('q'));

        return $this->render('Volume/list.html.twig', [
            'q' => $q,
            'result' => $this->listMatchingResources($q, $request->getLocale()),
        ]);
    }

    protected function buildResources(\ExistDbRpc\Client $client, $id, $lang, $getTerms = false)
    {
        $xql = $this->renderView('Volume/list-resources-json.xql.twig', [
            'prefix' => $this->siteKey,
        ]);

        $query = $client->prepareQuery($xql);
        $query->setJSONReturnType();
        $query->bindVariable('collection', $client->getCollection() . '/' . $id);
        $query->bindVariable('lang', $lang);
        $query->bindVariable('getTerms', $getTerms);
        $res = $query->execute();
        $resources = $res->getNextResult();
        $res->release();

        return $resources;
    }

    protected function buildResourcesGrouped(\ExistDbRpc\Client $client, $id, $lang, $getTerms = false)
    {
        $resources = $this->buildResources($client, $id, $lang, $getTerms);

        // get outline from site settings
        $ret = array_map(
            function ($section) {
                if (is_array($section) && !array_key_exists('resources', $section)) {
                    $section['resources'] = [];
                }

                return $section;
            },
            $this->getParameter('app.site.structure')
        );

        if (is_null($resources)) {
            return $ret;
        }

        foreach ($resources['data'] as $info) {
            switch ($info['genre']) {
                case 'introduction':
                    if (!array_key_exists('introduction', $ret)) {
                        $ret['introduction'] = [
                            'name' => 'Introduction',
                            'resources' => [],
                        ];
                    }
                    $ret['introduction']['resources'][] = $info;
                    break;

                case 'document-collection':
                case 'image-collection':
                    $key = str_replace('-collection', 's', $info['genre']);

                    if (!array_key_exists($info['id'], $ret[$key]['resources'])) {
                        $info['resources'] = [];
                        $ret[$key]['resources'][$info['id']] = $info;
                    }
                    break;

                case 'document':
                case 'image':
                case 'audio':
                case 'video':
                    $key = $info['genre'] . 's';
                    if (in_array($key, ['images', 'audios', 'videos'])  && !array_key_exists($key, $ret)) {
                        // GHIS doesn't separate between documents and images
                        $key = 'documents';
                    }

                    $parts = explode('/', $info['shelfmark']);
                    if (preg_match('/(chapter\-\d+)/', $parts[2], $matches)) {
                        $chapter = $matches[1];
                        if (array_key_exists($chapter, $ret[$key]['resources'])) {
                            $ret[$key]['resources'][$chapter]['resources'][] = $info;
                            break;
                        }
                    }

                    $ret[$key]['resources']['resources'][] = $info;
                    break;

                case 'map':
                    if (!array_key_exists('maps', $ret)) {
                        $ret['maps'] = [
                            'name' => 'Maps',
                            'resources' => [],
                        ];
                    }
                    $ret['maps']['resources'][] = $info;
                    break;
            }
        }

        return $ret;
    }

    /**
     * Write a single resource into an Excel-row.
     */
    private function exportResource($writer, &$terms, $resource, $style = null)
    {
        $row = [
            array_key_exists('genre', $resource) ? $resource['genre'] : '',
            $resource['name'],
            join('; ', array_map(
                function ($uri) use ($terms) {
                    if (array_key_exists($uri, $terms)) {
                        return $terms[$uri];
                    }

                    return $uri;
                },
                array_key_exists('terms', $resource) ? $resource['terms'] : []
            )),
        ];

        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($row, $style));
    }

    #[Route(path: '/volume/{id}.dc.xml', name: 'volume-detail-dc', requirements: ['id' => 'volume\-\d+'])]
    #[Route(path: '/volume/{id}.scalar.json', name: 'volume-detail-scalar', requirements: ['id' => 'volume\-\d+'])]
    #[Route(path: '/volume/{id}.tei.xml', name: 'volume-detail-tei', requirements: ['id' => 'volume\-\d+'])]
    #[Route(path: '/volume/{id}', name: 'volume-detail', requirements: ['id' => 'volume\-\d+'])]
    #[Route(path: '/volume/{id}/create', name: 'volume-create', requirements: ['id' => 'volume\-\d+'])]
    #[Route(path: '/volume/{id}/export', name: 'volume-export', requirements: ['id' => 'volume\-\d+'])]
    public function volumeDetailAction(
        Request $request,
        TranslatorInterface $translator,
        $id
    ) {
        $client = $this->getExistDbClient($this->subCollection);

        $volume = $this->fetchVolume($client, $id, $lang = \App\Utils\Iso639::code1To3($request->getLocale()));

        if (is_null($volume)) {
            // check if we have one in another locale
            $createFrom = [];

            foreach ($this->getParameter('locales') as $alternate) {
                if ($alternate == $request->getLocale()) {
                    continue;
                }

                $volumeAlternate = $this->fetchVolume($client, $id, $alternateCode3 = \App\Utils\Iso639::code1To3($alternate));
                if (!is_null($volumeAlternate)) {
                    if (!empty($_POST['from-locale']) && $_POST['from-locale'] == $alternate) {
                        $from = $client->getCollection() . '/' . $id . '/' . $id . '.' . $alternateCode3 . '.xml';

                        $content = $client->getDocument($from, ['omit-xml-declaration' => 'no']);

                        if (false !== $content) {
                            $to = $client->getCollection() . '/' . $id . '/' . $id . '.' . $lang . '.xml';

                            // set new language
                            // TODO: adjust translated-from if needed
                            $data = [
                                'language' => $lang,
                            ];

                            $res = $this->updateTeiHeaderContent($client, $to, $content, $data, false);

                            if ($res) {
                                $request->getSession()
                                        ->getFlashBag()
                                        ->add('info', $translator->trans('The resource has been copied'))
                                ;

                                return $this->redirect($this->generateUrl('volume-edit', [
                                    'id' => $id,
                                ]));
                            }
                        }
                    }

                    $createFrom[$alternate] = \App\Utils\Iso639::nameByCode3($alternateCode3);
                }
            }

            if (!empty($createFrom)) {
                return $this->render('Volume/import.html.twig', [
                    'id' => $id,
                    'createFrom' => $createFrom,
                ]);
            }

            $request->getSession()
                    ->getFlashBag()
                    ->add('warning', sprintf(
                        $translator->trans('No resource found for id: %s'),
                        $id
                    ))
            ;

            return $this->redirect($this->generateUrl('volume-list'));
        }

        $resourcePath = $client->getCollection() . '/' . $id . '/' . $id . '.' . $lang . '.xml';

        $q = trim($request->request->get('q'));

        if ($request->isMethod('post')) {
            // check for query
            if (!empty($q)) {
                $result = $this->listMatchingResources($q, $request->getLocale(), $id);
                if (!empty($result['data'])) {
                    return $this->render('Volume/detail.html.twig', [
                        'id' => $id,
                        'volume' => $volume,
                        'webdav_base' => $this->buildWebDavBaseUrl($client),
                        'titleHtml' => $this->teiToHtml($client, $resourcePath, $lang, '//tei:titleStmt/tei:title', true),
                        'result' => $result,
                        'q' => $q,
                    ]);
                }

                $request->getSession()
                        ->getFlashBag()
                        ->add('info', $translator->trans('No matching resources found'))
                ;
            }

            // check for updated order
            $postData = $request->request->get('order');
            if (!empty($postData)) {
                $order = json_decode($postData, true);
                if (false !== $order) {
                    $resourcesGrouped = $this->buildResourcesGrouped($client, $id, $lang);
                    if (array_key_exists($request->get('resource_group'), $resourcesGrouped)) {
                        $hasPart = $resourcesGrouped[$request->get('resource_group')]['resources'];
                    }

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
                            [$order, $ignore] = explode(':', end($parts), 2);
                            if ($order != $newOrder) {
                                $newOrderAndId = sprintf(
                                    '%03d:%s',
                                    $newOrder[$childId],
                                    $childId
                                );
                                $parts[count($parts) - 1] = $newOrderAndId;
                                $newShelfmark = implode('/', $parts);

                                if ($child['shelfmark'] != $newShelfmark) {
                                    $this->updateDocumentShelfmark(
                                        $client,
                                        $id,
                                        $childId,
                                        $lang,
                                        $newShelfmark,
                                        $child['shelfmark']
                                    );

                                    $updated = true;

                                    if (array_key_exists('resources', $child)) {
                                        // adjust shelfmark of inherited
                                        foreach ($child['resources'] as $grandChild) {
                                            if (!u($grandChild['shelfmark'])->startsWith($newShelfmark)) {
                                                // replace everything until $childId with $newShelfmark
                                                $subShelfmark = preg_replace(
                                                    '/^(.*?)' . preg_quote($childId, '/') . '/',
                                                    $newShelfmark,
                                                    $grandChild['shelfmark']
                                                );
                                                if ($subShelfmark != $grandChild['shelfmark']) {
                                                    $this->updateDocumentShelfmark(
                                                        $client,
                                                        $id,
                                                        $grandChild['id'],
                                                        $lang,
                                                        $subShelfmark,
                                                        $grandChild['shelfmark']
                                                    );

                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }

                    if ($updated) {
                        $this->addFlash('info', $translator->trans('The order has been updated'));
                    }
                }
            }
        }

        $volumepath = $client->getCollection() . '/' . $id . '/' . $volume['data']['fname'];
        if ('volume-detail-dc' == $request->get('_route')) {
            return $this->teiToDublinCore($translator, $client, $volumepath);
        }

        if ('volume-detail-scalar' == $request->get('_route')) {
            return $this->teiToScalar(
                $client,
                $volumepath,
                \App\Utils\Iso639::code1To3($request->getLocale()),
                $this->buildResourcesGrouped($client, $id, $lang)
            );
        }

        if ('volume-detail-tei' == $request->get('_route')) {
            $tei = $client->getDocument($volumepath, ['omit-xml-declaration' => 'no']);

            $response = new Response($tei);
            $response->headers->set('Content-Type', 'xml');

            return $response;
        }

        if ('volume-export' == $request->get('_route')) {
            $resourcesGrouped = $this->buildResourcesGrouped($client, $id, $lang, true);
            $terms = $this->buildTermChoices($request->getLocale());

            $fileName = sprintf(
                '%s-%s.xlsx',
                $volume['data']['id'],
                $lang
            );

            // Create styles
            $titleStyle = (new Style())
                       ->setFontBold()
                       ->setFontSize(20)
                       // ->setShouldWrapText()
            ;

            $sectionStyle = (new Style())
                       ->setFontBold()
                       ->setFontSize(18)
                       // ->setShouldWrapText()
            ;

            $chapterStyle = (new Style())
                       ->setFontBold()
                       // ->setShouldWrapText()
            ;

            $writer = new \OpenSpout\Writer\XLSX\Writer();
            $writer->openToBrowser($fileName);

            // Create a row with cells and apply the style to all cells
            $row = \OpenSpout\Common\Entity\Row::fromValues([$volume['data']['name']], $titleStyle);
            $writer->addRow($row);

            foreach ($resourcesGrouped as $key => $section) {
                $this->exportResource($writer, $terms, [
                    'name'  => /* @Ignore */$translator->trans($section['name'], [], 'additional'),
                ], $sectionStyle);

                foreach ($section['resources'] as $chapterKey => $chapter) {
                    $hasResources = array_key_exists('resources', $chapter);

                    $this->exportResource($writer, $terms, $chapter, $hasResources ? $chapterStyle : null);

                    if (!$hasResources) {
                        // empty row
                        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues([]));

                        continue;
                    }

                    foreach ($chapter['resources'] as $resource) {
                        $this->exportResource($writer, $terms, $resource);
                    }

                    // empty row
                    $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues([]));
                }
            }

            $writer->close();

            exit;
        }

        return $this->render('Volume/detail.html.twig', [
            'id' => $id,
            'volume' => $volume,
            'webdav_base' => $this->buildWebDavBaseUrl($client),
            'titleHtml' => $this->teiToHtml($client, $resourcePath, $lang, '//tei:titleStmt/tei:title', true),
            'resources_grouped' => $this->buildResourcesGrouped($client, $id, $lang),
            'q' => $q,
        ]);
    }

    #[Route(path: '/volume/{id}/edit', name: 'volume-edit', requirements: ['id' => 'volume\-\d+'])]
    public function volumeEditAction(
        Request $request,
        TranslatorInterface $translator,
        $id = null
    ) {
        $update = 'volume-edit' == $request->get('_route');

        $client = $this->getExistDbClient($this->subCollection);

        $lang = \App\Utils\Iso639::code1To3($request->getLocale());

        if (is_null($id)) {
            $entity = null;
        }
        else {
            $resourcePath = $client->getCollection() . '/' . $id . '/' . $id . '.' . $lang . '.xml';
            $entity = $this->fetchTeiHeader($client, $resourcePath);
        }

        $titleHtml = null;
        if (is_null($entity)) {
            if (is_null($id)) {
                // add new not implemented yet
                $request->getSession()
                        ->getFlashBag()
                        ->add('warning', $translator->trans('Creating new volumes is not implemented yet'))
                ;

                return $this->redirect($this->generateUrl('volume-list'));
            }

            $request->getSession()
                    ->getFlashBag()
                    ->add('warning', sprintf(
                        $translator->trans('No resource found for id: %s'),
                        $id
                    ))
            ;

            return $this->redirect($this->generateUrl('volume-list'));
        }
        else {
            $titleHtml = $this->teiToHtml($client, $resourcePath, $lang, '//tei:titleStmt/tei:title', true);
        }

        $form = $this->createForm(\App\Form\Type\TeiHeaderType::class, $entity);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if (!$update) {
                exit('TODO: handle');
                $id = $this->nextInSequence($client, $client->getCollection(), 'volume-');
                $entity->setId($id);
                // TODO: createTeiHeader
            }
            else {
                $res = $this->updateTeiHeader($client, $resourcePath, $entity);
            }

            if (!$res) {
                $request->getSession()
                        ->getFlashBag()
                        ->add('warning', 'An issue occured while storing id: ' . $id)
                ;
            }
            else {
                $request->getSession()
                        ->getFlashBag()
                                ->add(
                                    'info',
                                    $update
                                      ? $translator->trans('The volume has been updated')
                                      : $translator->trans('The volume has been created')
                                )
                ;
            }

            return $this->redirect($this->generateUrl('volume-detail', ['id' => $id]));
        }

        return $this->render('Volume/edit.html.twig', [
            'form' => $form->createView(),
            'entity' => $entity,
            'id' => $id,
            'titleHtml' => $titleHtml,
        ]);
    }
}
