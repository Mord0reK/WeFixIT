# WeFixIT — instrukcje dla agenta implementującego kod

## Zakazy bezwzględne

Bez wyraźnego polecenia użytkownika nie wolno:

1. commitować, pushować, mergować ani deployować;
2. resetować bazy, usuwać volume'ów ani uruchamiać `docker compose down -v`;
3. wykonywać destrukcyjnych poleceń Git (`reset --hard`, `clean -fdx`, `push --force`);
4. dodawać zależności, frameworków ani narzędzi (npm, Bun, ORM, MySQLi, JWT);
5. budować `public/assets/css/app.css` samodzielnie ani edytować go ręcznie;
6. implementować funkcji spoza zakresu polecenia;
7. refaktoryzować kodu ani formatować plików poza zakresem zadania;
8. zmieniać zadań w zewnętrznym narzędziu do zarządzania zadaniami.

Nie nadpisuj niezwiązanych zmian użytkownika. Jeśli polecenie jest sprzeczne
z tymi zasadami, powiedz o konflikcie i ustal decyzję przed kodowaniem.

## Cel i zakres

Projekt semestralny: system rezerwacji usług serwisu komputerowego z rolami
klienta, pracownika i administratora.

- Pisz prosty, czytelny kod możliwy do wyjaśnienia podczas obrony.
- Realizuj zakres bieżącego polecenia, nie przyszłe funkcje na zapas.
- Przed zmianą przeczytaj odpowiednie pliki, README i istniejące testy.
- Nie zakładaj, że istniejący kod jest poprawny lub kompletny.

## Stack

- PHP zgodny z 8.2, bez frameworków.
- Apache.
- PDO z driverem MySQL i prepared statements.
- MariaDB 11.4.
- HTML5, Tailwind CSS 4.3.3 standalone CLI, vanilla JavaScript.
- Docker Compose v2 do developmentu; zgodność z XAMPP, także w podkatalogu.
- Skompilowany `public/assets/css/app.css` jest commitowany.

PDO ma API obiektowe, ale kod organizuj jako proste moduły funkcji.
Nie opakowuj PDO we własne klasy bez realnej potrzeby.

## Architektura i katalogi

Jedna aplikacja monolityczna PHP/Apache.

- PHP renderuje strony, layouty, formularze i początkowy HTML.
- JavaScript wykonuje operacje przez `fetch()` i wewnętrzne JSON API.
- Formularze operacji wymagają JavaScriptu. Nie dodawaj fallbacku bez JS,
  osobnych actions ani obsługi POST w stronach renderujących formularze.
- API nie jest osobną aplikacją, kontenerem ani deploymentem.
- Authentication opiera się o sesję PHP i cookies, nigdy o JWT.

- `public/` — punkty wejścia HTTP i publiczne assety.
- `app/` — wspólne moduły poza docrootem: DB, sesja, auth, CSRF, walidacja,
  layout.
- `database/`, `tests/`, `docker/`, `.github/workflows/`.

- Twórz katalogi razem z rzeczywistym kodem, nie pustą architekturę.
- Nie wymagaj osobnego repository lub service dla każdego zapytania.
- Prosta logika używana tylko w jednym endpointcie może pozostać w nim.
- Wydzielaj logikę, gdy jest współdzielona, złożona lub utrudnia czytanie
  endpointu. Nie duplikuj reguł biznesowych.
- Widoki i komponenty renderujące nie wykonują SQL ani operacji zapisu.
- `config.php` pełni rolę pliku startowego. Nie dodawaj drugiego
  `bootstrap.php` robiącego to samo.
- Ścieżki do plików buduj względem `__DIR__`, nie katalogu roboczego.
- Kod, `.env` i pliki SQL poza `public/` nie mogą być dostępne przez HTTP.

## Styl kodu

- Nazwy nowych funkcji i zmiennych po angielsku: PHP `snake_case`,
  JavaScript `camelCase`.
- Zachowuj istniejące polskie nazwy tabel i kolumn DB.
- Komunikaty UI, komentarze i dokumentację pisz po polsku.
- Cztery spacje, nie tabulatory.
- Klamra funkcji w PHP w następnej linii.
- Guard clauses zamiast głębokich zagnieżdżeń.
- Deklaruj typy parametrów i wyników własnych funkcji.
- Nie używaj PHPDoc.
- Komentarze wyjaśniają powód decyzji lub nieoczywistą regułę, nie każdą
  instrukcję.

Każdy plik PHP zaczynaj od:

```php
<?php
declare(strict_types=1);

/*
 * Krótki opis odpowiedzialności tego pliku.
 */
```

## HTTP i JSON API

- Publiczne URL-e bez rozszerzenia `.php`; Apache mapuje je na pliki PHP.
- Endpointy pod `/api/*`, bez `/v1/`.
- Operacje auth mają osobne endpointy (`/api/auth/login`, `/api/auth/register`,
  `/api/auth/logout`). Nie łącz ich w jeden endpoint z parametrem `action`.
- Linki, `fetch()` i redirecty muszą działać również w podkatalogu. Nigdy nie
  zakładaj root domeny.

Kody odpowiedzi:

- 200 — sukces; 201 — utworzenie zasobu.
- 405 z nagłówkiem `Allow` — zła metoda.
- 415 — nieobsługiwany `Content-Type`.
- 400 — uszkodzony JSON lub zła struktura body.
- 422 — błędy walidacji pól.
- 401 — brak authentication lub odrzucone dane logowania.
- 403 — brak uprawnień lub niepoprawny CSRF.
- 409 — konflikt stanu, np. zajęty termin.
- 500 — bez szczegółów technicznych.

Kontrakt:

