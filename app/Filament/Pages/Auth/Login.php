<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;

class Login extends BaseLogin
{
    public function fillAdminDemo(): void
    {
        $this->data['email'] = 'admin@admin.com';
        $this->data['password'] = '11223311';
    }

    public function fillEmployeeDemo(): void
    {
        $this->data['email'] = 'employee1@office.com';
        $this->data['password'] = 'password';
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                RenderHook::make(PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE),
                $this->getFormContentComponent(),
                $this->getMultiFactorChallengeFormContentComponent(),
                RenderHook::make(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER),
                View::make('filament.admin.auth.login-demo'),
            ]);
    }
}
