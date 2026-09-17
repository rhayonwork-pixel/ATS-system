Profile Photo Migration

If the acme_ats database already exists, run:
  database/migration-profile-photo.sql

For a fresh installation, database/schema.sql already includes candidates.profile_image.

Candidate profile photos are stored in:
  assets/uploads/profile-images/

The uploads directory is protected from PHP/CGI execution by the existing .htaccess.
