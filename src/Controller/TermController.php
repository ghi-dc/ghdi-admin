<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 *
 */
class TermController
extends BaseController
{
    protected $subCollection = '/data/authority/terms';

    /**
     * @Route("/term", name="term-list")
     */
    public function listAction(Request $request)
    {
        $client = $this->getExistDbClient($this->subCollection);

        $q = trim($request->request->get('q'));
        $xql = $this->renderView('Term/list-json.xql.twig', [
            'q' => $q,
        ]);

        $query = $client->prepareQuery($xql);
        $query->setJSONReturnType();
        $query->bindVariable('collection', $client->getCollection());
        $query->bindVariable('locale', $request->getLocale());
        $query->bindVariable('q', $q);
        $res = $query->execute();
        $terms = $res->getNextResult();
        $res->release();

        return $this->render('Term/list.html.twig', [
            'q' => $q,
            'terms' => $terms,
        ]);
    }

    protected function fetchEntity($client, $id)
    {
        if ($client->hasDocument($name = $id . '.xml')) {
            $content = $client->getDocument($name);

            $serializer = $this->getSerializer();

            return $serializer->deserialize($content, 'App\Entity\Term', 'xml');
        }

        return null;
    }

    /**
     * @Route("/term/{id}", name="term-detail", requirements={"id" = "term\-\d+"})
     */
    public function detailAction(Request $request, $id)
    {
        $client = $this->getExistDbClient($this->subCollection);

        $entity = $this->fetchEntity($client, $id);
        if (is_null($entity)) {
            $request->getSession()
                    ->getFlashBag()
                    ->add('warning', 'No entry found for id: ' . $id)
                ;

            return $this->redirect($this->generateUrl('term-list'));
        }

        $broader = null;
        /*
        // TODO
        $term->getBroader();
        if (!is_null($broader)) {
            $broader = $this->findByIdentifier($containedIn->getTgn(), 'tgn', true);
        }
        */

        return $this->render('Term/detail.html.twig', [
            'entity' => $entity,
            'broader' => $broader,
        ]);
    }

    protected function nextInSequence($client, $collection)
    {
        // see https://stackoverflow.com/a/48901690
        $xql = <<<EOXQL
    declare variable \$collection external;
    let \$terms := collection(\$collection)/CategoryCode
    return (for \$key in (1 to 9999)!format-number(., '0')
        where empty(\$terms[@id='term-'||\$key])
        return 'term-' || \$key)[1]
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

    protected function persist($client, $term, $update = false)
    {
        if (!$update) {
            $id = $this->nextInSequence($client, $client->getCollection());
            $term->setId($id);
        }

        $serializer = $this->getSerializer();

        $content = $serializer->serialize($term, 'xml');

        $id = $term->getId();
        $name = $id . '.xml';

        $res = $client->parse($content, $name, $update);

        return $term;
    }

    protected function findByIdentifier($value, $type = 'gnd', $fetchEntity = false)
    {
        $xql = $this->renderView('Term/lookup-by-identifier-json.xql.twig', [
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

            return $this->fetchEntity($client, $id);
        }

        return $info;
    }

    /**
     * @Route("/term/add-from-identifier", name="term-add-from-identifier")
     */
    public function addFromIdentifierAction(Request $request)
    {
        $types = [
            'gnd' => 'GND',
            // 'lcauth' => 'LoC authority ID',
            'wikidata' => 'Wikidata QID',
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

                    return $this->redirect($this->generateUrl('term-detail', [
                        'id' => $id,
                    ]));
                }


                switch ($data['type']) {
                    case 'wikidata':
                        $found = false;
                        $lodService = new \App\Utils\Lod\LodService(new \App\Utils\Lod\Provider\WikidataProvider());
                        $identifier = \App\Utils\Lod\Identifier\Factory::byName($data['type']);
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

                        if (!$found) {
                            $request->getSession()
                                    ->getFlashBag()
                                    ->add('warning', 'Could not find a corresponding GND')
                                ;

                            break;
                        }
                        // fallthrough
                    case 'gnd':
                        $identifier = new \App\Utils\Lod\Identifier\GndIdentifier($data['identifier']);

                        $lodService = new \App\Utils\Lod\LodService(new \App\Utils\Lod\Provider\DnbProvider());
                        $entity = $lodService->lookup($identifier);

                        if (!is_null($entity) && $entity instanceof \App\Entity\Term) {
                            // display for review
                            $request->getSession()
                                    ->getFlashBag()
                                    ->add('info', 'Please review and enhance before pressing [Save]')
                                ;

                            $form = $this->get('form.factory')
                                    ->create(\App\Form\Type\TermType::class, $entity, [
                                        'action' => $this->generateUrl('term-add'),
                                    ])
                                    ;


                            return $this->render('Term/edit.html.twig', [
                                'form' => $form->createView(),
                                'entity' => $entity,
                            ]);
                        }
                        else {
                            $request->getSession()
                                    ->getFlashBag()
                                    ->add('warning', 'No term found for GND: ' . $data['identifier'])
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

        return $this->render('Term/add-from-identifier.html.twig', [
            'form' => $form->createView(),
            'data' => $data,
        ]);
    }

    /**
     * @Route("/term/{id}/edit", name="term-edit", requirements={"id" = "term\-\d+"})
     * @Route("/term/add", name="term-add")
     */
    public function editAction(Request $request, $id = null)
    {
        $update = 'term-edit' == $request->get('_route');

        $client = $this->getExistDbClient($this->subCollection);

        $entity = null;

        if (!is_null($id)) {
            $entity = $this->fetchEntity($client, $id);
        }

        if (is_null($entity)) {
            if (is_null($id)) {
                // add new
                $entity = new \App\Entity\Term();
            }
            else {
                $request->getSession()
                        ->getFlashBag()
                        ->add('warning', 'No entry found for id: ' . $id)
                    ;

                return $this->redirect($this->generateUrl('term-list'));
            }
        }

        $form = $this->get('form.factory')->create(\App\Form\Type\TermType::class,
                                                   $entity);
        if ($request->getMethod() == 'POST') {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $res = $this->persist($client, $entity, $update);
                if (!$res) {
                    $request->getSession()
                            ->getFlashBag()
                            ->add('warning', 'An issue occured while storing id: ' . $id)
                        ;
                }
                else {
                    if (is_null($id)) {
                        $id = $res->getId();
                    }

                    $request->getSession()
                            ->getFlashBag()
                            ->add('info', 'Entry ' . ($update ? ' updated' : ' created'));
                        ;
                }

                return $this->redirect($this->generateUrl('term-detail', [ 'id' => $id ]));
            }
        }

        return $this->render('Term/edit.html.twig', [
            'form' => $form->createView(),
            'entity' => $entity,
        ]);
    }

    /**
     * @Route("/term/{id}/lookup-identifier", name="term-lookup-identifier", requirements={"id" = "term\-\d+"})
     */
    public function enhanceAction(Request $request, $id)
    {
        $client = $this->getExistDbClient($this->subCollection);

        $entity = $this->fetchEntity($client, $id);

        if (is_null($entity)) {
            $request->getSession()
                    ->getFlashBag()
                    ->add('warning', 'No entry found for id: ' . $id)
                ;

            return $this->redirect($this->generateUrl('term-list'));
        }

        if (!$entity->hasIdentifiers()) {
            $request->getSession()
                    ->getFlashBag()
                    ->add('warning', 'Entry has no identifier')
                ;

            return $this->redirect($this->generateUrl('term-detail', [ 'id' => $id ]));
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

        return $this->redirect($this->generateUrl('term-detail', [ 'id' => $id ]));
    }

    /**
     * @Route("/term/test", name="term-test")
     */
    public function testAction(Request $request)
    {
        $term = new \App\Entity\Term();

        $term->setId('term-1');
        $term->setName('Eugenik');
        $term->setIdentifier('gnd', '4015656-4');
        // $term->setBroader($province);

        $serializer = $this->getSerializer();

        $content = $serializer->serialize($term, 'json');
        var_dump($content);

        $content = $serializer->serialize($term, 'xml');
        var_dump($content);

        $term = $serializer->deserialize($content, 'App\Entity\Term', 'xml');
        var_dump($term);

        exit;
    }
}
