<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * Provide a few services
 */
class ApiController
extends AbstractController
{
    /**
     * @Route("/api/slugify/{text}", name="api-slugify", requirements={"text"=".*"})
     */
    public function slugifyAction(Request $request,
                                  \Cocur\Slugify\SlugifyInterface $slugify,
                                  $text)
    {
        return new JsonResponse([
            'text' => $text,
            'slug' => $slugify->slugify($text),
        ]);
    }
}
