-- The test suite runs against this database, so tests never touch development data.
SELECT 'CREATE DATABASE pixelforge_testing'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'pixelforge_testing')\gexec
