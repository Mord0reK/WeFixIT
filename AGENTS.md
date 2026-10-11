# WeFixIT — instrukcje implementacji i code review

Instrukcje dotyczą całego repozytorium. Wspólne standardy obowiązują
zarówno agenta implementującego kod, jak i narzędzia wykonujące review.

## Cel i zakres

WeFixIT jest projektem semestralnym: systemem rezerwacji usług serwisu
komputerowego z rolami klienta, pracownika i administratora.

- Pisz prosty, czytelny kod możliwy do wyjaśnienia podczas obrony.
- Realizuj zakres bieżącego polecenia, nie przyszłe funkcje na zapas.
- Przed zmianą przeczytaj odpowiednie pliki, README i istniejące testy.
- Nie zakładaj, że istniejący kod jest poprawny lub kompletny.
- Nie przeprowadzaj niezwiązanych refactorów i masowego formatowania.
- Jeśli polecenie jest sprzeczne z tymi instrukcjami, wyjaśnij konflikt
  i ustal decyzję przed implementacją.

## Stack

- PHP zgodny z PHP 8.2, bez frameworków.
- Apache.
- PDO z driverem MySQL i prepared statements.
- MariaDB 11.4.
- HTML5, Tailwind CSS 4.3.3 standalone CLI, vanilla JavaScript.
- Docker Compose do developmentu; kompatybilność z XAMPP.
- Skompilowany public/assets/css/app.css jest commitowany.

Nie wprowadzaj MySQLi, ORM, Laravel, Symfony, React, Vue, npm, Bun ani
dodatkowych serwisów bez wyraźnej decyzji użytkownika.

PDO ma API obiektowe, ale kod aplikacji organizujemy jako proste moduły
funkcji. Nie twórz własnych klas do opakowywania PDO bez realnej potrzeby.

## Architektura i katalogi

Aplikacja jest jednym modularnym monolitem PHP/Apache.

- PHP renderuje strony, layouty, formularze i początkowy HTML.
- JavaScript wykonuje operacje przez fetch() i wewnętrzne JSON API.
- Formularze operacji wymagają JavaScriptu.
- Nie dodawaj fallbacku formularzy bez JS, osobnych actions ani obsługi
  POST w stronach renderujących formularze.
- API nie jest osobną aplikacją, kontenerem ani deploymentem.
- Authentication wykorzystuje sesję PHP i cookies, nie JWT.

W public/ umieszczaj punkty wejścia HTTP i publiczne assets.
Wspólne funkcje, konfigurację, połączenie DB, kontrolę dostępu oraz
reużywalne widoki umieszczaj poza public/, np. w app/.

- Twórz katalogi razem z rzeczywistym kodem, nie pustą architekturę.
- Współdziel DB, sesje, auth, authorization, CSRF i walidację.
- Nie wymagaj osobnego repository lub service dla każdego zapytania.
- Prosta logika używana tylko w jednym endpointcie może pozostać w nim.
- Wydzielaj logikę, gdy jest współdzielona, złożona lub utrudnia czytanie
  endpointu. Nie duplikuj reguł biznesowych.
- Widoki i komponenty renderujące nie wykonują SQL ani operacji zapisu.
- config.php może pełnić rolę pliku startowego. Nie dodawaj drugiego
  bootstrap.php wykonującego te same czynności.
- Ścieżki do plików buduj względem __DIR__, nie katalogu roboczego.

## Styl kodu

- Nazwy nowych funkcji i zmiennych pisz po angielsku.
- PHP: snake_case dla własnych funkcji i zmiennych.
- JavaScript: camelCase dla funkcji i zmiennych.
- Zachowuj istniejące polskie nazwy tabel i kolumn DB.
- Komunikaty UI, komentarze i dokumentację pisz po polsku.
- Używaj czterech spacji zamiast tabulatorów.
- W PHP klamrę funkcji umieszczaj w następnej linii.
- Preferuj guard clauses zamiast głębokich zagnieżdżeń.
- Deklaruj typy parametrów i wyników własnych funkcji.
- Nie używaj PHPDoc.
- Nie zmieniaj istniejących nazw i formatowania poza zakresem zadania.

