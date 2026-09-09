CREATE DATABASE IF NOT EXISTS wefixit
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE wefixit;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS rezerwacje;
DROP TABLE IF EXISTS godziny_pracy;
DROP TABLE IF EXISTS uslugi_pracownikow;
DROP TABLE IF EXISTS pracownicy;
DROP TABLE IF EXISTS uslugi;
DROP TABLE IF EXISTS kategorie_uslug;
DROP TABLE IF EXISTS uskugi_kategorie;
DROP TABLE IF EXISTS uzytkownicy;
DROP TABLE IF EXISTS uzytkownik;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE uzytkownicy (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    imie VARCHAR(100) NOT NULL,
    nazwisko VARCHAR(100) NOT NULL,
    email VARCHAR(254) NOT NULL,
    telefon VARCHAR(20) NOT NULL,
    haslo_hash VARCHAR(255) NOT NULL,
    rola ENUM(
        'klient',
        'pracownik',
        'admin'
    ) NOT NULL DEFAULT 'klient',
    aktywny BOOLEAN NOT NULL DEFAULT TRUE,
    utworzono DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    zaktualizowano DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    CONSTRAINT uq_uzytkownicy_email
        UNIQUE (email),

    CONSTRAINT chk_uzytkownicy_email_niepusty
        CHECK (email <> ''),

    CONSTRAINT chk_uzytkownicy_telefon_niepusty
        CHECK (telefon <> '')
) ENGINE = InnoDB;

CREATE TABLE kategorie_uslug (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nazwa VARCHAR(100) NOT NULL,
    opis TEXT NULL,
    aktywna BOOLEAN NOT NULL DEFAULT TRUE,
    utworzono DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    zaktualizowano DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    CONSTRAINT uq_kategorie_uslug_nazwa
        UNIQUE (nazwa),

    CONSTRAINT chk_kategorie_uslug_nazwa_niepusta
        CHECK (nazwa <> '')
) ENGINE = InnoDB;

