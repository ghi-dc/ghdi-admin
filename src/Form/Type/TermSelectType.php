<?php

namespace App\Form\Type;

use App\Form\DataTransformer\IdToTermTransformer;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\RouterInterface;

class TermSelectType
extends AbstractType
{
    private $router;

    public function __construct(RouterInterface $router)
    {
        $this->router = $router;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->addModelTransformer(new IdToTermTransformer());
    }

    public function getParent()
    {
        return ChoiceType::class;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'invalid_message' => 'No matching term found !',
            // term-autocomplete isn't implemented yet
            /*
            'finder_callback' => function(string $name) {
                $term = new \App\Entity\Term();
                $term->setId('term-1');
                $term->setName('Term 1');

                return $term;
            },
            'attr' => [
                'class' => 'js-user-autocomplete',
                'data-autocomplete-url' => $this->router->generate('term-autocomplete')
            ]
            */
        ]);
    }
}
