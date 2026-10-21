-- ======================================================================
--  LIMS - yangi SQL Server bazasi va login.   SSMS da yoki:
--    sqlcmd -S localhost -E -C -i baza_yaratish.sql
--  Ishga tushirishdan OLDIN pastdagi 3 ta qiymatni almashtiring:
--    lims_yangi     - baza nomi (.env: DB_DATABASE)
--    lims_user      - login (.env: DB_USERNAME)
--    KUCHLI_PAROL   - parol (.env: DB_PASSWORD), kamida 12 belgi
--  Eski serverdagi baza collation'ini bilish:  SELECT name, collation_name FROM sys.databases;
--  Yangi baza ham xuddi shunday bo'lsin (quyida Cyrillic_General_CI_AS qo'yilgan).
--  SQL Server "Mixed Mode" (SQL login) rejimida bo'lishi kerak.
-- ======================================================================
USE master;
GO
IF DB_ID(N'lims_yangi') IS NULL
    CREATE DATABASE [lims_yangi] COLLATE Cyrillic_General_CI_AS;
GO
IF NOT EXISTS (SELECT 1 FROM sys.server_principals WHERE name = N'lims_user')
    CREATE LOGIN [lims_user] WITH PASSWORD = N'KUCHLI_PAROL', CHECK_POLICY = ON, DEFAULT_DATABASE = [lims_yangi];
GO
USE [lims_yangi];
GO
IF NOT EXISTS (SELECT 1 FROM sys.database_principals WHERE name = N'lims_user')
    CREATE USER [lims_user] FOR LOGIN [lims_user];
-- db_owner: migratsiyalar jadval yaratadi. Dastur faqat shu bazada ishlaydi.
ALTER ROLE db_owner ADD MEMBER [lims_user];
GO
PRINT N'Tayyor. Tekshirish:  sqlcmd -S localhost -U lims_user -P parol -d lims_yangi -C -Q "SELECT DB_NAME()"';
