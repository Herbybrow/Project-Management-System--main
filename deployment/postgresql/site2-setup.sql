-- Administrator only, in psql. Inspect first; do not rerun CREATE commands
-- when the role/database already exists. Never alter Site 1.
\set ON_ERROR_STOP on
CREATE ROLE site2_user LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION;
-- Interactive hidden password entry, avoiding passwords in SQL/history.
\password site2_user
CREATE DATABASE site2_db OWNER site2_user ENCODING 'UTF8';
\connect site2_db
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE, CREATE ON SCHEMA public TO site2_user;
-- Ownership gives the application access to objects it creates by migration.
-- No superuser or grants to any other database are needed.
