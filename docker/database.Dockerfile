FROM mariadb:11.4

COPY database/database-czysta.sql \
     /docker-entrypoint-initdb.d/database.sql
