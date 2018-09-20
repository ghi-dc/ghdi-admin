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

        $xql = $this->renderView('Organization/detail-json.xql.twig', [
        ]);

        $query = $client->prepareQuery($xql);
        $query->setJSONReturnType();
        $query->bindVariable('collection', $client->getCollection());
        $query->bindVariable('id', $id);
        $res = $query->execute();
        $organization = $res->getNextResult();
        $res->release();
        if (is_null($organization)) {
            $request->getSession()
                    ->getFlashBag()
                    ->add('warning', 'No item found for id: ' . $id)
                ;

            return $this->redirect($this->generateUrl('organization-list'));
        }

        $serializer = $this->getSerializer();
        $entity = $serializer->deserialize(json_encode($organization['data'], true), 'App\Entity\Organization', 'json');

        return $this->render('Organization/detail.html.twig', [
            'organization' => $organization,
            'entity' => $entity,
        ]);
    }

    protected function nextInSequence($client, $collection)
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
            'wikidata' => 'Wikidata QID',
        ];

        $data = [];

        $form = $this->get('form.factory')
                ->create(\App\Form\Type\EntityIdentifierType::class, $data, [
                    'types' => $types,
                ])
                ;

        $form->handleRequest($request);
        $data = [];
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
                    $found = false;

                    $qid = $data['identifier'];

                    $corporateBody = new \App\Utils\CorporateBodyData();

                    try {
                        $gnds = $corporateBody->lookupGndByQid($data['identifier']);
                        if (1 == count($gnds)) {
                            $found = true;

                            $data['identifier'] = $gnds[0];
                            $data['type'] = 'gnd';
                        }
                    }
                    catch (\Exception $e) {
                        ; // ignore
                    }

                    if (!$found) {
                        $request->getSession()
                                ->getFlashBag()
                                ->add('warning', 'Could not find a corresponding GND')
                            ;

                        break;
                    }
                    // fallthrough

                case 'gnd':
                    // TODO: look if there is already an entry
                    $organization = \App\Utils\CorporateBodyData::lookupOrganizationByGnd($data['identifier']);

                    if (!is_null($organization)) {
                        // display for review
                        $request->getSession()
                                ->getFlashBag()
                                ->add('info', 'Please review and enhance before pressing [Save]')
                            ;

                        $form = $this->get('form.factory')
                                ->create(\App\Form\Type\OrganizationType::class, $organization, [
                                    'action' => $this->generateUrl('organization-add'),
                                ])
                                ;


                        return $this->render('Organization/edit.html.twig', [
                            'form' => $form->createView(),
                            'entity' => $organization,
                        ]);
                    }
                    else {
                        $request->getSession()
                                ->getFlashBag()
                                ->add('warning', 'No info found for GND: ' . $data['identifier'])
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

        $organization = null;
        $serializer = $this->getSerializer();
        if ($client->hasDocument($name = $id . '.xml')) {
            $content = $client->getDocument($name);
            $organization = $serializer->deserialize($content, 'App\Entity\Organization', 'xml');
        }

        if (is_null($organization)) {
            if (is_null($id)) {
                // add new
                $organization = new \App\Entity\Organization();
            }
            else {
                $request->getSession()
                        ->getFlashBag()
                        ->add('warning', 'No item found for id: ' . $id)
                    ;

                return $this->redirect($this->generateUrl('organization-list'));
            }
        }

        $form = $this->get('form.factory')
                    ->create(\App\Form\Type\OrganizationType::class, $organization)
                    ;

        if ($request->getMethod() == 'POST') {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                if (!$update) {
                    $id = $this->nextInSequence($client, $client->getCollection());
                    $organization->setId($id);
                }

                $redirectUrl = $this->generateUrl('organization-detail', [ 'id' => $id ]);

                $content = $serializer->serialize($organization, 'xml');
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
                            ->add('in', 'Entry ' . ($update ? ' updated' : ' created'));
                        ;
                }

                return $this->redirect($redirectUrl);
            }
        }

        return $this->render('Organization/edit.html.twig', [
            'form' => $form->createView(),
            'entity' => $organization,
        ]);
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
