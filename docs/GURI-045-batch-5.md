# GURI-045 batch 5: Complete community workflows

## Result

Public pages work without login. Only submissions, reservations, personal records and staff work require the existing main login. No new ESI scopes, staff grants or database migrations.

- `/community/` is the public art, propaganda, comic and recording archive. Approved images render as gallery thumbnails. Public cards show excerpts and link to detail pages.
- `/community/submit.php` accepts one artwork or up to ten ordered comic pages. PNG/JPEG/WebP only, 8 MB per file, 24 MB total, bounded pixel dimensions. The server's PHP upload/request limit may be lower and is shown in the form. Each image requires a description or transcript, plus release title, description, creator credit and permission attestation. Members have at most five pending entries and ten submissions per UTC day.
- `/community/submissions.php` shows the author's own submissions, private previews and review notes. Staff submissions start as drafts. Member submissions start pending. Neither is publicly accessible until an officer publishes it after checking permission and credit.
- `/community/read.php` renders artwork and an internal comic reader. Reading order, previous/next pages, page links and transcripts work without JavaScript. Officers can reorder pages and edit transcripts. External-link releases from earlier batches still work.
- `/community/media.php` authorizes every file request against the ledger. Pending/rejected/draft/archived media is readable only by its author or staff. Public images become unavailable when archived. Downloads do not grant reuse rights. Files remain outside the web root, use generated names, fixed raster MIME types, nosniff, sandbox and no-store headers. Personal metadata is not stripped automatically; submitters are told to remove it first.
- `/operations/entry.php` provides fleet, order, recording and report details. Reports link to an existing fleet, historical campaign/system context and an optional HTTPS campaign reference. Fleet detail pages list their reports. Hidden related entries are never exposed through a public relationship link.
- Supply orders now have exact item names, whole-unit targets, destination/contact, ISK reward per unit and payment terms. These are organizer declarations, not live contract guarantees. Changing item, destination or payment terms after reservations requires a new job.
- Reservations are atomic, use the current ledger revision, last at most 48 hours (or until the deadline) and cannot exceed remaining unverified/unreserved stock. Pilots can cancel an unsubmitted reservation. Expiry releases capacity automatically. Delivery evidence must cover the whole reservation and arrive before expiry. If late or partial, contact the organizer; cancel/re-reserve or have an officer reject incorrect evidence before submitting a corrected quantity.
- Pending evidence holds capacity until review. A different officer verifies the delivery in EVE and approves or rejects it with a note. Verification never sends ISK. Approved whole quantities contribute to public per-order progress. Unrelated item quantities are not summed. Public boards reveal no pilot names or private evidence.
- A pilot's private supply record shows reservations, evidence and review notes. Dossier links lead to their records and submissions rather than duplicating the public guides.
- Archived operations can explicitly remain public as historical records, including verified progress. The default archive remains private. This option never applies to images/comics. Existing unstructured jobs and reviewed records survive: old records without quantities are not assigned invented item counts.

The locked ledger preserves older schemas without a destructive migration. The last 1,000 mutation audit records retain actor/action/subject/time. Deployments preserve `storage/community` and images, and include them in the verified predeployment backup. `.gitignore` excludes all community runtime records. The preparation script also adds a local Git exclude before clean-tree checks; it refuses to proceed if community records are already tracked.

## Publishing

1. Open the staff desk, then Community Publishing. Use Upload art / comic pages for images, or New text or linked entry for fleets, orders, reports and recordings.
2. Open the draft or pending submission from the queue. Review the preview, creator credit, descriptions/transcripts and page positions. Check permission before setting Published. Reject with a private review note when needed.
3. For orders, name the exact item, target, unit reward and payer/timing terms. Publish only real requests. Pilots read the order, confirm with the contact, reserve units and submit private evidence.
4. Review whole delivery quantities in EVE. Staff cannot review their own deliveries. A rejection frees capacity; an approval adds verified progress. Pay any agreed reward through the appropriate in-game process.
5. Add factual field reports and link their fleet/campaign context. Keep historical fleet/order details public only when intentionally selecting that archive option.

## Remaining content and verification

Actual artwork/comic pages need creator permission, credit and descriptions/transcripts. Real operations need organizer schedules, eligibility and contacts. Real orders need requested quantities, destination and payment terms. Recordings need approved URLs and metadata. No examples or invented orders ship in the live ledger. Additional audio releases/annotations and Cozen policies still need confirmation; private enrichment and funding remain separate tickets.

GURI-045 remains open for final signed-in/out desktop/mobile production review and reconciliation of the 32 subtasks. These packages do not automatically mark ticket items complete.

## Validation

- Community service tests: roles, quantity validation, reserved capacity, stale revisions, ownership, cancellation, expiry, whole-quantity evidence, independent review, exactly-once credit, archive policy, page ordering, persistence and prior-batch compatibility.
- Concurrency test: two processes reserve the same last five units; one succeeds and one is rejected.
- Raster tests: PNG signature/dimensions, rejection of PHP/SVG/non-images, safe storage paths and genuine HTTP upload requirement.
- Real-route HTTP integration in a disposable copy with stub identities: multipart upload, CSRF, private previews/media, publication, comic reordering, downloads, rollback cleanup, reservations, evidence privacy, review progress and linked fleet reports. Stub authentication exists only in the test fixture and is never copied into application source.
- Cumulative PHP lint, public page rendering/search/escaping, industry/campaign/freshness tests, JavaScript syntax and deployment-script syntax.

To run the HTTP fixture test: `GURI_TEST_PHP=/path/to/php python3 tests/test_community_http.py`. Normal PHP installs use configured fileinfo. For an extracted runtime, set `GURI_TEST_FILEINFO=/path/to/fileinfo.so` as well. Production upload/media/moderation usability still needs verification after deployment.