- `Content-Type: application/json; charset=utf-8`.
- `Cache-Control: no-store` dla auth i danych użytkownika.
- Sukces: `{"success": true, ...}` — pola zależne od operacji, bez opakowania
  `data` i bez `error: null`.
- Błąd: `{"error": "..."}`, opcjonalnie mapa `errors` dla walidacji pól.
- Używaj wspólnego helpera odpowiedzi JSON zamiast kopiowania funkcji.
- Nie dopuszczaj HTML, warningów PHP ani debug outputu w odpowiedzi JSON.
- Nie pokazuj klientowi wyjątków, SQL, ścieżek ani danych połączenia.

## Walidacja i bezpieczeństwo

- Waliduj dane po stronie serwera; JavaScript poprawia wyłącznie UX.
- Jawnie sprawdzaj typy, formaty i zakresy danych HTTP.
- `strict_types` nie zastępuje walidacji danych wejściowych.
- Hasła przez `password_hash()` i `password_verify()`, bez `trim()` na hasłach.
- Po skutecznym logowaniu `session_regenerate_id(true)` oraz rotacja tokenu
  CSRF.
- Sprawdzaj aktywność konta i aktualne uprawnienia po stronie serwera.
- Na każdym chronionym endpointcie i stronie sprawdzaj authentication, rolę
  oraz ownership, jeśli operacja dotyczy konkretnego zasobu.
- `user_id`, rola i uprawnienia nigdy nie pochodzą z żądania klienta.
- CSRF obowiązuje każdą operację zmieniającą stan, także logowanie,
  wylogowanie i JSON `POST`/`PATCH`/`DELETE`. Token w nagłówku
  `X-CSRF-Token`.
- Escapuj dynamiczne dane w kontekście wyświetlania; w JS tekst przez
  `textContent`, nie `innerHTML`.
- Nie loguj haseł, tokenów ani sekretów.
- Sekrety wyłącznie w ignorowanym `.env`; `.env.example` bez realnych wartości.

## Baza danych

- Prepared statements dla wszystkich dynamicznych wartości SQL.
- Dynamiczne nazwy kolumn i kierunki sortowania wybieraj z allowlisty.
- PDO z `charset=utf8mb4` i obsługą błędów przez wyjątki.
- Konfiguracja Docker/XAMPP różni się wartościami `.env`, nie kodem PHP.
- Ceny jako `DECIMAL`, czas usługi w minutach.
- Preferuj dezaktywację rekordów zamiast usuwania historii.
- Cenę i dane historyczne rezerwacji ustala serwer.
- Zmiana `database.sql` nie aktualizuje istniejącego volume MariaDB.

Tworzenie rezerwacji musi atomowo sprawdzać dostępność i zapisywać dane.
Sama transakcja bez właściwej blokady nie eliminuje race condition.

Warunek konfliktu:

```text
existing.start_at < requested.end_at
AND existing.end_at > requested.start_at
```

Rzeczywiste kolumny w tabeli `rezerwacje` to `czas_rozpoczecia`
i `czas_zakonczenia`. Przedziały stykające się granicami nie kolidują.
Bezpośrednio przed zapisem sprawdź ponownie uprawnienia, aktywność usługi
i pracownika, przypisanie usługi, godziny pracy, nieobecności oraz konflikty.

## Frontend / Tailwind

- Klasy Tailwind zapisuj jako pełne, statyczne stringi w HTML/PHP.
  CLI nie widzi nazw składanych z fragmentów.
- `public/assets/css/app.css` przebudowuje się automatycznie po zmianie źródeł
  stylowania. Nie buduj go samodzielnie i nie edytuj ręcznie.
- Po zmianie klas lub `input.css` odczekaj na przebudowę i sprawdź:

      git status --short public/assets/css/app.css

- Plik się zmienił → dołącz go do commita.
- Plik się nie zmienił → poinformuj użytkownika, że `app.css` nie został
  przebudowany, podaj pliki z nowymi klasami i przerwij. Nie commituj wtedy
  i nie próbuj obejść problemu.

## Testy

- Testy HTTP są w `tests/` i uruchamia się je przez `php tests/run.php`.
- Nowe testy dokładaj do istniejącego mechanizmu, w tym samym stylu. Nie
  dodawaj frameworka testowego bez uzasadnienia.
- Testy nie mogą działać na bazie deweloperskiej — wyłącznie izolowana baza
  wskazana przez `DB_NAME`, domyślnie `wefixit_test`.
- Testy startują wbudowany `php -S`, który nie czyta `.htaccess`, dlatego
  uderzają w ścieżki z rozszerzeniem `.php`.

### Kiedy uruchamiać testy

Uruchamiaj testy przy zmianach logiki, endpointów, zapytań SQL, walidacji,
auth lub uprawnień.

Nie uruchamiaj testów, gdy zmiana jest prosta i ograniczona do: tekstu
interfejsu, klasy Tailwind, komentarza, formatowania, literówki, nazwy
zmiennej lokalnej albo drobnej poprawki stylu.

Przy zmianie wątpliwej — dotyczącej jednej gałęzi warunku, jednego pola
formularza lub pojedynczej reguły walidacji — uruchom tylko powiązany test
albo najbliższą grupę, nie cały zestaw.

Zmiana zachowania endpointu bez aktualizacji testów jest niekompletna.
Jeśli testów nie uruchomiłeś, napisz to wprost w podsumowaniu, razem
z uzasadnieniem.

## Git

- Prefiksy commitów: `feat:`, `fix:`, `docs:`, `test:`, `ci:`.
- Opis commitu mówi, co się zmieniło i dlaczego.
- Praca na feature branchu, zmiany przez pull request do `main`.
- Każdy PR musi przejść CI i code review przed mergem.

## Po zakończeniu pracy

Krótko podsumuj: co zmieniłeś, jakie testy wykonałeś, jakie ograniczenia
zostały.