<?php

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EntityIdentifierType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $types = $options['types'];

        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'Type of Identifier',
                'required' => true,
                'choices' => array_flip($types),
            ])
            ->add('identifier', TextType::class, [
                'label' => 'Identifier',
                'required' => true,
            ])
        ;

        $builder->add('lookup', SubmitType::class, [
            'label' => 'Lookup',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'types' => [
                'gnd' => 'GND',
                'lcauth' => 'LoC authority ID',
                'wikidata' => 'Wikidata QID',
            ],
        ]);
    }

    public function getName()
    {
        return 'entityidentifiertype';
    }
}
