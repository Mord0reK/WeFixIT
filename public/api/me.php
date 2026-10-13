<?php
declare(strict_types=1);

/*
    Endpoint GET /api/me.

    Zwraca podstawowe dane zalogowanego użytkownika. Odczyt nie zmienia stanu,
    więc nie wymaga tokenu CSRF. Nigdy nie ujawnia hasła ani flagi aktywności.
*/
require_once __DIR__ . '/../../app/json.php';
require_once __DIR__ . '/../../app/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    json_response(405, ['error' => 'Nieprawidłowe żądanie.']);
}

$user = require_api_login();

json_response(200, [
    'success' => true,
    'user' => [
        'id' => (int) $user['id'],
        'imie' => (string) $user['imie'],
        'nazwisko' => (string) $user['nazwisko'],
        'email' => (string) $user['email'],
        'telefon' => (string) $user['telefon'],
        'rola' => (string) $user['rola'],
    ],
]);
