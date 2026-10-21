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
    // Under Resources, beside Items and Batch Values: the desk opens it every day, and a
    // page used every day does not belong under Modules (audit-staff-ux R11, 1.0.5).
    'navigation' => [
        'AdminResource' => [
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
