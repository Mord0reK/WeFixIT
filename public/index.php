<?php
declare(strict_types=1);

/*
    Punkt wejścia dla katalogu głównego. Przekierowuje na formularz logowania.
    Zalogowanego użytkownika login.php odeśle dalej na stronę właściwą dla jego roli.
*/
require_once dirname(__DIR__) . '/app/auth.php';

header('Location: ' . auth_base_path() . '/login');
exit;
