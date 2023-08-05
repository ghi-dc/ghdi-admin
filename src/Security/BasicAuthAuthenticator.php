<?php
// src/Security/BasicAuthAuthenticator.php

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Guard\AbstractGuardAuthenticator;

/*
 * see https://symfony.com/doc/current/security/guard_authentication.html
 */
class BasicAuthAuthenticator
extends AbstractGuardAuthenticator
{
    private $realmName = 'Basic-Auth';
    private $userProvider;

    public function __construct(UserProviderInterface $userProvider)
    {
        $this->userProvider = $userProvider;
    }

    public function supports(Request $request)
    {
        return !empty($request->getUser());
    }

    public function getCredentials(Request $request)
    {
        $credentials = [
            'username' => $request->getUser(),
            'password' => $request->getPassword(),
        ];

        return $credentials;
    }

    public function getUser($credentials, UserProviderInterface $userProvider)
    {
        try {
            $user = $userProvider->loadUserByUsername($credentials['username']);
        } catch (UsernameNotFoundException $exception) {
            // CAUTION: this message will be returned to the client
            // (so don't put any un-trusted messages / error strings here)
            throw new AuthenticationException('Invalid username or password.');
        }

        if (!$user) {
            // fail authentication with a custom error
            throw new AuthenticationException('User could not be found.');
        }

        $user->setPassword($credentials['password']);

        return $user;
    }

    public function checkCredentials($credentials, UserInterface $user)
    {
        return $this->userProvider->isPasswordValid($user->getUsername(), $credentials['password']);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, $providerKey)
    {
        // on success, let the request continue
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception)
    {
        return $this->start($request, $exception);
    }

    /**
     * Called when authentication is needed, but it's not sent
     */
    public function start(Request $request, AuthenticationException $authException = null)
    {
        // see  https://github.com/symfony/security-http/blob/master/EntryPoint/BasicAuthenticationEntryPoint.php
        $response = new Response();
        $response->headers->set('WWW-Authenticate', sprintf('Basic realm="%s"', $this->realmName));
        $response->setStatusCode(401);

        return $response;
    }

    public function supportsRememberMe()
    {
        return false;
    }
}
