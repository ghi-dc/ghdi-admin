<?php

// src/Controller/MediaController.php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Basic controller to deliver binary images stored in exist to the browser.
 */
class MediaController extends BaseController
{
    protected $subCollection = '/data/media';

    /**
     * By default, the Symfony Routing component requires that the parameters match the following regular expression: [^/]+.
     * This means that all characters are allowed except /.
     */
    #[Route(path: '/media/{path}', requirements: ['path' => '.+'])]
    public function sendAction(Request $request, $path)
    {
        try {
            // try to get media from repository
            $client = $this->getExistDbClient($this->subCollection);
            $media = $client->getBinaryResource($path);
            if (false !== $media) {
                $info = $client->describeResource($path);

                // TODO: depending on $info, maybe build a smaller version for online presentation
                // or do some file-system caching

                return new Response($media, Response::HTTP_OK, [
                    'content-type' => $info['mime-type'],
                ]);
            }
        }
        catch (\Exception $e) {
            // ignore
        }

        throw $this->createNotFoundException('/' . $path . ' not found.');
    }
}
