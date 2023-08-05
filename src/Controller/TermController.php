<?php
// src/Controller/Term.php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

use Symfony\Contracts\Translation\TranslatorInterface;

use JMS\Serializer\SerializationContext;

/**
 * CRUD for Term
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

    /**
     * @Route("/term/{id}", name="term-detail", requirements={"id" = "term\-\d+"})
     */
    public function detailAction(Request $request,
                                 TranslatorInterface $translator,
                                 $id)
    {
        $client = $this->getExistDbClient($this->subCollection);

        $entity = $this->fetchEntity($client, $id, \App\Entity\Term::class);
        if (is_null($entity)) {
            $request->getSession()
                    ->getFlashBag()
                    ->add('warning', sprintf($translator->trans('No entry found for id: %s'),
                                             $id))
                ;

            return $this->redirect($this->generateUrl('term-list'));
        }

        $broader = $entity->getBroader();
        if (!is_null($broader)) {
            if (!empty($broader->getId())) {
                // fetch since we only store the id and not everything else
                $broader = $this->fetchEntity($client, $broader->getId(), \App\Entity\Term::class);
                $entity->setBroader($broader);
            }
            else {
                $entity->setBroader(null);
            }
        }

        return $this->render('Term/detail.html.twig', [
            'entity' => $entity,
        ]);
    }

    protected function nextInSequence(\ExistDbRpc\Client $client, $collection)
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

    protected function persist(\ExistDbRpc\Client $client, $term, $update = false)
    {
        if (!$update) {
            $id = $this->nextInSequence($client, $client->getCollection());
            $term->setId($id);
        }

        $serializer = $this->getSerializer();

        $content = $serializer->serialize($term, 'xml', SerializationContext::create()->enableMaxDepthChecks());

        $id = $term->getId();
        $name = $id . '.xml';

        $res = $client->parse($content, $name, $update);

        return $term;
    }

    /**
     * @Route("/term/add-from-identifier", name="term-add-from-identifier")
     */
    public function addFromIdentifierAction(Request $request,
                                            TranslatorInterface $translator)
    {
        $types = [
            'gnd' => 'GND',
            'lcauth' => 'LoC authority ID',
            'wikidata' => 'Wikidata QID',
        ];

        $data = [];

        $form = $this->createForm(\App\Form\Type\EntityIdentifierType::class, $data, [
            'types' => $types,
        ]);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $info = $this->findTermByIdentifier(trim($data['identifier']), $data['type']);
            if (!empty($info['data'])) {
                $id = array_key_exists('id', $info['data'])
                    ? $info['data']['id'] : $info['data'][0]['id'];

                $request->getSession()
                        ->getFlashBag()
                        ->add('info', $translator->trans('There is already an entry for this identifier'))
                    ;

                return $this->redirect($this->generateUrl('term-detail', [
                    'id' => $id,
                ]));
            }

            if ('wikidata' == $data['type']) {
                // don't query directly but check instead for a sameAs
                $found = false;
                $lodService = new \App\Utils\Lod\LodService(new \App\Utils\Lod\Provider\WikidataProvider());
                $identifier = \App\Utils\Lod\Identifier\Factory::byName($data['type']);
                if (!is_null($identifier)) {
                    $identifier->setValue($data['identifier']);

                    $sameAs = $lodService->lookupSameAs($identifier);
                    if (!empty($sameAs)) {
                        foreach ($sameAs as $identifier) {
                            // hunt for a gnd or lcauth
                            if (in_array($identifier->getPrefix(), [ 'gnd', 'lcauth' ] )) {
                                $data['type'] = $identifier->getPrefix();
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
                             ->add('warning', $translator->trans('Could not find a corresponding GND'))
                        ;
                }
            }

            switch ($data['type']) {
                case 'wikidata':
                    // handled above
                    break;

                case 'gnd':
                case 'lcauth':
                    if ('gnd' == $data['type']) {
                        $identifier = new \App\Utils\Lod\Identifier\GndIdentifier($data['identifier']);
                        $lodService = new \App\Utils\Lod\LodService(new \App\Utils\Lod\Provider\DnbProvider());
                    }
                    else if ('lcauth' == $data['type']) {
                        $identifier = new \App\Utils\Lod\Identifier\LocLdsSubjectsIdentifier($data['identifier']);
                        $lodService = new \App\Utils\Lod\LodService(new \App\Utils\Lod\Provider\LocProvider());
                    }

                    $entity = $lodService->lookup($identifier);

                    if (!is_null($entity) && $entity instanceof \App\Entity\Term) {
                        // display for review
                        $request->getSession()
                                ->getFlashBag()
                                ->add('info', $translator->trans('Please review and enhance before pressing [Save]'))
                            ;

                        $termChoices = $this->buildTermChoicesById($request->getLocale(), $entity);
                        $form = $this->createForm(\App\Form\Type\TermType::class, $entity, [
                            'action' => $this->generateUrl('term-add'),
                            'choices' => [
                                'terms' => array_flip($termChoices),
                            ],
                        ]);

                        return $this->render('Term/edit.html.twig', [
                            'form' => $form->createView(),
                            'entity' => $entity,
                        ]);
                    }
                    else {
                        $request->getSession()
                                ->getFlashBag()
                                ->add('warning', sprintf($translator->trans('No entry found for: %s'),
                                                         $data['identifier']))
                            ;
                    }
                    break;

                default:
                    $request->getSession()
                            ->getFlashBag()
                            ->add('warning', sprintf($translator->trans('Not handling type: %s'),
                                                     $data['type']))
                        ;
            }
        }

        return $this->render('Term/add-from-identifier.html.twig', [
            'form' => $form->createView(),
            'data' => $data,
        ]);
    }

    protected function buildTermChoicesById($locale, $entity = null)
    {
        $client = $this->getExistDbClient($this->authorityPaths['terms']);

        $xql = $this->renderView('Term/list-choices-json.xql.twig', [
        ]);

        $query = $client->prepareQuery($xql);
        $query->setJSONReturnType();
        $query->bindVariable('collection', $client->getCollection());
        $query->bindVariable('locale', $locale);
        $query->bindVariable('q', '');
        $res = $query->execute();
        $terms = $res->getNextResult();
        $res->release();

        $choices = [];

        if (is_null($terms)) {
            return $choices;
        }

        foreach ($terms['data'] as $term) {
            $name = $term['name'];
            $choices[$term['id']] = $name;
        }

        return $choices;
    }

    /**
     * @Route("/term/{id}/edit", name="term-edit", requirements={"id" = "term\-\d+"})
     * @Route("/term/add", name="term-add")
     */
    public function editAction(Request $request,
                               TranslatorInterface $translator,
                               $id = null)
    {
        $update = 'term-edit' == $request->get('_route');

        $client = $this->getExistDbClient($this->subCollection);

        $entity = null;

        if (!is_null($id)) {
            $entity = $this->fetchEntity($client, $id, \App\Entity\Term::class);
        }

        if (is_null($entity)) {
            if (is_null($id)) {
                // add new
                $entity = new \App\Entity\Term();
            }
            else {
                $request->getSession()
                        ->getFlashBag()
                        ->add('warning', sprintf($translator->trans('No entry found for id: %s'),
                                                 $id))
                    ;

                return $this->redirect($this->generateUrl('term-list'));
            }
        }

        $termChoices = $this->buildTermChoicesById($request->getLocale(), $entity);
        $form = $this->createForm(\App\Form\Type\TermType::class, $entity, [
            'choices' => [
                'terms' => array_flip($termChoices),
            ],
        ]);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $res = $this->persist($client, $entity, $update);
            if (!$res) {
                $request->getSession()
                        ->getFlashBag()
                        ->add('warning', sprintf($translator->trans('An issue occured while storing id: %s'),
                                                 $id))
                    ;
            }
            else {
                if (is_null($id)) {
                    $id = $res->getId();
                }

                $request->getSession()
                        ->getFlashBag()
                        ->add('info',
                              $update
                              ? $translator->trans('The entry has been updated')
                              : $translator->trans('The entry has been created'));
                    ;
            }

            return $this->redirect($this->generateUrl('term-detail', [
                'id' => $id,
            ]));
        }

        return $this->render('Term/edit.html.twig', [
            'form' => $form->createView(),
            'entity' => $entity,
        ]);
    }

    /**
     * @Route("/term/{id}/lookup-identifier", name="term-lookup-identifier", requirements={"id" = "term\-\d+"})
     */
    public function enhanceAction(Request $request,
                                  TranslatorInterface $translator,
                                  $id)
    {
        $client = $this->getExistDbClient($this->subCollection);

        $entity = $this->fetchEntity($client, $id, \App\Entity\Term::class);

        if (is_null($entity)) {
            $request->getSession()
                    ->getFlashBag()
                    ->add('warning', sprintf($translator->trans('No entry found for id: %s'),
                                             $id))
                ;

            return $this->redirect($this->generateUrl('term-list'));
        }

        if (!$entity->hasIdentifiers()) {
            $request->getSession()
                    ->getFlashBag()
                    ->add('warning', $translator->trans('Entry has no identifier'))
                ;

            return $this->redirect($this->generateUrl('term-detail', [
                'id' => $id,
            ]));
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
                        $name = $identifier->getPrefix();
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
                        ->add('warning', sprintf($translator->trans('An issue occured while storing id: %s'),
                                                 $id))
                    ;
            }
            else {
                $request->getSession()
                        ->getFlashBag()
                        ->add('info', $translator->trans('The entry has been updated'));
                    ;
            }
        }
        else {
            $request->getSession()
                    ->getFlashBag()
                    ->add('info', $translator->trans('No additional information could be found'));
                ;
        }

        return $this->redirect($this->generateUrl('term-detail', [
            'id' => $id,
        ]));
    }

    /**
     * @Route("/term/test", name="term-test")
     */
    public function testAction(Request $request)
    {
        $term = new \App\Entity\Term();

        $term->setId('term-99');
        $term->setName('Rassehygiene');
        $term->setIdentifier('gnd', '4176978-8');

        $broader = new \App\Entity\Term();
        $broader->setId('term-1');
        $broader->setName('Eugenik');
        $broader->setIdentifier('gnd', '4015656-4');
        $term->setBroader($broader);

        /* test depth */
        $evenBroader = new \App\Entity\Term();
        $evenBroader->setId('term-100');
        $evenBroader->setName('Medizin');
        $broader->setBroader($evenBroader);

        $serializer = $this->getSerializer();

        $content = $serializer->serialize($term, 'json');
        var_dump($content);

        $content = $serializer->serialize($term, 'xml', SerializationContext::create()->enableMaxDepthChecks());
        var_dump($content);

        $term = $serializer->deserialize($content, 'App\Entity\Term', 'xml');
        var_dump($term);

        exit;
    }
}
