<?php
namespace RevisionHistory;

return [
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'view_helpers' => [
        'factories' => [
            'revisionHistoryShow' => Service\RevisionHistoryShowFactory::class,
        ],
    ],
    'controllers' => [
        'factories' => [
            Controller\Admin\IndexController::class => Service\IndexControllerFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'revision-history' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/revision-history[/:action[/:id]]',
                            'constraints' => [
                                'action' => 'browse|item|copy|watch|export|restore|revive|preview|refused',
                                'id' => '\d+',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'RevisionHistory\Controller\Admin',
                                'controller' => Controller\Admin\IndexController::class,
                                'action' => 'browse',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            [
                'label' => 'Revision History', // @translate
                'route' => 'admin/revision-history',
                'resource' => Controller\Admin\IndexController::class,
                'privilege' => 'browse',
                'class' => 'o-icon- fa-history',
            ],
        ],
    ],
];
