USE acme_ats;

-- Interview schema is included in database/schema.sql.
-- This file is kept as a safe migration for older prototype databases.
ALTER TABLE interviews
  ADD COLUMN IF NOT EXISTS application_id INT UNSIGNED NULL AFTER id,
  ADD COLUMN IF NOT EXISTS interviewer_id INT UNSIGNED NULL AFTER application_id,
  ADD COLUMN IF NOT EXISTS ends_at DATETIME NULL AFTER starts_at,
  ADD COLUMN IF NOT EXISTS interview_type VARCHAR(20) NOT NULL DEFAULT 'video' AFTER ends_at,
  ADD COLUMN IF NOT EXISTS meeting_url VARCHAR(500) NULL AFTER interview_type,
  ADD COLUMN IF NOT EXISTS meeting_provider VARCHAR(80) NOT NULL DEFAULT 'Zoom' AFTER meeting_url,
  ADD COLUMN IF NOT EXISTS location VARCHAR(255) NULL AFTER meeting_provider,
  ADD COLUMN IF NOT EXISTS notes TEXT NULL AFTER status;

-- "Is anyone actually on a call right now" is tracked in the database, not in
-- the browser's localStorage — a live status has to be set by the room and
-- expires on its own if the room stops pinging, so it can never get stuck
-- showing an interview that doesn't exist / was never joined.
ALTER TABLE interviews
  ADD COLUMN IF NOT EXISTS room_status ENUM('idle','live') NOT NULL DEFAULT 'idle' AFTER room_code,
  ADD COLUMN IF NOT EXISTS room_last_ping DATETIME NULL AFTER room_status,
  ADD INDEX IF NOT EXISTS interview_room_live (room_status, room_last_ping);

-- If your existing prototype uses the old candidate_id interview table, recreate it from schema.sql
-- after backing up data because the old structure is not compatible with application-based interviews.