Każdy plik PHP zaczynaj od:

```php
<?php
declare(strict_types=1);

/*
 * Krótki opis odpowiedzialności tego pliku.
 */
```

Na początku każdego nowego lub istotnie zmienianego pliku kodu dodaj
krótki blok komentarza opisujący jego odpowiedzialność. Użyj składni
odpowiedniej dla języka; zachowaj wymagane położenie dyrektyw i deklaracji.

Komentarze wewnątrz kodu wyjaśniają powód decyzji lub nieoczywistą regułę.
Nie opisuj każdej instrukcji ani oczywistych operacji.

## HTTP i JSON API

- Publiczne URL-e nie mają rozszerzenia .php.
- Używaj /api/* bez wymaganego /v1.
- Apache mapuje URL-e na istniejące pliki PHP.
- Operacje auth mają oddzielne endpointy, np. /api/auth/login,
  /api/auth/register i /api/auth/logout.
- Nie łącz operacji w jeden endpoint z parametrem action.

Dla endpointów przyjmujących JSON:

- Sprawdź metodę HTTP i Content-Type.
- Niepoprawna metoda: 405 i właściwy nagłówek Allow.
- Nieobsługiwany Content-Type: 415.
- Uszkodzony JSON lub niewłaściwa struktura body: 400.
- Błędy walidacji pól: 422.
- Odrzucone dane logowania lub brak authentication: 401.
- Brak uprawnień albo niepoprawny CSRF: 403.
- Konflikt stanu, np. zajęty termin: 409.
- Nieoczekiwany błąd wewnętrzny: 500 bez szczegółów technicznych.

Odpowiedzi JSON mają Content-Type: application/json; charset=utf-8.
Dla auth i danych użytkownika stosuj Cache-Control: no-store.

Format sukcesu:

```json
{
  "success": true,
  "user_id": 4
}
```

Pola sukcesu zależą od operacji. Nie wymagaj opakowania data ani error: null.

Format błędu:

```json
{
  "error": "Nieprawidłowy e-mail lub hasło."
}
```

Błędy walidacji mogą dodatkowo zawierać mapę pól:

```json
{
  "error": "Nieprawidłowe dane logowania.",
  "errors": {
    "email": "Podaj prawidłowy adres e-mail."
  }
}
```

- Używaj wspólnego helpera odpowiedzi JSON, gdy korzysta z niego wiele
  endpointów; nie kopiuj identycznych funkcji do kolejnych plików.
- Nie dopuszczaj HTML, warningów PHP ani debug outputu w odpowiedzi JSON.
- Nie zwracaj powodzenia po nieudanej operacji.
- Nie pokazuj klientowi wyjątków, SQL, ścieżek ani danych połączenia.

## Walidacja i bezpieczeństwo

- Waliduj dane po stronie serwera; JavaScript poprawia wyłącznie UX.
- Jawnie sprawdzaj typy, formaty i zakresy danych HTTP.
- strict_types nie zastępuje walidacji danych wejściowych.
- Hasła obsługuj przez password_hash() i password_verify().
- Nie wykonuj trim() na hasłach.
- Po udanym logowaniu wykonuj session_regenerate_id(true).
- Sprawdzaj aktywność konta i aktualne uprawnienia po stronie serwera.
- Na każdym chronionym endpointcie i stronie sprawdzaj authentication,
  rolę oraz ownership, jeśli operacja dotyczy konkretnego zasobu.
- Nie ufaj user_id, roli ani uprawnieniom przekazanym przez klienta.
- Stosuj CSRF dla operacji zmieniających stan, również logowania,
  wylogowania i JSON POST/PATCH/DELETE.
- Escapuj dynamiczne dane w kontekście, w którym są wyświetlane.
- Do tekstowych komunikatów w JS używaj textContent, nie innerHTML.
- Nie zapisuj haseł, tokenów ani innych sekretów w logach.
- Sekrety przechowuj poza public/ w ignorowanym .env.
- Utrzymuj .env.example bez rzeczywistych sekretów.

## Baza danych

- Używaj prepared statements dla dynamicznych wartości SQL.
- Dynamiczne nazwy kolumn i kierunki sortowania wybieraj z allowlisty.
- Konfiguruj połączenie PDO z charset=utf8mb4 i obsługą błędów
  przez wyjątki.
- Konfiguracja Docker/XAMPP różni się wartościami .env, nie kodem PHP.
- Ceny przechowuj jako DECIMAL, czas usługi jako liczbę minut.
- Preferuj dezaktywację zamiast usuwania rekordów potrzebnych w historii.
- Cenę i inne niezbędne dane historyczne rezerwacji ustala serwer.

Tworzenie rezerwacji musi atomowo sprawdzać dostępność i zapisywać dane.
Sama transakcja bez odpowiedniej blokady nie eliminuje race condition.

Warunek konfliktu:

```text
existing.start_at < requested.end_at
AND existing.end_at > requested.start_at
```

Nazwy w warunku są schematyczne — używaj rzeczywistych kolumn DB.
Przedziały stykające się granicami nie kolidują.

Bezpośrednio przed zapisem sprawdź ponownie uprawnienia, aktywność usługi
i pracownika, przypisanie usługi, godziny pracy, nieobecności oraz konflikty.

## Frontend i uruchamianie

- Używaj statycznych, pełnych nazw klas Tailwind.
- Nie składaj nazw klas z fragmentów stringów.
- Nie edytuj ręcznie wygenerowanego app.css; przebuduj go ze źródeł.
- Przy zmianie źródeł stylowania dołącz aktualny wynik builda.
- Linki, fetch() i redirecty muszą działać również w podkatalogu XAMPP.
- Nie zakładaj, że aplikacja zawsze działa w root domeny.
- Kod, .env i pliki SQL poza public/ nie mogą być dostępne przez HTTP.
- Zmiana database.sql nie aktualizuje istniejącego volume MariaDB.

## Granice działania agenta

Agent może edytować kod i uruchamiać niedestrukcyjne testy w zakresie
polecenia użytkownika.

Bez wyraźnego polecenia nie wykonuj:
- commitów, pushów, merge'ów ani deployu,
- resetowania DB ani usuwania volumes,
- destrukcyjnych poleceń Git,
- dodawania zależności,
- zmian zadań w narzędziu task management.

Nie nadpisuj niezwiązanych zmian użytkownika.
Po pracy krótko podsumuj zmiany, wykonane testy i pozostałe ograniczenia.

## Zasady code review

Review służy wykrywaniu rzeczywistych błędów, nie przebudowie projektu.

Priorytet:
- błędy runtime i logiki,
- regresje,
- authentication, authorization i ownership,
- SQL injection, XSS, CSRF i ujawnianie danych,
- błędna walidacja,
- race conditions i niespójność danych,
- naruszenie jawnych wymagań projektu.

Każde zgłoszenie powinno wskazywać konkretną ścieżkę wystąpienia problemu,
jego praktyczny skutek i możliwą poprawkę.

Nie zgłaszaj kosmetyki, alternatywnego formatowania, arbitralnych zmian
nazw, abstrakcji na zapas ani hipotetycznych problemów bez uzasadnienia.

Przy lokalnej, jednoznacznej i bezpiecznej poprawce podaj committable
code suggestion nadające się do GitHub Apply suggestion.

Nie proponuj dużej przebudowy, jeśli wystarcza mała poprawka.
Reguły stylu stosuj podczas pisania kodu; nie używaj ich jako powodu
do kosmetycznych komentarzy review.
