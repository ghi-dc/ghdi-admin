<?php

// src/Security/HttpBasicAuthenticator

namespace App\Security;

use App\Security\User\ExistDbUser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\PassportInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

class HttpBasicAuthenticator implements AuthenticatorInterface, AuthenticationEntryPointInterface
{
    private $realmName = 'Basic-Auth';
    private $userProvider;

    public function __construct(UserProviderInterface $userProvider)
    {
        $this->userProvider = $userProvider;
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $response = new Response();
        $response->headers->set('WWW-Authenticate', sprintf('Basic realm="%s"', $this->realmName));
        $response->setStatusCode(401);

        return $response;
    }

    public function supports(Request $request): ?bool
    {
        return $request->headers->has('PHP_AUTH_USER');
    }

    public function authenticate(Request $request): Passport
    {
        $username = $request->headers->get('PHP_AUTH_USER');
        $password = $request->headers->get('PHP_AUTH_PW', '');

        // @deprecated since Symfony 5.3, change to $this->userProvider->loadUserByIdentifier() in 6.0
        $method = 'loadUserByIdentifier';
        if (!method_exists($this->userProvider, 'loadUserByIdentifier')) {
            trigger_deprecation('symfony/security-core', '5.3', 'Not implementing method "loadUserByIdentifier()" in user provider "%s" is deprecated. This method will replace "loadUserByUsername()" in Symfony 6.0.', get_debug_type($this->userProvider));

            $method = 'loadUserByUsername';
        }

        $passport = new Passport(
            new UserBadge($username, [$this->userProvider, $method]),
            new CustomCredentials(function ($credentials, ExistDbUser $user) {
                $user->setPassword($credentials); // since userProvider doesn't fetch password

                return $this->userProvider->isPasswordValid($user->getUsername(), $credentials);
            }, $password)
        );

        return $passport;
    }

    /**
     * @deprecated since Symfony 5.4, use {@link createToken()} instead
     */
    public function createAuthenticatedToken(PassportInterface $passport, string $firewallName): TokenInterface
    {
        trigger_deprecation('symfony/security-http', '5.4', 'Method "%s()" is deprecated, use "%s::createToken()" instead.', __METHOD__, __CLASS__);

        return $this->createToken($passport, $firewallName);
    }

    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        return new UsernamePasswordToken($passport->getUser(), $firewallName, $passport->getUser()->getRoles());
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return $this->start($request, $exception);
    }
}
