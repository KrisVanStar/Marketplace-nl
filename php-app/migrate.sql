-- Upgrading an existing Marketplace-NL database to the current version.
-- Run this ONCE in phpMyAdmin if you already had data from an earlier
-- version. On a fresh install just use schema.sql instead.
--
-- MySQL has no "ADD COLUMN IF NOT EXISTS", so a column that already
-- exists reports an error you can safely ignore.

ALTER TABLE businesses
  ADD COLUMN has_website TINYINT NOT NULL DEFAULT 1,
  ADD COLUMN business_type VARCHAR(100),
  ADD COLUMN postcode VARCHAR(20),
  ADD COLUMN email VARCHAR(255),
  ADD COLUMN opening_hours VARCHAR(255),
  ADD COLUMN facebook VARCHAR(500),
  ADD COLUMN instagram VARCHAR(500),
  ADD COLUMN lat DECIMAL(10, 7),
  ADD COLUMN lon DECIMAL(10, 7),
  ADD COLUMN notes TEXT;

ALTER TABLE businesses MODIFY website VARCHAR(500) NOT NULL DEFAULT '';
ALTER TABLE businesses ADD INDEX idx_has_website (has_website);

ALTER TABLE scans
  ADD COLUMN summary VARCHAR(500),
  ADD COLUMN findings_json MEDIUMTEXT,
  ADD COLUMN positives_json TEXT;

ALTER TABLE scans MODIFY signals_json MEDIUMTEXT;

-- Older rows had these columns; they are no longer written to and may be dropped.
-- ALTER TABLE scans DROP COLUMN is_https, DROP COLUMN has_viewport;
