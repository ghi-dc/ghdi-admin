<?php

// src/Controller/DefaultController.php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * Render Administration home page.
 */
class DefaultController extends AbstractController
{
    #[Route(path: '/', name: 'home')]
    public function homeAction(Request $request): Response
    {
        return $this->render('Home/home.html.twig', [
        ]);
    }
}
