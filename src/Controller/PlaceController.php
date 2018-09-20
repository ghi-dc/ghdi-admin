<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 *
 */
class PlaceController
extends BaseController
{
    protected $subCollection = '/data/authority/places';

    /**
     * @Route("/place", name="place-list")
     */
    public function listAction(Request $request)
    {
        $client = $this->getExistDbClient($this->subCollection);

        $q = trim($request->request->get('q'));
        $xql = $this->renderView('Place/list-json.xql.twig', [
            'q' => $q,
        ]);

        $query = $client->prepareQuery($xql);
        $query->setJSONReturnType();
        $query->bindVariable('collection', $client->getCollection());
        $query->bindVariable('locale', $request->getLocale());
        $query->bindVariable('q', $q);
        $res = $query->execute();
        $places = $res->getNextResult();
        $res->release();

        return $this->render('Place/list.html.twig', [
            'q' => $q,
            'places' => $places,
        ]);
    }

    /**
     * @Route("/place/{id}", name="place-detail", requirements={"id" = "place\-\d+"})
     */
    public function detailAction(Request $request, $id)
    {
        $client = $this->getExistDbClient($this->subCollection);

        $place = $this->fetchById($client, $id);

        if (is_null($place)) {
            $request->getSession()
                    ->getFlashBag()
                    ->add('warning', 'No item found for id: ' . $id)
                ;

            return $this->redirect($this->generateUrl('place-list'));
        }

        $containedInPlace = null;

        $containedIn = $place->getContainedInPlace();
        if (!is_null($containedIn)) {
            $containedInPlace = $this->findByIdentifier($containedIn->getTgn(), 'tgn', true);
        }

        return $this->render('Place/detail.html.twig', [
            'entity' => $place,
            'containedInPlace' => $containedInPlace,
        ]);
    }

    protected function nextInSequence($client, $collection)
    {
        // see https://stackoverflow.com/a/48901690
        $xql = <<<EOXQL
    declare variable \$collection external;
    let \$places := collection(\$collection)/Place
    return (for \$key in (1 to 9999)!format-number(., '0')
        where empty(\$places[@id='place-'||\$key])
        return 'place-' || \$key)[1]
EOXQL;

        $query = $client->prepareQuery($xql);
        $query->bindVariable('collection', $collection);
        $res = $query->execute();
        $nextId = $res->getNextResult();
        $res->release();

        if (empty($nextId)) {
            throw new \Exception('Could not generated next id in sequence');
        }

        return $nextId;
    }

    protected function persist($client, $place, $update = false)
    {
        if (!$update) {
            $id = $this->nextInSequence($client, $client->getCollection());
            $place->setId($id);
        }

        $serializer = $this->getSerializer();

        $content = $serializer->serialize($place, 'xml');

        $id = $place->getId();
        $name = $id . '.xml';

        $res = $client->parse($content, $name, $update);

        return $place;
    }

    function fetchById($client, $id)
    {
        if ($client->hasDocument($name = $id . '.xml')) {
            $content = $client->getDocument($name);

            $serializer = $this->getSerializer();

            return $serializer->deserialize($content, 'App\Entity\Place', 'xml');
        }

        return null;
    }

    protected function findByIdentifier($value, $type = 'tgn', $fetchEntity = false)
    {
        $xql = $this->renderView('Place/lookup-by-identifier-json.xql.twig', [
        ]);
        $client = $this->getExistDbClient($this->subCollection);

        $query = $client->prepareQuery($xql);

        $query->setJSONReturnType();
        $query->bindVariable('collection', $client->getCollection());
        $query->bindVariable('type', $type);
        $query->bindVariable('value', $value);
        $res = $query->execute();
        $info = $res->getNextResult();
        $res->release();

        if ($fetchEntity) {
            if (empty($info['data'])) {
                return null;
            }

            $id = array_key_exists('id', $info['data'])
                ? $info['data']['id'] : $info['data'][0]['id'];

            return $this->fetchById($client, $id);
        }

        return $info;
    }

    protected function lookupContainedInPlace ($client, $place)
    {
        $containedInPlace = $place->getContainedInPlace();

        if (!is_null($containedInPlace)) {
            $tgn = $containedInPlace->getTgn();
            $containedInPlace = null;
            $info = $this->findByIdentifier($tgn, 'tgn');
            if (is_null($info)) {
                $containedInPlace = \App\Utils\GeographicalDataTgn::lookupPlaceByTgn($tgn);
                if (!is_null($containedInPlace)) {
                    $this->lookupContainedInPlace($client, $containedInPlace);
                    $this->persist($client, $containedInPlace);
                }
            }
        }
    }

    /**
     * @Route("/place/add-from-identifier", name="place-add-from-identifier")
     */
    public function addFromIdentifierAction(Request $request)
    {
        $types = [
            'tgn' => 'Getty TGN',
        ];
        $data = [];

        $form = $this->get('form.factory')
                ->create(\App\Form\Type\EntityIdentifierType::class, $data, [
                    'types' => $types,
                ])
                ;

        if ($request->getMethod() == 'POST') {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $data = $form->getData();

                $info = $this->findByIdentifier(trim($data['identifier']), $data['type']);
                if (!empty($info['data'])) {
                    $id = array_key_exists('id', $info['data'])
                        ? $info['data']['id'] : $info['data'][0]['id'];

                    $request->getSession()
                            ->getFlashBag()
                            ->add('info', 'There is already an entry for this identifier')
                        ;

                    return $this->redirect($this->generateUrl('place-detail', [
                        'id' => $id,
                    ]));
                }


                switch ($data['type']) {
                    case 'tgn':
                        $client = $this->getExistDbClient($this->subCollection);
                        $place = \App\Utils\GeographicalDataTgn::lookupPlaceByTgn($data['identifier']);

                        if (!is_null($place)) {
                            // fetch / store all the parents
                            $this->lookupContainedInPlace($client, $place);

                            /* currently no review since we can't handle containedInPlace in Form
                            // display for review
                            $request->getSession()
                                    ->getFlashBag()
                                    ->add('info', 'Please review and enhance before pressing [Save]')
                                ;

                            $form = $this->get('form.factory')
                                    ->create(\App\Form\Type\PlaceType::class, $place, [
                                        'action' => $this->generateUrl('place-add'),
                                    ])
                                    ;


                            return $this->render('Place/edit.html.twig', [
                                'form' => $form->createView(),
                                'entity' => $place,
                            ]);
                            */
                            $place = $this->persist($client, $place);
                            return $this->redirect($this->generateUrl('place-detail', [
                                'id' => $place->getId(),
                            ]));
                        }
                        else {
                            $request->getSession()
                                    ->getFlashBag()
                                    ->add('warning', 'No info found for TGN: ' . $data['identifier'])
                                ;
                        }
                        break;

                    default:
                        $request->getSession()
                                ->getFlashBag()
                                ->add('warning', 'Not handling type: ' . $data['type'])
                            ;

                }
            }
        }

        return $this->render('Place/add-from-identifier.html.twig', [
            'form' => $form->createView(),
            'data' => $data,
        ]);
    }

    /**
     * @Route("/place/{id}/edit", name="place-edit", requirements={"id" = "place\-\d+"})
     * @Route("/place/add", name="place-add")
     */
    public function editAction(Request $request, $id = null)
    {
        $update = 'place-edit' == $request->get('_route');

        $client = $this->getExistDbClient($this->subCollection);

        $place = null;

        if (!is_null($id)) {
            $place = $this->fetchById($client, $id);
        }

        if (is_null($place)) {
            if (is_null($id)) {
                // add new
                $place = new \App\Entity\Place();
            }
            else {
                $request->getSession()
                        ->getFlashBag()
                        ->add('warning', 'No item found for id: ' . $id)
                    ;

                return $this->redirect($this->generateUrl('place-list'));
            }
        }

        $form = $this->get('form.factory')->create(\App\Form\Type\PlaceType::class,
                                                   $place);
        if ($request->getMethod() == 'POST') {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $res = $this->persist($client, $place, $update);
                if (!$res) {
                    $request->getSession()
                            ->getFlashBag()
                            ->add('warning', 'An issue occured while storing id: ' . $id)
                        ;
                }
                else {
                    $request->getSession()
                            ->getFlashBag()
                            ->add('in', 'Entry ' . ($update ? ' updated' : ' created'));
                        ;
                }

                return $this->redirect($this->generateUrl('place-detail', [ 'id' => $id ]));
            }
        }

        return $this->render('Place/edit.html.twig', [
            'form' => $form->createView(),
            'entity' => $place,
        ]);
    }

    /**
     * @Route("/place/test", name="place-test")
     */
    public function testAction(Request $request)
    {
        $province = new \App\Entity\Place();
        $province->setId('place-2');
        $province->setName('Roma');
        $province->setIdentifier('tgn', '7000874');

        $place = new \App\Entity\Place();

        $place->setId('place-1');
        $place->setName('Roma');
        $place->setIdentifier('tgn', '7003138');
        $place->setContainedInPlace($province);

        $geoCoordinates = new \App\Entity\GeoCoordinates();
        $geoCoordinates
            ->setLatitude('41.9')
            ->setLongitude('12.483333')
            ->setAddressCountry('IT');

        $place->setGeo($geoCoordinates);

        $serializer = $this->getSerializer();

        $content = $serializer->serialize($place, 'json');
        var_dump($content);

        $content = $serializer->serialize($place, 'xml');
        var_dump($content);

        $place = $serializer->deserialize($content, 'App\Entity\Place', 'xml');
        var_dump($place);

        exit;
    }
}
