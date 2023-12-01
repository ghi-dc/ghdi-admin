<?php
// src/Security/FormLoginAuthenticator.php

namespace App\Security;

use App\Security\User\ExistDbUser;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\PasswordUpgradeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\PassportInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 */
class FormLoginAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    private $router;
    private $csrfTokenManager;
    private $userProvider;
    private $options = [];

    public function __construct(RouterInterface $router,
                                CsrfTokenManagerInterface $csrfTokenManager,
                                UserProviderInterface $userProvider,
                                array $options = [])
    {
        $this->router = $router;
        $this->csrfTokenManager = $csrfTokenManager;
        $this->userProvider = $userProvider;
        $this->options = array_merge([
            'post_only' => true,
            'form_only' => false,
            'enable_csrf' => true,
            'csrf_token_id' => 'authenticate',
        ], $options);
    }

    public function getLoginUrl(Request $request): string
    {
        return $this->router->generate('login');
    }

    private function getCredentials(Request $request)
    {
        $credentials = [
            'username' => $request->request->get('_username'),
            'password' => $request->request->get('_password'),
            'csrf_token' => $request->request->all('form')['_token'],
        ];

        $request->getSession()->set(
            Security::LAST_USERNAME,
            $credentials['username']
        );

        return $credentials;
    }

    public function authenticate(Request $request): Passport
    {
        $credentials = $this->getCredentials($request);

        // @deprecated since Symfony 5.3, change to $this->userProvider->loadUserByIdentifier() in 6.0
        $method = 'loadUserByIdentifier';
        if (!method_exists($this->userProvider, 'loadUserByIdentifier')) {
            trigger_deprecation('symfony/security-core', '5.3', 'Not implementing method "loadUserByIdentifier()" in user provider "%s" is deprecated. This method will replace "loadUserByUsername()" in Symfony 6.0.', get_debug_type($this->userProvider));

            $method = 'loadUserByUsername';
        }

        $passport = new Passport(
            new UserBadge($credentials['username'], [$this->userProvider, $method]),
            new CustomCredentials(function($credentials, ExistDbUser $user) {
                $user->setPassword($credentials); // since userProvider doesn't fetch password

                return $this->userProvider->isPasswordValid($user->getUsername(), $credentials);
            }, $credentials['password'])
        );

        if ($this->options['enable_csrf']) {
            $passport->addBadge(new CsrfTokenBadge($this->options['csrf_token_id'], $credentials['csrf_token']));
        }

        if ($this->userProvider instanceof PasswordUpgraderInterface) {
            $passport->addBadge(new PasswordUpgradeBadge($credentials['password'], $this->userProvider));
        }

        return $passport;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, $providerKey): ?Response
    {
        if ($targetPath = $this->getTargetPath($request->getSession(), $providerKey)) {
            return new RedirectResponse($targetPath);
        }

        return new RedirectResponse($this->router->generate('home'));
    }
}
