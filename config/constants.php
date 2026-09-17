<?php
/**
 * Shared limits/paths used by app/Services and legacy upload handlers alike,
 * so a limit only ever needs to change in one place.
 */

const UPLOAD_DIR_RESUMES  = __DIR__ . '/../storage/resumes';
const UPLOAD_DIR_LEGACY_RESUMES = __DIR__ . '/../assets/uploads/resumes';
const UPLOAD_DIR_AVATARS  = __DIR__ . '/../assets/uploads/profile-images';
const UPLOAD_DIR_COMPANY  = __DIR__ . '/../assets/uploads/company';

const MAX_RESUME_BYTES  = 5 * 1024 * 1024;
const MAX_AVATAR_BYTES  = 3 * 1024 * 1024;

const RESUME_ALLOWED_EXTENSIONS = [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];

const AVATAR_ALLOWED_MIME = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

/** How long an interview room token stays valid after the scheduled end time. */
const INTERVIEW_TOKEN_GRACE_MINUTES = 240;

/** Signaling polling cadence hint returned to the client (ms). Client code is
 * free to poll faster while a peer connection is actively negotiating. */
const INTERVIEW_POLL_INTERVAL_MS = 2000;
