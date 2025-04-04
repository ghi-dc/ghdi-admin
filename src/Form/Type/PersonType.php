<?php

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class PersonType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('givenName', TextType::class, [
                'label' => 'Given Name',
                'required' => false,
            ])
            ->add('familyName', TextType::class, [
                'label' => 'Family Name',
                'required' => false,
            ])
            ->add(
                $builder
                ->create('group-names', FormType::class, [
                    'label' => 'Localized Names',
                    'inherit_data' => true,
                ])
                ->add('name_en', TextType::class, [
                    'label' => 'English',
                    'required' => false,
                ])
                ->add('name_de', TextType::class, [
                    'label' => 'German',
                    'required' => false,
                ])
            )
            ->add(
                $builder
                ->create('group-descriptions', FormType::class, [
                    'label' => 'Disambiguating Description',
                    'inherit_data' => true,
                ])
                ->add('disambiguating_description_en', TextareaType::class, [
                    'label' => 'English',
                    'required' => false,
                    'attr' => [
                        'rows' => 2,
                    ],
                ])
                ->add('disambiguating_description_de', TextareaType::class, [
                    'label' => 'German',
                    'required' => false,
                    'attr' => [
                        'rows' => 2,
                    ],
                ])
            )
            ->add('birthDate', TextType::class, [
                'label' => 'Date of Birth',
                'required' => false,
            ])
            ->add('deathDate', TextType::class, [
                'label' => 'Date of Death',
                'required' => false,
            ])
            ->add('gender', ChoiceType::class, [
                'label' => 'Gender',
                'multiple' => false,
                'required' => false,
                'choices' => ['Female' => 'Female', 'Male' => 'Male'],
            ])
            ->add(
                $builder
                ->create('group-identifiers', FormType::class, [
                    'label' => 'Identifiers',
                    'inherit_data' => true,
                ])
                ->add('gnd', TextType::class, [
                    'label' => 'GND',
                    'required' => false,
                ])
                ->add('wikidata', TextType::class, [
                    'label' => 'Wikidata',
                    'required' => false,
                ])
                ->add('lcauth', TextType::class, [
                    'label' => 'LoC authority ID',
                    'required' => false,
                ])
                ->add('viaf', TextType::class, [
                    'label' => 'VIAF',
                    'required' => false,
                ])
            )
        ;

        $builder->add('save', SubmitType::class, [
            'label' => 'Save',
        ]);
    }

    public function getName()
    {
        return 'persontype';
    }
}
