-- schema_update9.sql
-- Add description and status columns to questionnaires table for the redesigned builder
ALTER TABLE questionnaires ADD COLUMN description TEXT DEFAULT NULL;
ALTER TABLE questionnaires ADD COLUMN status VARCHAR(20) DEFAULT 'Active';
