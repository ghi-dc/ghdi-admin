<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

/**
 * Render a Login form
 */
class UserController
extends Controller
{
    /**
     * @Route("/login", name="login")
     */
    public function loginAction(Request $request, AuthenticationUtils $authenticationUtils = null)
    {
        if (is_null($authenticationUtils)) {
            $authenticationUtils = $this->get('security.authentication_utils');
        }

        // last username entered by the user
        $defaultData = [ '_username' => $authenticationUtils->getLastUsername() ];
        $form = $this->createFormBuilder($defaultData)
            ->add('_username', \Symfony\Component\Form\Extension\Core\Type\TextType::class)
            ->add('_password', \Symfony\Component\Form\Extension\Core\Type\PasswordType::class)
            ->add('Login', \Symfony\Component\Form\Extension\Core\Type\SubmitType::class)
            ->getForm()
            ;

        // set the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();
        if (!empty($error)) {
            $form->addError(new \Symfony\Component\Form\FormError(
                $error->getMessageKey()
            ));
        }

        $form->handleRequest($request);

        return $this->render('User/login.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/logout", name="logout")
     */
    public function logoutAction(Request $request)
    {
        $this->get('security.token_storage')->setToken(null);
        $request->getSession()->invalidate();

        return $this->redirect($this->generateUrl('login'));
    }
}
