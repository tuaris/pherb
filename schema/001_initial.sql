-- Pherb schema: initial job table
CREATE TABLE IF NOT EXISTS jobs (
  id VARCHAR(32) PRIMARY KEY,
  audio_path VARCHAR(255) NOT NULL,
  status ENUM('queued','processing','completed','failed') DEFAULT 'queued',
  options JSON,
  callback_url VARCHAR(512),
  result_path VARCHAR(255),
  error_message TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME,
  completed_at DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
