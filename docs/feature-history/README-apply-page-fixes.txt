Apply page: spot count, phone input, resume upload
==================================================

NO MIGRATION. jobs.applicant_limit keeps its name and type.

FILES
-----
  apply.php                  spot logic, phone rule, one resume zone, JSON mode
  assets/js/apply-form.js    NEW — phone validation, drop zone, XHR submit
  assets/css/apply.css       NEW — the page's own styles
  job-detail.php             Apply button no longer hidden when "full"
  jobs.php                   "Full" / "N spots left" badges replaced
  job-post.php, admin.php    staff wording that was no longer true

SPOTS ARE A TARGET, NOT A CAP
-----------------------------
applicant_limit now means "how many people this role is looking for". It is
shown as a plain number — "10 spots available" — and never closes the role.

The limit was enforced in four places, not one. Changing apply.php alone would
have done nothing for anyone arriving through the careers site, because
job-detail.php replaced the Apply button with "Applications closed — role is
full" and jobs.php badged the card "Full". All four now agree:

  apply.php        header shows the static count; the POST gate and the
                   SELECT ... FOR UPDATE that rolled back at the limit are gone
                   (the transaction itself stays — candidate, application and
                   resume must still land together)
  job-detail.php   Apply is always a link; "N spots available", no ratio
  jobs.php         a static "N spots" badge, no Full / spots-left
  admin.php        "Limit reached" -> "Target met" (staff still see progress)
  job-post.php     field relabelled "Spots available — never closes the role"

What still closes the form: the portal-wide pause (setting
portal_accepting_applications = 0). That is a deliberate "stop all intake"
decision, not a spot count, and it is untouched. Verified: the form is hidden
and an XHR submit is refused with JSON while it is on.

PHONE
-----
type="tel", autocomplete="tel-national", aria-label, aria-describedby pointing
at the hint and the error, aria-invalid on failure, and a native `pattern` for
the no-JavaScript path. The dial code is aria-hidden: it is already announced
as part of the selected country.

The server rule gained a character check. It used to strip non-digits and count
what was left, so "call me 0917123456" passed as a phone number. Browser and
server now apply the same rule: digits and ( ) + . - space only, then 6-14
digits.

Layout: one grid row on wide screens; below 768px the country select takes a
row of its own and the dial code sits beside the number, so nothing overlaps
and the number field never gets squeezed (184px at 320px wide).

RESUME
------
The broken state was three things stacked: a .resume-drop label with its own
dashed border and a hardcoded #f4f9f2 hover (a near-white block over the dark
theme), a .dropzone inside it with a SECOND dashed border, and the legacy
"Click to upload resume" prompt underneath. It was also bound twice, by the
page's inline script and by app.js's global [data-dropzone] handler.

Now: one .ap-resume zone, off the global handler, token colours only. The real
<input type="file"> covers the zone invisibly, so a click anywhere opens the
picker, Tab lands on it, Enter or Space opens it, and it still uploads with
JavaScript off. The wrapper deliberately has NO tabindex — that would add a
second tab stop doing the same thing as the first. The focus ring is drawn on
the zone because the input is invisible.

Rejected before upload: anything that is not .pdf/.doc/.docx (checked by
extension AND reported MIME, with an empty MIME allowed because Windows often
reports none for .doc), anything over 5MB, empty files, and more than one file
dropped at once. The server still re-checks all of it.

UPLOAD PROGRESS NEEDED A SERVER CHANGE
--------------------------------------
A normal form POST exposes no progress at all. The form is now sent with
XMLHttpRequest, whose upload.onprogress drives the bar, and apply.php answers
that request with JSON (it checks X-Requested-With): {ok, redirect} on success,
{ok:false, error, field} on failure. After the bytes are up the bar reads
"Uploaded — finishing up…" rather than sitting at 100% while the server stores
the file.

Side benefit: a validation error no longer reloads the page, so the resume the
applicant chose stays attached instead of being wiped.

The plain POST still works exactly as before with scripting off.

TWO THINGS THAT WERE QUIETLY WRONG
----------------------------------
  * app.js's generic form[data-validate] handler loads in the footer, AFTER
    apply-form.js, so it would have run after the XHR had already gone — and it
    marks fields with an inline #b7492d border that never clears. The form no
    longer uses data-validate; the browser's constraint validation plus
    setCustomValidity() does the job, and the submit event only fires once it
    all passes.
  * A second `change` event arriving on the now-empty input (after a file was
    rejected and removed) wiped the rejection message and aria-invalid. An empty
    input no longer clears an error; only a good file or Remove does.

WHAT WAS VERIFIED
-----------------
Headless Chrome against the local PHP server and MySQL, 43 of 43, no console
errors. A role was temporarily set to a target of 3 with 3 applications —
"full" under the old rules — and:

  * careers list: no Full badge, static "3 spots"
  * job detail: Apply is a real link, "3 spots available"
  * apply: "3 spots available", form enabled
  * the application was ACCEPTED and redirected to the status page
  * phone: letters, too short, valid, country change
  * resume: one zone, no legacy prompt, drag over/leave states, 6MB rejected,
    .txt rejected, valid file shows name and size, Remove resets, Tab reaches
    it with a visible focus ring
  * progress, throttled to 300KB/s: the bar appeared, reported partial
    progress, and the submit button was disabled while sending
  * a duplicate application came back inline with the file still attached
  * 390px, 320px, 900px: no horizontal overflow, every control >= 44px, phone
    controls never overlap
  * dark theme: the zone is never a near-white block

Outside the browser: the no-JavaScript POST redirects on success (302), renders
a phone error with aria-invalid, and rejects "call me 0917123456"; the portal
pause still closes the form. All test rows and stored files were removed and
the role's target restored.

NOT VERIFIED
------------
Real drag-and-drop from a desktop file manager (the drag states were driven by
synthetic DragEvents; the drop path uses the same DataTransfer assignment
app.js already relied on), Safari and Firefox, a screen reader, and real mobile
hardware.

NOTED, NOT CHANGED
------------------
save_profile_image_upload() reports "Invalid profile photo upload" when the
photo field is missing from the request altogether, rather than treating it as
"no photo". Browsers always send the empty field, so real applicants never hit
it — only a non-browser client would.
