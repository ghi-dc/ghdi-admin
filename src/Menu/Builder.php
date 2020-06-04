<?php
// src/App/Menu/Builder.php

// registered in services.yml to pass $securityContext and $requestStack
// see http://symfony.com/doc/current/bundles/KnpMenuBundle/index.html
namespace App\Menu;

use Knp\Menu\FactoryInterface;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\PhpBridgeSessionStorage;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class Builder
{
    private $factory;
    private $authorizationChecker;
    private $translator;
    private $requestStack;
    private $showBibliography = false;

    /**
     * @param FactoryInterface $factory
     * @param RequestStack $requestStack
     *
     * Add any other dependency you need
     */
    public function __construct(FactoryInterface $factory,
                                AuthorizationCheckerInterface $authorizationChecker,
                                \Symfony\Contracts\Translation\TranslatorInterface $translator,
                                RequestStack $requestStack,
                                array $zoteroOptions)
    {
        $this->factory = $factory;
        $this->authorizationChecker = $authorizationChecker;
        $this->translator = $translator;
        $this->requestStack = $requestStack;
        $this->showBibliography = !empty($zoteroOptions) && !empty($zoteroOptions['group_id']);
    }

    public function createTopMenu(array $options)
    {
        $menu = $this->factory->createItem('root');
        if (array_key_exists('position', $options) && 'footer' == $options['position']) {
            $menu->setChildrenAttributes([ 'id' => 'menu-top-footer', 'class' => 'small' ]);
        }
        else {
            $menu->setChildrenAttributes([ 'id' => 'menu-top', 'class' => 'list-inline' ]);
        }

        return $menu;
    }

    public function createFooterMainMenu(array $options)
    {
        $options['position'] = 'footer';

        return $this->createMainMenu($options);
    }

    public function createMainMenu(array $options)
    {
        try {
            $loggedIn = $this->authorizationChecker->isGranted('IS_AUTHENTICATED_FULLY');
        }
        catch (\Exception $e) {
            // can happen on error pages
            $loggedIn = false;
        }

        // for translation, see http://symfony.com/doc/master/bundles/KnpMenuBundle/i18n.html
        $menu = $this->factory->createItem('home', [
            'label' => 'Home',
            'route' => 'home',
        ]);

        if (array_key_exists('position', $options) && 'footer' == $options['position']) {
            $menu->setChildrenAttributes([ 'id' => 'menu-main-footer', 'class' => 'nav navbar-nav navbar-expand-sm' ]);
        }
        else {
            $menu->setChildrenAttributes([ 'id' => 'menu-main', 'class' => 'nav navbar-nav navbar-expand-sm' ]);
        }

        $menu->addChild('volume-list', [
            'label' => $this->translator->trans('Volumes', [], 'menu'),
            'route' => 'volume-list',
        ]);

        $menu->addChild('Authority Control', [
            'label' => $this->translator->trans('Authority Control', [], 'menu'),
            'route' => 'person-list',
        ]);
        $menu['Authority Control']->addChild('person-list', [
            'label' => $this->translator->trans('Persons', [], 'menu'),
            'route' => 'person-list',
        ]);
        $menu['Authority Control']->addChild('organization-list', [
            'label' => $this->translator->trans('Organizations', [], 'menu'),
            'route' => 'organization-list',
        ]);
        $menu['Authority Control']->addChild('place-list', [
            'label' => $this->translator->trans('Places', [], 'menu'),
            'route' => 'place-list',
        ]);
        $menu['Authority Control']->addChild('term-list', [
            'label' => $this->translator->trans('Subject Headings', [], 'menu'),
            'route' => 'term-list',
        ]);

        if ($this->showBibliography) {
            $menu->addChild('bibliography-list', [
                'label' => $this->translator->trans('Bibliography', [], 'menu'),
                'route' => 'bibliography-list',
            ]);
        }

        $menu->addChild('ca-list', [
            'label' => $this->translator->trans('Collective Access', [], 'menu'),
            'route' => 'ca-list',
        ]);

        // find the matching parent
        // TODO: maybe use a voter, see https://gist.github.com/nateevans/9958390
        $uriCurrent = $this->requestStack->getCurrentRequest()->getRequestUri();

        // create the iterator
        $itemIterator = new \Knp\Menu\Iterator\RecursiveItemIterator($menu);

        // iterate recursively on the iterator
        $iterator = new \RecursiveIteratorIterator($itemIterator, \RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $item) {
            $uri = $item->getUri();
            if (substr($uriCurrent, 0, strlen($uri)) === $uri) {
                $item->setCurrent(true);
                break;
            }
        }

        return $menu;
    }

    public function createBreadcrumbMenu(array $options)
    {
        $menu = $this->createMainMenu($options + [ 'position' => 'breadcrumb' ]);

        // try to return the active item
        $currentRoute = $this->requestStack->getCurrentRequest()->get('_route');

        if ('home' == $currentRoute) {
            return $menu;
        }

        // first level
        $item = $menu[$currentRoute];
        if (isset($item)) {
            return $item;
        }

        // additional routes
        if (preg_match('/^(person|organization|place)\-(list|detail|edit)/', $currentRoute, $matches)) {
            $item = $menu['Authority Control'];
            $item->setUri(null);

            $item = $item->addChild($currentRoute, [
                'label' => ucfirst($matches[1] . 's'),
                'route' => $matches[1] . '-list',
            ]);

            if ('list' != $matches[2]) {
                $item = $item->addChild($currentRoute, [
                    'label' => ucfirst($matches[2]),
                ]);
            }

            return $item;
        }

        return $menu;
    }
}
