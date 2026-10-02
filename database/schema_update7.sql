-- schema_update7.sql
-- Add OTP verification columns to users table
ALTER TABLE users ADD COLUMN is_verified TINYINT(1) DEFAULT 0;
ALTER TABLE users ADD COLUMN otp_code VARCHAR(10) DEFAULT NULL;
ALTER TABLE users ADD COLUMN otp_expires_at DATETIME DEFAULT NULL;
