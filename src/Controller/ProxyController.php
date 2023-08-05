<?php
// src/Controller/ProxyController.php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Simple pass-through proxy since CollectiveAccess sends wrong mime-type
 * for svg
 */
class ProxyController
extends BaseController
{
    private $client;

    public function __construct(HttpClientInterface $client)
    {
        $this->client = $client;
    }

    /**
     * @Route("/helper/svgproxy", name="svgproxy")
     */
    public function svgProxyAction(Request $request)
    {
        $url = $request->get('url');
        $clientResponse = $this->client->request('GET', $url);

        // Responses are lazy: this code is executed as soon as headers are received
        if (200 !== $clientResponse->getStatusCode()) {
            throw new \Exception($url . ' could not be fetched');
        }

        $contentType = $clientResponse->getHeaders()['content-type'][0];
        if (preg_match('/^text\/xml/', $contentType)) {
            $contentType = 'image/svg+xml';
        }

        $response = new StreamedResponse();
        $response->headers->set('Content-Type', $contentType);

        $response->setCallback(function () use ($clientResponse) {
            foreach ($this->client->stream($clientResponse) as $chunk) {
                echo $chunk->getContent();
                flush();
            }
        });

        return $response;
    }
}
