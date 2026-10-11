<?php
declare(strict_types=1);

/*
    Wspólny helper odpowiedzi JSON dla endpointów API.
    Ustawia nagłówki i kończy skrypt, więc każde wywołanie kończy żądanie.
*/
function json_response(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}