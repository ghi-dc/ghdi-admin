<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 *
 */
class DefaultController
extends AbstractController
{
    /**
     * @Route("/", name="home")
     */
    public function homeAction(Request $request)
    {
        return $this->render('Home/home.html.twig', [
        ]);
    }
}
