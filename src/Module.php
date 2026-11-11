<?php

declare(strict_types=1);

namespace MyFeedback\Wizard;

use Laminas\Router\Http\Literal;
use Laminas\ServiceManager\Factory\InvokableFactory;

final class Module
{
    public function getConfig(): array
    {
        $views = dirname(__DIR__) . '/view';

        return [
            'router' => [
                'routes' => [
                    'wizard' => [
                        'type' => Literal::class,
                        'options' => [
                            'route' => '/',
                            'defaults' => [
                                'controller' => WizardController::class,
                                'action' => 'index',
                            ],
                        ],
                    ],
                ],
            ],
            'controllers' => [
                'factories' => [
                    WizardController::class => InvokableFactory::class,
                    AccountController::class => InvokableFactory::class,
                ],
            ],
            'view_manager' => [
                'template_map' => [
                    'layout/layout' => $views . '/layout.phtml',
                    'layout/participation' => $views . '/layout-participation.phtml',
                    'wizard/branding' => $views . '/branding.phtml',
                    'wizard/step' => $views . '/step.phtml',
                    'wizard/preview' => $views . '/preview.phtml',
                    'wizard/questions' => $views . '/questions.phtml',
                    'wizard/results' => $views . '/results.phtml',
                    'wizard/issuer' => $views . '/issuer.phtml',
                    'wizard/bookkeeping' => $views . '/bookkeeping.phtml',
                    'wizard/account' => $views . '/account.phtml',
                    'wizard/workspace-nav' => $views . '/workspace-nav.phtml',
                    'wizard/participation' => $views . '/participation.phtml',
                    'wizard/poll-status' => $views . '/poll-status.phtml',
                    'wizard/addons' => $views . '/addons.phtml',
                    'wizard/fields' => $views . '/fields.phtml',
                    'wizard/contact-link' => $views . '/contact-link.phtml',
                    'wizard/combined' => $views . '/combined.phtml',
                    'wizard/notice' => $views . '/notice.phtml',
                    'wizard/progress' => $views . '/progress.phtml',
                    'wizard/guide' => $views . '/guide.phtml',
                    'wizard/print' => $views . '/print.phtml',
                    'wizard/account-menu' => $views . '/account-menu.phtml',
                    'wizard/faq' => $views . '/faq.phtml',
                    'wizard/contact' => $views . '/contact.phtml',
                    'error' => $views . '/error.phtml',
                ],
            ],
        ];
    }
}
