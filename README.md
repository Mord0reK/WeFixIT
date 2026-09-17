# WeFixIT

System rezerwacji dla serwisu komputerowego. Obsługuje klientów, pracowników i administratorów.

## Spis treści

- [Technologie](#Technologie)
- [Baza danych](#Baza-danych)
- [Zakres projektu](#zakres-projektu)
- [Wymagania](#wymagania)
- [Uruchomienie przez Docker](#uruchomienie-przez-docker)
- [Uruchomienie przez XAMPP](#uruchomienie-przez-xampp)
- [Autorzy](#Autorzy)

## Technologie
Projekt wykorzystuje następujące technologie:
- PHP 8.2 + Apache, proceduralne MySQLi
- MariaDB 11.4
- HTML5, Tailwind CSS 4.3.3 standalone CLI, vanilla JS
- Docker Compose v2, kompatybilność XAMPP

## Baza danych
Wykorzystywana jest baza danych MariaDB. Pusta wersja znajduje się w pliku [database-czysta.sql](database/database-czysta.sql), a wersja z danymi testowymi w [database.sql](database/database.sql).

Poniżej znajduje się diagram wykorzystywanej bazy danych.

![Diagram schematu bazy danych WeFixIT](database/database-diagram-WeFixIT.png)

### Wersja pusta
Pusta edycja bazy danych jak sama nazwa wskazuje nie zawiera żadnych danych w sobie. Zalecane użycie w środowisku produkcyjnym.

### Wersja testowa
Testowa edycja bazy danych zawiera wszystko co wersja pusta plus dane testowe. Zalecana ona jest do użycia w celach testowych.

#### Konta testowe

| Rola | Imię i nazwisko | E-mail | Hasło |
|------|-----------------|--------|-------|
| Administrator | Administrator Systemu | `admin@wefixit.pl` | `haslo123` |
| Pracownik | Tomasz Serwisant | `tomasz.serwisant@wefixit.pl` | `haslo123` |
| Pracownik | Marcin Elektronik | `marcin.elektronik@wefixit.pl` | `haslo123` |
| Klient | Jan Kowalski | `jan.kowalski@example.com` | `haslo123` |
| Klient | Anna Nowak | `anna.nowak@example.com` | `haslo123` |
| Klient | Piotr Wiśniewski | `piotr.wisniewski@example.com` | `haslo123` |

## Zakres projektu

WeFixIT to system rezerwacji dla serwisu komputerowego z trzema rolami: klient, pracownik, administrator.

### Zrealizowano w Etapie 1

- Schemat bazy MariaDB 11.4: 7 tabel (`uzytkownicy`, `pracownicy`, `kategorie_uslug`, `uslugi`, `uslugi_pracownikow`, `godziny_pracy`, `rezerwacje`).
- Relacje 1:N oraz relacja N:M pracownik–usługa przez `uslugi_pracownikow`.
- Integralność: klucze obce, `UNIQUE` na e-mail, `CHECK` na ceny / czasy / statusy, `ON DELETE RESTRICT`.
- Diagram ERD: `database-diagram-WeFixIT.png`.
- Dane testowe: 1 administrator, 2 pracowników, 3 klientów, usługi, grafik i rezerwacje.
- Środowisko uruchomieniowe: Docker Compose dev + prod oraz instrukcja pod XAMPP.

### Planowane w Etapie 2 - Użytkownicy i logowanie

- Rejestracja konta klienta z walidacją po stronie serwera.
- Logowanie i wylogowanie z obsługą sesji.
- Podział na role: klient, pracownik, administrator.
- Ochrona stron i endpointów przed dostępem bez roli / sesji.
- Profil użytkownika: podgląd i edycja podstawowych danych.

## Wymagania

Do uruchomienia aplikacji należy wybrać jedną z poniższych metod:

- Docker z obsługą Compose,
- XAMPP z Apache, PHP i MySQL.

### Konfiguracja środowiska

Plik `.env` jest jedynym źródłem konfiguracji aplikacji, w szczególności
połączenia z bazą danych. Należy utworzyć go na podstawie `.env.example` i
dostosować do wybranego środowiska. Plik `.env` znajduje się w katalogu
głównym projektu, poza katalogiem `public/`, i jest ignorowany przez Git — nie
trafia do repozytorium.

## Uruchomienie przez Docker

Docker uruchamia aplikację razem z serwerem MariaDB oraz automatycznie ładuje testową bazę danych.

1. Sklonuj repozytorium i przejdź do jego katalogu:

   ```bash
   git clone https://github.com/Mord0reK/WeFixIT.git
   cd WeFixIT
   ```

2. Utwórz plik `.env` na podstawie `.env.example` i w razie potrzeby zmień hasła:

   ```bash
   cp .env.example .env
   ```

3. Zbuduj obrazy i uruchom kontenery:

   ```bash
   docker compose --env-file .env -f docker/docker-compose.dev.yml up --build
   ```

   Polecenie wykonuj z katalogu głównego projektu, ponieważ właśnie stamtąd
   Compose wczytuje plik `.env`. 

4. Otwórz aplikację w przeglądarce: [http://localhost:8080](http://localhost:8080).

Aby zatrzymać aplikację, użyj `Ctrl+C` albo uruchom:

```bash
docker compose --env-file .env -f docker/docker-compose.dev.yml down
```

Baza danych jest przechowywana w wolumenie Dockera. Aby rozpocząć od nowej bazy i ponownie załadować dane testowe, usuń także wolumen:

```bash
docker compose --env-file .env -f docker/docker-compose.dev.yml down -v
docker compose --env-file .env -f docker/docker-compose.dev.yml up --build
```

## Uruchomienie przez XAMPP

Uruchomienie przez XAMPP:

1. Zainstalować XAMPP i uruchomić moduły **Apache** oraz **MySQL**.
2. Rozpakować projekt do `C:\xampp\htdocs\WeFixIT` (Windows) albo odpowiednika katalogu `htdocs` na danym systemie.
3. Otworzyć [phpMyAdmin](http://localhost/phpmyadmin), utworzyć bazę `wefixit` i zaimportować plik [database.sql](database/database.sql). Na potrzeby produkcyjne można użyć [database-czysta.sql](database/database-czysta.sql).
4. Utworzyć plik `.env` w katalogu głównym projektu na podstawie `.env.example` i ustawić dane XAMPP. Dla standardowej instalacji użyj:

   ```dotenv
   DB_HOST=localhost
   DB_PORT=3306
   DB_NAME=wefixit
   DB_USER=root
   DB_PASSWORD=
   ```

   Jeśli konto MySQL `root` ma ustawione hasło, wpisz je w `DB_PASSWORD`. Nie należy edytować plików PHP — konfigurację zmienia się wyłącznie w `.env`.

5. Otworzyć aplikację pod adresem [http://localhost/WeFixIT/public/](http://localhost/WeFixIT/public/). Alternatywnie można skonfigurować VirtualHost z `DocumentRoot` wskazującym na katalog `public/` i używać adresu ustawionego dla tego VirtualHosta.

W środowisku Docker wartość `DB_HOST` musi wynosić `mariadb`; w środowisku XAMPP — `localhost`.

## Autorzy

> - [JanDziaslo](https://github.com/JanDziaslo) (Bartosz N.)
>
> - [Mord0reK](https://github.com/Mord0reK) (Marcel S.)
