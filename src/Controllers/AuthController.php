<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

final class AuthController
{
    public function showLogin(): void
    {
        View::render('auth/login', ['title' => 'Sign in'], 'layouts/auth');
    }

    public function login(): void
    {
        $email = (string) Request::post('email', '');
        $password = (string) Request::post('password', '');

        $error = Auth::attempt($email, $password);

        if ($error !== null) {
            Flash::setOld(['email' => $email]);
            Flash::error($error);
            Response::redirect('/login');
        }

        $intended = Session::pull('__intended_url');
        Response::redirect($intended ?: (Auth::isStaff() ? '/staff' : '/'));
    }

    public function logout(): void
    {
        Auth::logout();
        Response::redirect('/login');
    }
}
