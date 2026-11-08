<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class WorkspaceFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Название',
                'constraints' => [new NotBlank()],
            ])
            ->add('slug', TextType::class, [
                'label' => 'Ключ (папка в workspaces/)',
                'help' => 'Каталог workspaces/{ключ}/ с manifest.md. URL: /workspaces/demo-main.',
                'constraints' => [
                    new NotBlank(),
                    new Regex([
                        'pattern' => '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                        'message' => 'Только a-z, 0-9 и дефис (например demo-main).',
                    ]),
                ],
            ])
            ->add('location', ChoiceType::class, [
                'label' => 'Размещение',
                'choices' => [
                    'Локально' => 'local',
                    'Сервер' => 'server',
                ],
            ])
            ->add('sortOrder', IntegerType::class, [
                'label' => 'Порядок сортировки',
                'required' => false,
                'empty_data' => '0',
                'attr' => ['min' => 0, 'step' => 1],
            ])
            ->add('avatarUpload', FileType::class, [
                'label' => 'Аватар',
                'required' => false,
                'mapped' => true,
                'constraints' => [
                    new File([
                        'maxSize' => '2M',
                        'mimeTypes' => [
                            'image/jpeg',
                            'image/png',
                            'image/gif',
                            'image/webp',
                        ],
                        'mimeTypesMessage' => 'Загрузите изображение JPEG, PNG, GIF или WebP.',
                    ]),
                ],
            ])
            ->add('save', SubmitType::class, [
                'label' => $options['submit_label'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => WorkspaceData::class,
            'submit_label' => 'Сохранить',
        ]);

        $resolver->setAllowedTypes('submit_label', 'string');
    }
}
