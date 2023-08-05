<?php
// src/Controller/UserController.php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Contracts\Translation\TranslatorInterface;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * Login and logout actions
 */
class UserController
extends AbstractController
{
    /**
     * @Route("/login", name="login")
     */
    public function loginAction(Request $request,
                                TranslatorInterface $translator,
                                AuthenticationUtils $authenticationUtils = null)
    {
        // last username entered by the user
        $defaultData = [ '_username' => $authenticationUtils->getLastUsername() ];

        // csrf_token_id needs to match with the id in LoginFormAuthenticator
        $form = $this->createFormBuilder($defaultData, [ 'csrf_token_id' => 'authenticate' ])
            ->add('_username', \Symfony\Component\Form\Extension\Core\Type\TextType::class, [
                'label' => 'Username',
            ])
            ->add('_password', \Symfony\Component\Form\Extension\Core\Type\PasswordType::class, [
                'label' => 'Password',
            ])
            ->add('_login', \Symfony\Component\Form\Extension\Core\Type\SubmitType::class, [
                'label' => 'Login',
            ])
            ->getForm()
            ;

        // set the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();
        if (!empty($error)) {
            $form->addError(new \Symfony\Component\Form\FormError(
                /** @Ignore */$translator->trans($error->getMessageKey(), [], 'additional')
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
    public function logoutAction(Request $request, TokenStorageInterface $tokenStorage)
    {
        $tokenStorage->setToken(null);
        $request->getSession()->invalidate();

        return $this->redirect($this->generateUrl('login'));
    }
}
