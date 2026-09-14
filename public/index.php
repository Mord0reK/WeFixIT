<?php

$db = mysqli_connect('mariadb', 'root', 'zmien-to-na-inne', 'wefixit');

$dane = mysqli_query($db, 'SELECT * FROM uzytkownicy;');
$dane = mysqli_fetch_all($dane, MYSQLI_ASSOC);

foreach ($dane as $row) {
    echo $row['imie'] . ' ' . $row['nazwisko'] . ' - ' . $row['email'] . '<br>';
}
