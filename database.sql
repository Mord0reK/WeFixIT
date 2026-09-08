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
