<?php

// src/Controller/DefaultController.php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * Render Administration home page.
 */
class DefaultController extends AbstractController
{
    #[Route(path: '/', name: 'home')]
    public function homeAction(Request $request)
    {
        return $this->render('Home/home.html.twig', [
        ]);
    }
}