CREATE TABLE uslugi (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kategoria_id INT UNSIGNED NOT NULL,
    nazwa VARCHAR(150) NOT NULL,
    opis TEXT NULL,
    czas_trwania_minuty SMALLINT UNSIGNED NOT NULL,
    cena DECIMAL(10,2) NOT NULL,
    aktywna BOOLEAN NOT NULL DEFAULT TRUE,
    utworzono DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    zaktualizowano DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    CONSTRAINT uq_uslugi_kategoria_nazwa
        UNIQUE (kategoria_id, nazwa),

    CONSTRAINT chk_uslugi_nazwa_niepusta
        CHECK (nazwa <> ''),

    CONSTRAINT chk_uslugi_czas_trwania
        CHECK (czas_trwania_minuty > 0),

    CONSTRAINT chk_uslugi_cena
        CHECK (cena >= 0),

    CONSTRAINT fk_uslugi_kategoria
        FOREIGN KEY (kategoria_id)
        REFERENCES kategorie_uslug (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    INDEX idx_uslugi_aktywna_kategoria (
        aktywna,
        kategoria_id
    )
) ENGINE = InnoDB;

CREATE TABLE pracownicy (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uzytkownik_id INT UNSIGNED NOT NULL,
    opis TEXT NULL,
    aktywny BOOLEAN NOT NULL DEFAULT TRUE,
    utworzono DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    zaktualizowano DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    CONSTRAINT uq_pracownicy_uzytkownik
        UNIQUE (uzytkownik_id),

    CONSTRAINT fk_pracownicy_uzytkownik
        FOREIGN KEY (uzytkownik_id)
        REFERENCES uzytkownicy (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    INDEX idx_pracownicy_aktywny (
        aktywny
    )
) ENGINE = InnoDB;

CREATE TABLE uslugi_pracownikow (
    pracownik_id INT UNSIGNED NOT NULL,
    usluga_id INT UNSIGNED NOT NULL,
    aktywne BOOLEAN NOT NULL DEFAULT TRUE,
    przypisano DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    zaktualizowano DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (
        pracownik_id,
        usluga_id
    ),

    CONSTRAINT fk_uslugi_pracownikow_pracownik
        FOREIGN KEY (pracownik_id)
        REFERENCES pracownicy (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_uslugi_pracownikow_usluga
        FOREIGN KEY (usluga_id)
        REFERENCES uslugi (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    INDEX idx_uslugi_pracownikow_usluga (
        usluga_id,
        aktywne,
        pracownik_id
    )
) ENGINE = InnoDB;

CREATE TABLE godziny_pracy (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pracownik_id INT UNSIGNED NOT NULL,
    dzien_tygodnia TINYINT UNSIGNED NOT NULL,
    czas_rozpoczecia TIME NOT NULL,
    czas_zakonczenia TIME NOT NULL,
    aktywne BOOLEAN NOT NULL DEFAULT TRUE,
    utworzono DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    zaktualizowano DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    CONSTRAINT chk_godziny_pracy_dzien
        CHECK (dzien_tygodnia BETWEEN 1 AND 7),

    CONSTRAINT chk_godziny_pracy_przedzial
        CHECK (czas_rozpoczecia < czas_zakonczenia),

    CONSTRAINT uq_godziny_pracy_przedzial
        UNIQUE (
            pracownik_id,
            dzien_tygodnia,
            czas_rozpoczecia,
            czas_zakonczenia
        ),

    CONSTRAINT fk_godziny_pracy_pracownik
        FOREIGN KEY (pracownik_id)
        REFERENCES pracownicy (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    INDEX idx_godziny_pracy_wyszukiwanie (
        pracownik_id,
        dzien_tygodnia,
        aktywne
    )
) ENGINE = InnoDB;

CREATE TABLE rezerwacje (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uzytkownik_id INT UNSIGNED NOT NULL,
    pracownik_id INT UNSIGNED NOT NULL,
    usluga_id INT UNSIGNED NOT NULL,
    czas_rozpoczecia DATETIME NOT NULL,
    czas_zakonczenia DATETIME NOT NULL,
    cena_historyczna DECIMAL(10,2) NOT NULL,
    status_rezerwacji ENUM(
        'oczekujaca',
        'potwierdzona',
        'w_trakcie',
        'zrealizowana',
        'anulowana'
    ) NOT NULL DEFAULT 'oczekujaca',
    komentarz_klienta VARCHAR(1000) NULL,
    anulowano DATETIME NULL,
    CONSTRAINT chk_rezerwacje_anulowanie
        CHECK (
            (status_rezerwacji = 'anulowana' AND anulowano IS NOT NULL)
            OR
            (status_rezerwacji <> 'anulowana' AND anulowano IS NULL)
        ),
    utworzono DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    zaktualizowano DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    CONSTRAINT chk_rezerwacje_przedzial
        CHECK (czas_rozpoczecia < czas_zakonczenia),

    CONSTRAINT chk_rezerwacje_cena
        CHECK (cena_historyczna >= 0),

    CONSTRAINT fk_rezerwacje_uzytkownik
        FOREIGN KEY (uzytkownik_id)
        REFERENCES uzytkownicy (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_rezerwacje_pracownik_usluga
        FOREIGN KEY (
            pracownik_id,
            usluga_id
        )
        REFERENCES uslugi_pracownikow (
            pracownik_id,
            usluga_id
        )
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    INDEX idx_rezerwacje_konflikt (
        pracownik_id,
        status_rezerwacji,
        czas_rozpoczecia,
        czas_zakonczenia
    ),

    INDEX idx_rezerwacje_uzytkownik_data (
        uzytkownik_id,
        czas_rozpoczecia
    ),

    INDEX idx_rezerwacje_status_data (
        status_rezerwacji,
        czas_rozpoczecia
    ),

    INDEX idx_rezerwacje_usluga (
        usluga_id
    )
) ENGINE = InnoDB;

INSERT INTO uzytkownicy (id, imie, nazwisko, email, telefon, haslo_hash, rola, aktywny) VALUES
(1, 'Administrator', 'Systemu', 'admin@wefixit.pl', '+48123456780', '$2y$12$5WalVqOzwEO80XzP7g65ReJzpBkI4o.kBX2ZJHfXOjmv0ZDPuVR8a', 'admin', TRUE),
(2, 'Tomasz', 'Serwisant', 'tomasz.serwisant@wefixit.pl', '+48123456781', '$2y$12$5WalVqOzwEO80XzP7g65ReJzpBkI4o.kBX2ZJHfXOjmv0ZDPuVR8a', 'pracownik', TRUE),
(3, 'Marcin', 'Elektronik', 'marcin.elektronik@wefixit.pl', '+48123456782', '$2y$12$5WalVqOzwEO80XzP7g65ReJzpBkI4o.kBX2ZJHfXOjmv0ZDPuVR8a', 'pracownik', TRUE),
(4, 'Jan', 'Kowalski', 'jan.kowalski@example.com', '+48987654321', '$2y$12$5WalVqOzwEO80XzP7g65ReJzpBkI4o.kBX2ZJHfXOjmv0ZDPuVR8a', 'klient', TRUE),
(5, 'Anna', 'Nowak', 'anna.nowak@example.com', '+48987654322', '$2y$12$5WalVqOzwEO80XzP7g65ReJzpBkI4o.kBX2ZJHfXOjmv0ZDPuVR8a', 'klient', TRUE),
(6, 'Piotr', 'Wisniewski', 'piotr.wisniewski@example.com', '+48987654323', '$2y$12$5WalVqOzwEO80XzP7g65ReJzpBkI4o.kBX2ZJHfXOjmv0ZDPuVR8a', 'klient', TRUE);

INSERT INTO pracownicy (id, uzytkownik_id, opis, aktywny) VALUES
(1, 2, 'Specjalista od diagnostyki PC, montażu zestawów komputerowych oraz instalacji systemów.', TRUE),
(2, 3, 'Inżynier mikroelektroniki: naprawy płyt głównych, lutowanie BGA, czyszczenie i konserwacja laptopów.', TRUE);

INSERT INTO kategorie_uslug (id, nazwa, opis, aktywna) VALUES
(1, 'Diagnostyka i Konserwacja', 'Podstawowe przeglądy sprzętu, czyszczenie układów chłodzenia i wymiana past termoprzewodzących.', TRUE),
(2, 'Naprawy Sprzętowe PC i Laptopów', 'Wymiana uszkodzonych podzespołów, matryc, gniazd zasilania oraz naprawy płyt głównych.', TRUE),
(3, 'Oprogramowanie i Systemy', 'Instalacja systemów operacyjnych, usuwanie złośliwego oprogramowania oraz konfiguracja sterowników.', TRUE),
(4, 'Archiwalna Kategoria', 'Kategoria wycofana z oferty do testów dezaktywacji.', FALSE);

INSERT INTO uslugi (id, kategoria_id, nazwa, opis, czas_trwania_minuty, cena, aktywna) VALUES
(1, 1, 'Konserwacja układu chłodzenia laptopa', 'Czyszczenie z kurzu, wymiana termopadów oraz nałożenie pasty termoprzewodzącej wysokiej klasy.', 60, 150.00, TRUE),
(2, 1, 'Kompleksowa diagnostyka sprzętowa', 'Testy obciążeniowe CPU, GPU, pamięci RAM oraz weryfikacja SMART dysków SSD/HDD.', 45, 80.00, TRUE),
(3, 2, 'Wymiana matrycy w laptopie', 'Demontaż uszkodzonego panelu LCD i montaż nowej matrycy (cena bez kosztu części).', 90, 180.00, TRUE),
(4, 2, 'Naprawa sekcji zasilania płyty głównej', 'Lutowanie uszkodzonych tranzystorów MOSFET, wymiana przetwornic i gniazd DC.', 120, 350.00, TRUE),
(5, 3, 'Instalacja systemu Linux lub Windows', 'Instalacja czystego systemu z kompletem najnowszych sterowników i podstawowym pakietem biurowym.', 60, 120.00, TRUE),
(6, 3, 'Usunięcie wirusów i optymalizacja', 'Odwirusowanie, oczyszczenie autostartu oraz konfiguracja zapory sieciowej.', 45, 100.00, TRUE),
(7, 2, 'Wycofana usługa naprawy napędów DVD', 'Nieświadczona już usługa pozostawiona do testu zachowania integralności.', 30, 50.00, FALSE);

INSERT INTO uslugi_pracownikow (pracownik_id, usluga_id, aktywne) VALUES
(1, 1, TRUE),
(1, 2, TRUE),
(1, 5, TRUE),
(1, 6, TRUE),
(2, 1, TRUE),
(2, 2, TRUE),
(2, 3, TRUE),
(2, 4, TRUE),
(2, 7, FALSE);

INSERT INTO godziny_pracy (pracownik_id, dzien_tygodnia, czas_rozpoczecia, czas_zakonczenia, aktywne) VALUES
(1, 1, '08:00:00', '16:00:00', TRUE),
(1, 2, '08:00:00', '16:00:00', TRUE),
(1, 3, '08:00:00', '16:00:00', TRUE),
(1, 4, '08:00:00', '16:00:00', TRUE),
(1, 5, '08:00:00', '16:00:00', TRUE),
(2, 1, '10:00:00', '14:00:00', TRUE),
(2, 1, '14:30:00', '18:00:00', TRUE),
(2, 2, '10:00:00', '18:00:00', TRUE),
(2, 3, '10:00:00', '18:00:00', TRUE),
(2, 4, '10:00:00', '18:00:00', TRUE),
(2, 5, '09:00:00', '15:00:00', TRUE);

INSERT INTO rezerwacje (
    id, uzytkownik_id, pracownik_id, usluga_id, czas_rozpoczecia, czas_zakonczenia,
    cena_historyczna, status_rezerwacji, komentarz_klienta, anulowano
) VALUES
(1, 4, 1, 1, '2026-05-10 09:00:00', '2026-05-10 10:00:00', 150.00, 'zrealizowana', 'Laptop grzał się przy renderingu, po czyszczeniu temperatury w normie.', NULL),
(2, 5, 2, 4, '2026-05-15 11:00:00', '2026-05-15 13:00:00', 350.00, 'w_trakcie', 'Płyta nie reaguje na włącznik po zalaniu herbatą.', NULL),
(3, 6, 1, 5, '2026-05-20 10:00:00', '2026-05-20 11:00:00', 120.00, 'potwierdzona', 'Proszę o zachowanie plików z pulpitu przed formatem.', NULL),
(4, 4, 2, 3, '2026-05-21 14:30:00', '2026-05-21 16:00:00', 180.00, 'oczekujaca', 'Pęknięta matryca 15.6 cala IPS 144Hz.', NULL),
(5, 5, 1, 2, '2026-05-12 13:00:00', '2026-05-12 13:45:00', 80.00, 'anulowana', 'Klientka zrezygnowała z diagnostyki, zakupiła nowy sprzęt.', '2026-05-11 18:20:00');
