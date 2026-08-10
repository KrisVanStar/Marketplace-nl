-- Marketplace-NL Lead Scanner - MySQL/MariaDB schema.
-- Import this once in your hosting control panel (phpMyAdmin or similar)
-- before using the app. Upgrading from an older version? See migrate.sql.

CREATE TABLE IF NOT EXISTS businesses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  website VARCHAR(500) NOT NULL DEFAULT '',
  -- Identity key: the website when there is one, otherwise name+address,
  -- so several businesses without a website can coexist.
  website_hash CHAR(40) NOT NULL,
  has_website TINYINT NOT NULL DEFAULT 0,
  city VARCHAR(255),
  category VARCHAR(100),
  business_type VARCHAR(100),
  address VARCHAR(500),
  postcode VARCHAR(20),
  phone VARCHAR(50),
  email VARCHAR(255),
  opening_hours VARCHAR(255),
  facebook VARCHAR(500),
  instagram VARCHAR(500),
  lat DECIMAL(10, 7),
  lon DECIMAL(10, 7),
  source VARCHAR(50) DEFAULT 'osm',
  source_id VARCHAR(100),
  status VARCHAR(20) DEFAULT 'new',
  notes TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_website_hash (website_hash),
  INDEX idx_city (city),
  INDEX idx_category (category),
  INDEX idx_status (status),
  INDEX idx_has_website (has_website)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS scans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  business_id INT NOT NULL,
  score INT NOT NULL,
  priority VARCHAR(20),
  summary VARCHAR(500),
  reasons_json TEXT,
  findings_json MEDIUMTEXT,
  positives_json TEXT,
  signals_json MEDIUMTEXT,
  status_code INT,
  final_url VARCHAR(500),
  response_time_ms INT,
  error VARCHAR(500),
  scanned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_scans_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  INDEX idx_business (business_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS scan_jobs (
  id CHAR(36) PRIMARY KEY,
  status VARCHAR(20) DEFAULT 'pending',
  city VARCHAR(255),
  category VARCHAR(100),
  total INT DEFAULT 0,
  processed INT DEFAULT 0,
  found_leads INT DEFAULT 0,
  message VARCHAR(500),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS scan_queue (
  id INT AUTO_INCREMENT PRIMARY KEY,
  job_id CHAR(36) NOT NULL,
  business_id INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_queue_job FOREIGN KEY (job_id) REFERENCES scan_jobs(id) ON DELETE CASCADE,
  CONSTRAINT fk_queue_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  INDEX idx_job (job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
