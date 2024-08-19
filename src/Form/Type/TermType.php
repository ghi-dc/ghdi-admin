<?php

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TermType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Name',
                'required' => true,
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
            ->add('broader', TermSelectType::class, [
                'label' => 'Broader Term',
                'required' => false,
                'choices'  => $options['choices']['terms'],
            ])
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
                ->add('lcauth', TextType::class, [
                    'label' => 'LoC authority ID',
                    'required' => false,
                ])
                ->add('wikidata', TextType::class, [
                    'label' => 'Wikidata',
                    'required' => false,
                ])
            )
        ;

        $builder->add('save', SubmitType::class, [
            'label' => 'Save',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'choices' => null,
        ]);
    }

    public function getName()
    {
        return 'termtype';
    }
}
