<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 *
 */
class OrganizationController
extends BaseController
{
    protected $subCollection = '/data/authority/organizations';

    /**
     * @Route("/organization", name="organization-list")
     */
    public function listAction(Request $request)
    {
        $client = $this->getExistDbClient($this->subCollection);

        $q = trim($request->request->get('q'));
        $xql = $this->renderView('Organization/list-json.xql.twig', [
            'q' => $q,
        ]);

        $query = $client->prepareQuery($xql);
        $query->setJSONReturnType();
        $query->bindVariable('collection', $client->getCollection());
        $query->bindVariable('locale', $request->getLocale());
        $query->bindVariable('q', $q);
        $res = $query->execute();
        $organizations = $res->getNextResult();
        $res->release();

        return $this->render('Organization/list.html.twig', [
            'q' => $q,
            'organizations' => $organizations,
        ]);
    }

    /**
     * @Route("/organization/{id}", name="organization-detail", requirements={"id" = "organization\-\d+"})
     */
    public function detailAction(Request $request, $id)
    {
        $client = $this->getExistDbClient($this->subCollection);

        $entity = $this->fetchEntity($client, $id, \App\Entity\Organization::class);
        if (is_null($entity)) {
            $request->getSession()
                    ->getFlashBag()
                    ->add('warning', 'No entry found for id: ' . $id)
                ;

            return $this->redirect($this->generateUrl('organization-list'));
        }

        return $this->render('Organization/detail.html.twig', [
            'entity' => $entity,
        ]);
    }

    protected function nextInSequence(\ExistDbRpc\Client $client, $collection)
    {
        // see https://stackoverflow.com/a/48901690
        $xql = <<<EOXQL
    declare variable \$collection external;
    let \$organizations := collection(\$collection)/Organization
    return (for \$key in (1 to 9999)!format-number(., '0')
        where empty(\$organizations[@id='organization-'||\$key])
        return 'organization-' || \$key)[1]
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
        $xql = $this->renderView('Organization/lookup-by-identifier-json.xql.twig', [
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
     * @Route("/organization/add-from-identifier", name="organization-add-from-identifier")
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
                        ->set('return-after-save', 'organization-import-missing');
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

                return $this->redirect($this->generateUrl('organization-detail', [
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

                    if (!is_null($entity) && $entity instanceof \App\Entity\Organization) {
                        // display for review
                        $request->getSession()
                                ->getFlashBag()
                                ->add('info', 'Please review and enhance before pressing [Save]')
                            ;

                        $form = $this->createForm(\App\Form\Type\OrganizationType::class, $entity, [
                            'action' => $this->generateUrl('organization-add'),
                        ]);

                        return $this->render('Organization/edit.html.twig', [
                            'form' => $form->createView(),
                            'entity' => $entity,
                        ]);
                    }
                    else {
                        $request->getSession()
                                ->getFlashBag()
                                ->add('warning', 'No organization found for: ' . $data['identifier'])
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

        return $this->render('Organization/add-from-identifier.html.twig', [
            'form' => $form->createView(),
            'data' => $data,
        ]);
    }

    /**
     * @Route("/organization/{id}/edit", name="organization-edit", requirements={"id" = "organization\-\d+"})
     * @Route("/organization/add", name="organization-add")
     */
    public function editAction(Request $request, $id = null)
    {
        $update = 'organization-edit' == $request->get('_route');

        $client = $this->getExistDbClient($this->subCollection);

        $entity = $this->fetchEntity($client, $id, \App\Entity\Organization::class);

        if (is_null($entity)) {
            if (is_null($id)) {
                // add new
                $entity = new \App\Entity\Organization();
            }
            else {
                $request->getSession()
                        ->getFlashBag()
                        ->add('warning', 'No entry found for id: ' . $id)
                    ;

                return $this->redirect($this->generateUrl('organization-list'));
            }
        }

        $form = $this->createForm(\App\Form\Type\OrganizationType::class, $entity);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if (!$update) {
                $id = $this->nextInSequence($client, $client->getCollection());
                $entity->setId($id);
            }

            $redirectUrl = $this->generateUrl('organization-detail', [ 'id' => $id ]);

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

        return $this->render('Organization/edit.html.twig', [
            'form' => $form->createView(),
            'entity' => $entity,
        ]);
    }

    /**
     * @Route("/organization/{id}/lookup-identifier", name="organization-lookup-identifier", requirements={"id" = "organization\-\d+"})
     */
    public function enhanceAction(Request $request, $id)
    {
        $client = $this->getExistDbClient($this->subCollection);

        $entity = $this->fetchEntity($client, $id, \App\Entity\Organization::class);

        if (is_null($entity)) {
            $request->getSession()
                    ->getFlashBag()
                    ->add('warning', 'No entry found for id: ' . $id)
                ;

            return $this->redirect($this->generateUrl('organization-list'));
        }

        if (!$entity->hasIdentifiers()) {
            $request->getSession()
                    ->getFlashBag()
                    ->add('warning', 'Entry has no identifier')
                ;

            return $this->redirect($this->generateUrl('organization-detail', [ 'id' => $id ]));
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

        return $this->redirect($this->generateUrl('organization-detail', [ 'id' => $id ]));
    }

    /**
     * @Route("/organization/import-missing", name="organization-import-missing")
     */
    public function importMissing(Request $request)
    {
        $xql = $this->renderView('Organization/lookup-missing-json.xql.twig', [
        ]);
        $client = $this->getExistDbClient();

        $query = $client->prepareQuery($xql);

        $baseCollection = $client->getCollection();

        $query->setJSONReturnType();
        $query->bindVariable('organizationsCollection', $baseCollection . $this->subCollection);
        $query->bindVariable('volumesCollection',  $baseCollection . '/data/volumes');
        $res = $query->execute();
        $info = $res->getNextResult();
        $res->release();

        return $this->render('Organization/missing.html.twig', [
            'info' => $info,
        ]);
    }

    /**
     * @Route("/organization/test", name="organization-test")
     */
    public function testAction(Request $request)
    {
        $organization = new \App\Entity\Organization();

        $organization->setId('organization-1');
        $organization->setName('Nationalsozialistische Arbeiterpartei Deutschlands');
        $organization->setLocalizedName('en', 'Nazi Party');
        $organization->setIdentifier('gnd', '136080804');
        $organization->setDisambiguatingDescription('de', 'Historiker und Mathematiker');
        $organization->setDisambiguatingDescription('en', 'Mathematician and Digital Humanist');
        $organization->setFoundingDate('1971-09-28');

        $serializer = $this->getSerializer();

        $content = $serializer->serialize($organization, 'json');
        var_dump($content);
        $test = $serializer->deserialize($content, 'App\Entity\Organization', 'json');
        var_dump($test);

        $content = $serializer->serialize($organization, 'xml');

        var_dump($content);

        $organization = $serializer->deserialize($content, 'App\Entity\Organization', 'xml');
        var_dump($organization);

        exit;
    }
}
