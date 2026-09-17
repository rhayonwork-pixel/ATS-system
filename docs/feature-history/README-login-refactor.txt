login.php: split-screen authentication refactor
===============================================

NO MIGRATION NEEDED
-------------------
The users table already has everything required.

EXPECTED SCHEMA (reference)
---------------------------
    CREATE TABLE users (
      id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name          VARCHAR(120)  NOT NULL,
      email         VARCHAR(190)  NOT NULL UNIQUE,
      password_hash VARCHAR(255)  NOT NULL,   -- password_hash(), never plaintext
      role          ENUM('super_admin','admin','recruiter','hiring_manager','employee')
                    NOT NULL DEFAULT 'recruiter',
      active        TINYINT(1)    NOT NULL DEFAULT 1,
      account_status ENUM('pending','active','rejected','suspended','disabled',
                          'pending_reactivation') NOT NULL DEFAULT 'active',
      created_at    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;

    -- email is UNIQUE, which is what makes the single-row lookup safe.

TWO DEPARTURES FROM THE BRIEF, BOTH DELIBERATE
----------------------------------------------
1. THE FILE IS NOT SELF-CONTAINED, AND SHOULD NOT BE.

   The brief asked for session config, CSS and markup in one file. Two of those
   cannot correctly live in login.php:

   * session_set_cookie_params() MUST run before session_start(). By the time
     login.php executes, includes/config.php has already opened the session and
     the cookie flags are fixed. Putting the call in login.php would be dead
     code that silently does nothing. It now lives in config.php, where it also
     protects all 51 other pages rather than just this one.

   * Inline CSS would fork the design system. The ATS has one stylesheet with
     shared tokens; a second copy of the colours in a <style> block would drift
     the moment either changed. The auth styles are a scoped section of
     assets/styles.css using the same custom properties.

   The PHP logic, markup and page-specific script ARE all in login.php.

2. CSRF TOKEN NAME. The brief specified $_SESSION['csrf_token']. This project
   already uses $_SESSION['csrf'] with the same construction
   (bin2hex(random_bytes(32)), compared with hash_equals) and 21 other pages
   validate against it. Renaming it would have invalidated every other form, so
   the existing name is kept.

SESSION HARDENING (includes/config.php)
---------------------------------------
    session.use_strict_mode = 1     PHP rejects a session id it did not issue,
                                    which blocks fixation via a planted cookie
    session.use_only_cookies = 1    no session id in the URL
    httponly = true                 JavaScript cannot read the cookie
    samesite = Strict               the cookie does not ride cross-site requests
    secure   = only under HTTPS     so local XAMPP over http still works
    lifetime = 0                    browser session by default

session_regenerate_id(true) runs on successful authentication.

AUTHENTICATION
--------------
PDO prepared statement, password_verify() against password_hash. The failure
message is identical for an unknown email and a wrong password
("Invalid email or password"), so the form cannot be used to enumerate staff.

The one place a specific message IS returned is a CORRECT password on an
inactive account — pending approval, suspended, disabled, awaiting
reactivation. That reveals nothing to an attacker who does not already hold
valid credentials, and without it a newly created recruiter has no way to learn
why they cannot get in.

Verified present: hash_equals, password_verify, session_regenerate_id(true),
the parameterised lookup, FILTER_VALIDATE_EMAIL, and the generic error.

CSRF
----
Validated inline rather than through check_csrf(), because that helper exits
with a bare 419 text page. An expired token now renders the same accessible
error banner as any other failure, with the form still usable.

INPUT AND OUTPUT
----------------
filter_input() + trim() on the identifier, FILTER_VALIDATE_EMAIL before any
query. Every echoed value passes through e(), which is
htmlspecialchars($v, ENT_QUOTES, 'UTF-8').

The email is sticky on failure; the password field never carries a value
attribute — confirmed by grep, not by eye.

REMEMBER ME
-----------
Extends the existing session cookie to 30 days rather than issuing a second
long-lived "remember" token. One credential to expire, one to revoke, and no
new table. The extended cookie keeps httponly and samesite=Strict.

LAYOUT
------
    >= 992px   45/55 split: brand showcase | centred form, min-height 100vh
    < 992px    single column; the showcase becomes a compact banner with
               reduced padding and the badges laid out horizontally
    <= 560px   the third badge and the secondary links are dropped so the hero
               does not push the form below the fold

CSS Grid and Flexbox with the project's custom properties. No framework.

The header/footer gained an $authPage branch so the page can own the whole
viewport: no topbar, no shell wrapper, and no <main> from the header — the page
supplies its own, so there is no nested landmark. Checked that the private and
public layouts still balance.

ACCESSIBILITY
-------------
  * semantic <main>, <section>, <form>, <label>, <button type="submit">
  * every input has an explicit <label for>, bound by id
  * the error banner is role="alert" with id="login-error"; the faulty field
    gets aria-invalid="true" aria-describedby="login-error" and takes focus
  * novalidate on the form so the server messages are authoritative, with
    checkValidity() still consulted before the loading state
  * :focus-visible rings on inputs, links, the checkbox and the button; the
    input focus ring is a 3px shadow, which is the visible indicator
  * prefers-reduced-motion suppresses the hover lift and the spinner

Contrast: #2f6b4f on #fff is 5.9:1; the alert text #8f2f16 on #fdeeea is 6.8:1;
brand copy #d6e6db on the gradient's darkest stop is above 7:1. All exceed the
4.5:1 requirement.

SUBMIT STATE
------------
The button disables itself and shows a spinner on a valid submit, so a double
click cannot post twice. Native validation runs first, so an invalid form is
reported by the browser without the button locking.

VERIFIED
--------
  * php lexer: 52 files clean
  * branch-aware render: login.php balanced on every sampled path
  * header/footer landmark balance checked for auth, private and public layouts
  * node --check on the inline script
  * password field has no value attribute; email is sticky
  * the other 21 pages still use check_csrf() against the unchanged token
