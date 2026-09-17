-- 010_create_interview_signals.sql
-- Adds the peer-connection signaling storage the browser-based interview
-- room needs: one row per interview holding the current SDP offer/answer,
-- and an append-only queue of ICE candidates per side. AJAX polling
-- (api/interview/*.php) reads and writes these — InfinityFree has no
-- WebSocket support, so polling is the transport, not the compromise.

CREATE TABLE IF NOT EXISTS interview_signals (
  interview_id BIGINT UNSIGNED PRIMARY KEY,
  offer_sdp LONGTEXT NULL,
  offer_updated_at DATETIME NULL,
  answer_sdp LONGTEXT NULL,
  answer_updated_at DATETIME NULL,
  connected_at DATETIME NULL,
  ended_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (interview_id) REFERENCES interviews(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS interview_ice_candidates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  interview_id BIGINT UNSIGNED NOT NULL,
  role ENUM('host','candidate') NOT NULL,
  candidate LONGTEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (interview_id) REFERENCES interviews(id) ON DELETE CASCADE,
  INDEX ice_lookup (interview_id, role, id)
) ENGINE=InnoDB;
