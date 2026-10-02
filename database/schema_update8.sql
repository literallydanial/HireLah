-- schema_update8.sql
-- Add company profile columns to users table (employer-only, but column applies table-wide like profile_picture)
ALTER TABLE users ADD COLUMN company_name VARCHAR(255) DEFAULT NULL;
ALTER TABLE users ADD COLUMN company_website VARCHAR(255) DEFAULT NULL;
ALTER TABLE users ADD COLUMN company_address TEXT DEFAULT NULL;
ALTER TABLE users ADD COLUMN company_logo VARCHAR(255) DEFAULT NULL;
