<?php
declare(strict_types=1);

/*
    Wspólny helper odpowiedzi JSON dla endpointów API.

    Ustawia nagłówki i kończy skrypt, więc każde wywołanie kończy żądanie.
    Dodatkowo wymusza dozwolone metody HTTP, sprawdza Content-Type żądań
    z ciałem JSON oraz dekoduje body bez duplikowania tych mechanizmów
    w poszczególnych endpointach.
*/
function json_response(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

// Poza listą metod odpowiadamy 405 i nagłówkiem Allow zgodnym z obsługą endpointu.
function json_require_method(array $methods): void
{
    $current = $_SERVER['REQUEST_METHOD'] ?? '';

    if (in_array($current, $methods, true)) {
        return;
    }

    header('Allow: ' . implode(', ', $methods));
    json_response(405, ['error' => 'Nieprawidłowe żądanie.']);
}

// Endpointy przyjmujące JSON akceptują wyłącznie application/json (także z parametrem).
// DELETE celowo nie przechodzi tej kontroli: operacja nie przyjmuje reprezentacji zasobu.
function json_require_content_type(): void
{
    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0]));

    if ($contentType !== 'application/json') {
        json_response(415, ['error' => 'Oczekiwano zawartości w formacie JSON.']);
    }
}

// Zwraca body jako tablicę pól. Uszkodzony JSON lub inna struktura daje 400.
function json_read_body(): array
{
    try {
        $data = json_decode(file_get_contents('php://input'), false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        json_response(400, ['error' => 'Nieprawidłowe żądanie.']);
    }

    if (!$data instanceof stdClass) {
        json_response(400, ['error' => 'Nieprawidłowe żądanie.']);
    }

    return (array) $data;
}
