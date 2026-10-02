-- schema_update10.sql
-- Add optional candidate-contact email column to users table
ALTER TABLE users ADD COLUMN contact_email VARCHAR(255) DEFAULT NULL;
