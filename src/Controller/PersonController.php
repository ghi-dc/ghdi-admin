<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 *
 */
class PersonController
extends BaseController
{
    protected $subCollection = '/data/authority/persons';

    /**
     * @Route("/person", name="person-list")
     */
    public function listAction(Request $request)
    {
        $client = $this->getExistDbClient($this->subCollection);

        $q = trim($request->request->get('q'));
        $xql = $this->renderView('Person/list-json.xql.twig', [
            'q' => $q,
        ]);

        $query = $client->prepareQuery($xql);
        $query->setJSONReturnType();
        $query->bindVariable('collection', $client->getCollection());
        $query->bindVariable('locale', $request->getLocale());
        $query->bindVariable('q', $q);
        $res = $query->execute();
        $persons = $res->getNextResult();
        $res->release();

        return $this->render('Person/list.html.twig', [
            'q' => $q,
            'persons' => $persons,
        ]);
    }

    /**
     * @Route("/person/{id}", name="person-detail", requirements={"id" = "person\-\d+"})
     */
    public function detailAction(Request $request, $id)
    {
        $client = $this->getExistDbClient($this->subCollection);

        $entity = $this->fetchEntity($client, $id, \App\Entity\Person::class);
        if (is_null($entity)) {
            $request->getSession()
                    ->getFlashBag()
                    ->add('warning', 'No entry found for id: ' . $id)
                ;

            return $this->redirect($this->generateUrl('person-list'));
        }

        return $this->render('Person/detail.html.twig', [
            'entity' => $entity,
        ]);
    }

    protected function nextInSequence($client, $collection)
    {
        // see https://stackoverflow.com/a/48901690
        $xql = <<<EOXQL
    declare variable \$collection external;
    let \$persons := collection(\$collection)/Person
    return (for \$key in (1 to 9999)!format-number(., '0')
        where empty(\$persons[@id='person-'||\$key])
        return 'person-' || \$key)[1]
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

    protected function findByIdentifier($value, $type = 'gnd')
    {
        $xql = $this->renderView('Person/lookup-by-identifier-json.xql.twig', [
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

        return $info;
    }

    /**
     * @Route("/person/add-from-identifier", name="person-add-from-identifier")
     */
    public function addFromIdentifierAction(Request $request)
    {
        $types = [
            'gnd' => 'GND',
            'lcauth' => 'LoC authority ID',
            'viaf' => 'VIAF',
            'wikidata' => 'Wikidata QID',
        ];

        $data = [];
        $form = $this->createForm(\App\Form\Type\EntityIdentifierType::class, $data, [
            'types' => $types,
        ]);

        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                $data = $form->getData();
            }
        }
        else {
            $type = $request->get('type');
            if (!empty($type) && array_key_exists($type, $types)) {
                $identifier = $request->get('identifier');
                if (!empty($identifier)) {
                    $data = [
                        'type' => $type,
                        'identifier' => $identifier,
                    ];

                    // TODO: make this conditional
                    $request->getSession()
                        ->set('return-after-save', 'person-import-missing');
                }
            }
        }

        if (!empty($data)) {
            $info = $this->findByIdentifier(trim($data['identifier']), $data['type']);
            if (!empty($info['data'])) {
                $id = array_key_exists('id', $info['data'])
                    ? $info['data']['id'] : $info['data'][0]['id'];

                $request->getSession()
                        ->getFlashBag()
                        ->add('info', 'There is already an entry for this identifier')
                    ;

                return $this->redirect($this->generateUrl('person-detail', [
                    'id' => $id,
                ]));
            }

            switch ($data['type']) {
                case 'wikidata':
                case 'viaf':
                case 'lcauth':
                    $found = false;
                    $lodService = new \App\Utils\Lod\LodService(new \App\Utils\Lod\Provider\WikidataProvider());
                    $identifier = \App\Utils\Lod\Identifier\Factory::byName('lcauth' == $data['type'] ? 'lcnaf' : $data['type']);
                    if (!is_null($identifier)) {
                        $identifier->setValue($data['identifier']);

                        $sameAs = $lodService->lookupSameAs($identifier);
                        if (!empty($sameAs)) {
                            foreach ($sameAs as $identifier) {
                                // hunt for a gnd
                                if ('gnd' == $identifier->getName()) {
                                    $data['type'] = 'gnd';
                                    $data['identifier'] = $identifier->getValue();
                                    $found = true;
                                    break;
                                }
                            }
                        }
                    }

                    if (!$found && $data['type'] != 'lcauth') {
                        $request->getSession()
                                ->getFlashBag()
                                ->add('warning', 'Could not find a corresponding GND')
                            ;

                        break;
                    }
                    // fallthrough

                case 'gnd':
                    if ('gnd' == $data['type']) {
                        $identifier = new \App\Utils\Lod\Identifier\GndIdentifier($data['identifier']);
                        $lodService = new \App\Utils\Lod\LodService(new \App\Utils\Lod\Provider\DnbProvider());
                    }
                    else {
                        $identifier = new \App\Utils\Lod\Identifier\LocLdsNamesIdentifier($data['identifier']);
                        $lodService = new \App\Utils\Lod\LodService(new \App\Utils\Lod\Provider\LocProvider());
                    }

                    $entity = $lodService->lookup($identifier);

                    if (!is_null($entity) && $entity instanceof \App\Entity\Person) {
                        // display for review
                        $request->getSession()
                                ->getFlashBag()
                                ->add('info', 'Please review and enhance before pressing [Save]')
                            ;

                        $form = $this->createForm(\App\Form\Type\PersonType::class, $entity, [
                            'action' => $this->generateUrl('person-add'),
                        ]);

                        return $this->render('Person/edit.html.twig', [
                            'form' => $form->createView(),
                            'entity' => $entity,
                        ]);
                    }
                    else {
                        $request->getSession()
                                ->getFlashBag()
                                ->add('warning', 'No person found for: ' . $data['identifier'])
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

        return $this->render('Person/add-from-identifier.html.twig', [
            'form' => $form->createView(),
            'data' => $data,
        ]);
    }

    /**
     * @Route("/person/{id}/edit", name="person-edit", requirements={"id" = "person\-\d+"})
     * @Route("/person/add", name="person-add")
     */
    public function editAction(Request $request, $id = null)
    {
        $update = 'person-edit' == $request->get('_route');

        $client = $this->getExistDbClient($this->subCollection);

        $entity = $this->fetchEntity($client, $id, \App\Entity\Person::class);

        if (is_null($entity)) {
            if (is_null($id)) {
                // add new
                $entity = new \App\Entity\Person();
            }
            else {
                $request->getSession()
                        ->getFlashBag()
                        ->add('warning', 'No entry found for id: ' . $id)
                    ;

                return $this->redirect($this->generateUrl('person-list'));
            }
        }

        $form = $this->createForm(\App\Form\Type\PersonType::class, $entity);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if (!$update) {
                $id = $this->nextInSequence($client, $client->getCollection());
                $entity->setId($id);
            }

            $redirectUrl = $this->generateUrl('person-detail', [ 'id' => $id ]);

            $serializer = $this->getSerializer();
            $content = $serializer->serialize($entity, 'xml');
            $name = $id . '.xml';
            $res = $client->parse($content, $name, $update);
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

        return $this->render('Person/edit.html.twig', [
            'form' => $form->createView(),
            'entity' => $entity,
        ]);
    }

    /**
     * @Route("/person/{id}/lookup-identifier", name="person-lookup-identifier", requirements={"id" = "person\-\d+"})
     */
    public function enhanceAction(Request $request, $id)
    {
        $client = $this->getExistDbClient($this->subCollection);

        $entity = $this->fetchEntity($client, $id, \App\Entity\Person::class);

        if (is_null($entity)) {
            $request->getSession()
                    ->getFlashBag()
                    ->add('warning', 'No entry found for id: ' . $id)
                ;

            return $this->redirect($this->generateUrl('person-list'));
        }

        if (!$entity->hasIdentifiers()) {
            $request->getSession()
                    ->getFlashBag()
                    ->add('warning', 'Entry has no identifier')
                ;

            return $this->redirect($this->generateUrl('person-detail', [ 'id' => $id ]));
        }

        $update = false;

        $lodService = new \App\Utils\Lod\LodService(new \App\Utils\Lod\Provider\WikidataProvider());
        foreach ($entity->getIdentifiers() as $name => $value) {
            $identifier = \App\Utils\Lod\Identifier\Factory::byName($name);
            if (!is_null($identifier) && !empty($value)) {
                $identifier->setValue($value);

                $sameAs = $lodService->lookupSameAs($identifier);
                if (!empty($sameAs)) {
                    foreach ($sameAs as $identifier) {
                        $name = $identifier->getName();
                        $current = $entity->getIdentifier($name);
                        if (empty($current)) {
                            $update = true;
                            $entity->setIdentifier($name, $identifier->getValue());
                        }
                    }

                    // one successful call gets all the others
                    break;
                }
            }
        }

        if ($update) {
            $serializer = $this->getSerializer();
            $content = $serializer->serialize($entity, 'xml');
            $name = $id . '.xml';
            $res = $client->parse($content, $name, $update);
            if (!$res) {
                $request->getSession()
                        ->getFlashBag()
                        ->add('warning', 'An issue occured while storing id: ' . $id)
                    ;
            }
            else {
                $request->getSession()
                        ->getFlashBag()
                        ->add('info', 'The entry has been updated.');
                    ;
            }
        }
        else {
            $request->getSession()
                    ->getFlashBag()
                    ->add('info', 'No additional information could be found.');
                ;
        }

        return $this->redirect($this->generateUrl('person-detail', [ 'id' => $id ]));
    }

    /**
     * @Route("/person/import-missing", name="person-import-missing")
     */
    public function importMissing(Request $request)
    {
        $xql = $this->renderView('Person/lookup-missing-json.xql.twig', [
        ]);
        $client = $this->getExistDbClient();

        $query = $client->prepareQuery($xql);

        $baseCollection = $client->getCollection();

        $query->setJSONReturnType();
        $query->bindVariable('personsCollection', $baseCollection . $this->subCollection);
        $query->bindVariable('volumesCollection',  $baseCollection . '/data/volumes');
        $res = $query->execute();
        $info = $res->getNextResult();
        $res->release();

        return $this->render('Person/missing.html.twig', [
            'info' => $info,
        ]);
    }

    /**
     * @Route("/person/test", name="person-test")
     */
    public function testAction(Request $request)
    {
        $person = new \App\Entity\Person();

        $birthPlace = new \App\Entity\Place();
        $birthPlace->setName('Meiringen');

        $person->setId('person-1');
        $person->setFamilyName('Burckhardt');
        $person->setGivenName('Daniel');
        $person->setIdentifier('gnd', '136080804');
        $person->setDisambiguatingDescription('de', 'Historiker und Mathematiker');
        $person->setDisambiguatingDescription('en', 'Mathematician and Digital Humanist');
        $person->setBirthDate('1971-09-28');
        $person->setBirthPlace($birthPlace);

        $serializer = $this->getSerializer();
        $content = $serializer->serialize($person, 'xml');

        var_dump($content);
        $person = $serializer->deserialize($content, 'App\Entity\Person', 'xml');
        var_dump($person);

        exit;
    }
}
