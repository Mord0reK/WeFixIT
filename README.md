# WeFixIT

System rezerwacji dla serwisu komputerowego. Obsługuje klientów, pracowników i administratorów.

## Baza danych
Wykorzystywana jest baza danych MariaDB. Pusta wersja znajduje się w pliku [database-czysta.sql](database-czysta.sql), a wersja z danymi testowymi w [database-z-danymi-testowymi.sql](database-z-danymi-testowymi.sql).

Poniżej znajduje się diagram wykorzystywanej bazy danych.

![Diagram schematu bazy danych WeFixIT](database-diagram-WeFixIT.svg)

### Wersja pusta
Pusta edycja bazy danych jak sama nazwa wskazuje nie zawiera żadnych danych w sobie. Zalecane użycie w środowisku produkcyjnym.

### Wersja testowa
Testowa edycja bazy danych zawiera wszystko co wersja pusta plus dane testowe. Zalecana ona jest do użycia w celach testowych.

<!-- haslo: haslo123 bo znjac zycie zapomne -->

Zawiera:
- Jednego administratora
- Dwóch pracowników
- Trzech klientów

Hasło do każdego z użytkowników to: **haslo123**

## Zarządzanie
System oferuje rozbudowany panel administracyjny który pozwala dostosować działanie i zachowanie aplikacji.

Takie jak:
- [placeholder]
- rownież [placeholder]
- no i oczywiscie [placeholder]
<!-- jakies glowne wyrozniki tutaj przydaloby sie dac -->

## Wymagania

Do uruchomienia aplikacji należy wybrać jedną z poniższych metod:

- Docker z obsługą Compose,
- XAMPP z Apache, PHP i MySQL.

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
   docker compose -f docker-compose.dev.yml up --build
   ```

4. Otwórz aplikację w przeglądarce: [http://localhost:8080](http://localhost:8080).

Aby zatrzymać aplikację, użyj `Ctrl+C` albo uruchom:

```bash
docker compose -f docker-compose.dev.yml down
```

Baza danych jest przechowywana w wolumenie Dockera. Aby rozpocząć od nowej bazy i ponownie załadować dane testowe, usuń także wolumen:

```bash
docker compose -f docker-compose.dev.yml down -v
docker compose -f docker-compose.dev.yml up --build
```

## Uruchomienie przez XAMPP

Docelowo uruchomienie przez XAMPP będzie wyglądało następująco:

1. Pobierz ZIP aplikacji z sekcji **Releases** na GitHubie.
2. Rozpakuj go w katalogu `htdocs`.
3. Uruchom Apache i MySQL w panelu XAMPP.
4. Otwórz aplikację pod adresem `http://localhost/WeFixIT`.

ZIP z release będzie zawierał gotową wersję aplikacji i nie będzie zawierał plików narzędzi deweloperskich, takich jak konfiguracja CodeRabbit.

### Obecny sposób uruchomienia

Aktualna wersja repozytorium nie ma jeszcze automatycznej konfiguracji połączenia z bazą dla XAMPP. Przed użyciem tej metody należy jednorazowo:

1. Zainstalować XAMPP i uruchomić moduły **Apache** oraz **MySQL**.
2. Rozpakować projekt do `C:\xampp\htdocs\WeFixIT` (Windows) albo odpowiednika katalogu `htdocs` na danym systemie.
3. Otworzyć [phpMyAdmin](http://localhost/phpmyadmin), utworzyć bazę `wefixit` i zaimportować plik [database-z-danymi-testowymi.sql](database-z-danymi-testowymi.sql). Na potrzeby produkcyjne można użyć [database-czysta.sql](database-czysta.sql).
4. W pliku `index.php` zmienić host bazy danych z `mariadb` na `localhost`:

   ```php
   $db = mysqli_connect('localhost', 'root', '', 'wefixit');
   ```

   Jeśli konto MySQL `root` ma ustawione hasło, należy wpisać je zamiast pustej wartości.

5. Otworzyć aplikację pod adresem [http://localhost/WeFixIT](http://localhost/WeFixIT).

Instrukcja z jednym gotowym ZIP-em z sekcji **Releases** będzie obowiązywać po przygotowaniu release oraz dostosowaniu konfiguracji bazy do środowiska XAMPP. Automatyczne tworzenie release przez GitHub Actions nie jest obecnie skonfigurowane.

## Autorzy

> - [JanDziaslo](https://github.com/JanDziaslo) (Bartosz N.)
>
> - [Mord0reK](https://github.com/Mord0reK) (Marcel S.)
