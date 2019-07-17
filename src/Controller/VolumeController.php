<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 *
 */
class VolumeController
extends ResourceController
{
    protected $subCollection = '/data/volumes';

    /**
     * @Route("/volume", name="volume-list")
     */
    public function listAction(Request $request)
    {
        $client = $this->getExistDbClient($this->subCollection);

        $q = trim($request->request->get('q'));
        $xql = $this->renderView('Volume/list-json.xql.twig', [
            'q' => $q,
            'prefix' => $this->siteKey,
        ]);

        $query = $client->prepareQuery($xql);
        $query->setJSONReturnType();
        $query->bindVariable('collection', $client->getCollection());
        $query->bindVariable('locale', $request->getLocale());
        $query->bindVariable('lang', \App\Utils\Iso639::code1To3($request->getLocale()));
        $query->bindVariable('q', $q);
        $res = $query->execute();
        $volumes = $res->getNextResult();
        $res->release();

        return $this->render('Volume/list.html.twig', [
            'q' => $q,
            'volumes' => $volumes,
        ]);
    }

    protected function buildResources($client, $id, $lang)
    {
        $xql = $this->renderView('Volume/list-resources-json.xql.twig', [
            'prefix' => $this->siteKey,
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

    protected function buildResourcesGrouped($client, $id, $lang)
    {
        $resources = $this->buildResources($client, $id, $lang);

        // get outline from site settings
        $ret = array_map(function ($section) {
                if (is_array($section) && !array_key_exists('resources', $section)) {
                    $section['resources'] = [];
                }

                return $section;
            },
            $this->getParameter('app.site.structure'));

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
                        $ret[$key]['resources'][$info['id']] = [
                            'id' => $info['id'],
                            'name' => $info['name'],
                            'resources' => [],
                        ];
                    }
                    break;

                case 'document':
                case 'image':
                    $key = $info['genre'] . 's';

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
     * @Route("/volume/{id}.dc.xml", name="volume-detail-dc", requirements={"id" = "volume\-\d+"})
     * @Route("/volume/{id}.scalar.json", name="volume-detail-scalar", requirements={"id" = "volume\-\d+"})
     * @Route("/volume/{id}", name="volume-detail", requirements={"id" = "volume\-\d+"})
     */
    public function volumeDetailAction(Request $request, $id)
    {
        $client = $this->getExistDbClient($this->subCollection);

        $volume = $this->fetchVolume($client, $id, $lang = \App\Utils\Iso639::code1To3($request->getLocale()));

        if (is_null($volume)) {
            $request->getSession()
                    ->getFlashBag()
                    ->add('warning', 'No item found for id: ' . $id)
                ;

            return $this->redirect($this->generateUrl('volume-list'));
        }

        if (!empty($request->get('order'))) {
            $request->getSession()
                    ->getFlashBag()
                    ->add('info', 'TODO: reorder ' . $request->get('order'))
                ;
        }

        if ('volume-detail-dc' == $request->get('_route')) {
            return $this->teiToDublinCore($client, $client->getCollection() . '/' . $id . '/' . $volume['data']['fname']);
        }

        if ('volume-detail-scalar' == $request->get('_route')) {
            return $this->teiToScalar($client, $client->getCollection() . '/' . $id . '/' . $volume['data']['fname'],
                                      \App\Utils\Iso639::code1To3($request->getLocale()),
                                      $this->buildResourcesGrouped($client, $id, $lang));
        }

        return $this->render('Volume/detail.html.twig', [
            'id' => $id,
            'volume' => $volume,
            'webdav_base' => $this->buildWebDavBaseUrl($client),
            'resources_grouped' => $this->buildResourcesGrouped($client, $id, $lang),
        ]);
    }

    /**
     * @Route("/volume/{id}/edit", name="volume-edit", requirements={"id" = "volume\-\d+"})
     */
    public function volumeEditAction(Request $request, $id = null)
    {
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

        if (is_null($entity)) {
            if (is_null($id)) {
                // add new not implemented yet
                $request->getSession()
                        ->getFlashBag()
                        ->add('warning', 'Creating new volumes is not implemented yet')
                    ;

                return $this->redirect($this->generateUrl('volume-list'));
            }

            $request->getSession()
                    ->getFlashBag()
                    ->add('warning', 'No item found for id: ' . $id)
                ;

            return $this->redirect($this->generateUrl('volume-list'));
        }

        $form = $this->get('form.factory')->create(\App\Form\Type\TeiHeaderType::class,
                                                   $entity);
        if ($request->getMethod() == 'POST') {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                if (!$update) {
                    die('TODO: handle');
                    $id = $this->nextInSequence($client, $client->getCollection());
                    $entity->setId($id);
                    // TODO: createTeiHeader
                }
                else {
                    $res = $this->updateTeiHeader($entity, $client, $resourcePath);
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
                            ->add('info', 'Volume ' . ($update ? ' updated' : ' created'));
                        ;
                }

                return $this->redirect($this->generateUrl('volume-detail', [ 'id' => $id ]));
            }
        }

        return $this->render('Volume/edit.html.twig', [
            'form' => $form->createView(),
            'entity' => $entity,
            'id' => $id,
        ]);
    }
}
