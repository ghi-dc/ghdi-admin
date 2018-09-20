<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 *
 */
class BibliographyController
extends BaseController
{
    const API_PAGE_SIZE = 50; // for sync

    protected $subCollection = '/data/bibliography';

    /**
     * @Route("/bibliography", name="bibliography-list")
     */
    public function listAction(Request $request)
    {
        $client = $this->getExistDbClient($this->subCollection);

        $q = trim($request->request->get('q'));
        $xql = $this->renderView('Bibliography/list-bibl.xql.twig', [
            'q' => $q,
        ]);

        $query = $client->prepareQuery($xql);
        $query->bindVariable('collection', $client->getCollection());
        $query->bindVariable('locale', $request->getLocale());
        $query->bindVariable('q', $q);
        $res = $query->execute();
        $listBibl = $res->getNextResult();
        $res->release();

        $creativeWorks = [];
        $simplexml = simplexml_load_string($listBibl);
        foreach ($simplexml as $elem) {
            $creativeWorks[] = \App\Entity\CreativeWork::fromTei($elem->asXML());
        }

        $path = $this->get('kernel')->getProjectDir()
            . '/data/styles/apa.csl';
        $citeProc = new \Seboettg\CiteProc\CiteProc(file_get_contents($path), $locale = 'en-US');

        return $this->render('Bibliography/list.html.twig', [
            'q' => $q,
            'creativeWorks' => $creativeWorks,
            'citeProc' => $citeProc,
        ]);
    }

    /**
     * @Route("/bibliography/{id}.tei.xml", name="bibliography-detail-tei", requirements={"id" = "[0-9A-Z]+"})
     * @Route("/bibliography/{id}", name="bibliography-detail", requirements={"id" = "[0-9A-Z]+"})
     */
    public function detailAction(Request $request, $id)
    {
        $client = $this->getExistDbClient($this->subCollection);

        $xql = $this->renderView('Bibliography/list-bibl.xql.twig', [
            'id' => $id,
        ]);

        $query = $client->prepareQuery($xql);
        $query->bindVariable('collection', $client->getCollection());
        $query->bindVariable('locale', $request->getLocale());
        $query->bindVariable('q', '');
        $res = $query->execute();
        $listBibl = $res->getNextResult();
        $res->release();

        $simplexml = simplexml_load_string($listBibl);
        if (0 == count($simplexml)) {
            $request->getSession()
                    ->getFlashBag()
                    ->add('warning', 'No item found for id: ' . $id)
                ;

            return $this->redirect($this->generateUrl('organization-list'));
        }

        foreach ($simplexml as $elem) {
            break;
        }

        if ('bibliography-detail-tei' == $request->get('_route')) {
            $listBibl = sprintf('<?' . 'xml version="1.0" encoding="UTF-8"?>
<listBibl xmlns="http://www.tei-c.org/ns/1.0">%s</listBibl>' . "\n",
                                "\n"
                                . preg_replace('/ n="\d+"/', '', str_replace(' id="' . $id . '"', '', $elem->asXML()))
                                . "\n");

            $response = new Response($listBibl);
            $response->headers->set('Content-Type', 'xml');

            return $response;
        }

        $creativeWork = \App\Entity\CreativeWork::fromTei($elem->asXML());

        $path = $this->get('kernel')->getProjectDir()
            . '/data/styles/apa.csl';
        $citeProc = new \Seboettg\CiteProc\CiteProc(file_get_contents($path), $locale = 'en-US');

        $zoteroApiService = $this->get(\App\Service\ZoteroApiService::class);

        return $this->render('Bibliography/detail.html.twig', [
            'creativeWork' => $creativeWork,
            'citeProc' => $citeProc,
            'groupId' => $zoteroApiService->getGroupId(),
        ]);
    }

    /**
     * @Route("/bibliography/sync", name="bibliography-sync")
     */
    public function syncAction(Request $request)
    {
        $zoteroApiService = $this->get(\App\Service\ZoteroApiService::class);

        // TODO: get maxModified as by
        // https://hcmc.uvic.ca/blogs/index.php?blog=11&p=8947
        $maxModified = null;

        $start = 0;
        $continue = true;

        $fetched = $updated = 0;

        $xql = $this->renderView('Bibliography/sync-version-json.xql.twig', [
        ]);
        $client = $this->getExistDbClient($this->subCollection);

        $query = $client->prepareQuery($xql);

        while ($continue) {
            $api = $zoteroApiService->getInstance(); // we need a new instance in each iteration since parameters get added multiple times
            $apiRequest = $api
                ->items()
                ->sortBy('dateModified')
                ->direction(is_null($maxModified) ? 'asc' : 'desc')
                ->start($start)
                ->limit(self::API_PAGE_SIZE);

            set_time_limit(60);

            try {
                $response = $apiRequest->send();
            }
            catch (\GuzzleHttp\Exception\ClientException $e) {
                $request->getSession()
                        ->getFlashBag()
                        ->add('error', sprintf('Error requesting items %s (%s)',
                                               $start, $e->getResponse()->getStatusCode()));
                    ;

                return $this->redirect($this->generateUrl('bibliography-list'));
            }

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                // something went wrong
                break;
            }

            $headers = $response->getHeaders();

            $start += self::API_PAGE_SIZE;
            $continue = $start < $headers['Total-Results'][0];

            // now process the items
            $items = $response->getBody();
            foreach ($items as $item) {
                $dateModified = $item['data']['dateModified'];
                if (!is_null($maxModified) && strcmp($dateModified, $maxModified) < 0) {
                    $continue = false;
                    break;
                }

                if (!empty($item['key']) && !in_array($item['data']['itemType'], [ 'note', 'attachment' ])) {
                    $creativeWork = \App\Entity\CreativeWork::fromZotero($item['data'], $item['meta']);

                    $id = $creativeWork->getId();
                    $query->setJSONReturnType();
                    $query->bindVariable('collection', $client->getCollection());
                    $query->bindVariable('id', $id);
                    $res = $query->execute();
                    $info = $res->getNextResult();
                    $res->release();

                    $store = true;
                    if (!is_null($info) && array_key_exists('data', $info)) {
                        if(!empty($info['data']['n']) && $info['data']['n'] >= $creativeWork->getVersion()) {
                            $store = false;
                        }
                    }

                    ++$fetched;
                    if (!$store) {
                        // already uptodate
                        continue;
                    }

                    $content = $creativeWork->teiSerialize();

                    $name = $id . '.xml';
                    $update = !is_null($info);
                    $res = $client->parse($content, $name, $update);
                    if (!$res) {
                        $request->getSession()
                                ->getFlashBag()
                                ->add('warning', 'An issue occured while storing id: ' . $id)
                            ;
                    }
                    else {
                        if ($update) {
                            ++$updated;
                        }

                        $request->getSession()
                                ->getFlashBag()
                                ->add('info', 'Entry ' . ($update ? ' updated' : ' created'));
                            ;
                    }
                }
            }
        }

        $request->getSession()
                ->getFlashBag()
                ->add('info', sprintf('Fetched %s items (%s updated)',
                                       $fetched, $updated));
            ;

        return $this->redirect($this->generateUrl('bibliography-list'));
    }
}
