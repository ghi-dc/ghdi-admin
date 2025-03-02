<?php

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TeiHeaderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Title',
                'required' => true,
                'attr' => [
                    'class' => 'tei inline',
                ],
            ])
            ->add('dtaDirName', TextType::class, [
                'label' => 'URL Slug',
                'required' => false,
                'block_prefix' => 'generate_slug',
            ])
            ->add('translator', TextType::class, [
                'label' => 'Translation',
                'required' => false,
                'attr' => [
                    'class' => 'tei inline',
                ],
            ])
        ;

        if (!empty($options['choices']['terms'])) {
            $builder
                ->add('terms', ChoiceType::class, [
                    'choices'  => $options['choices']['terms'],
                    'label' => 'Subject Headings',
                    'required' => false,
                    'multiple' => true,
                    'attr' => [
                        'class' => 'select2',
                    ],
                ])
            ;
        }

        if (!empty($options['choices']['meta'])) {
            $builder
                ->add('meta', ChoiceType::class, [
                    'choices'  => $options['choices']['meta'],
                    'label' => 'Meta Tags',
                    'required' => false,
                    'multiple' => true,
                    'expanded' => true, // checkbox
                ])
            ;
        }

        $builder->add('save', SubmitType::class, [
            'label' => 'Save',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'choices' => null,
        ]);
    }

    public function getName()
    {
        return 'teiheadertype';
    }
}
