=== Ars Nova Singers Portal ===
Contributors: arsnovasingers
Tags: members, portal, choir, private, materials
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.13.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A login-gated members portal for the Ars Nova Singers choir: season projects, materials listed with free-form tags, a singer-side tag filter and bulk .zip download, roster, calendars, announcements, RSVPs and front-end singer bios.

== Description ==

Ars Nova Singers Portal adds a private, mobile-first member area to your existing WordPress site (built and tested alongside the Kadence theme). It has no plugin dependencies — no ACF required — and integrates with your site's existing `singer` profile post type.

**For singers** (role `singer`, no wp-admin access):

* A tabbed portal at `/portal/` (shortcode `[ans_singers_portal]`) with:
  * **Home / Announcements** — announcements scoped to their group(s).
  * **My Bio** — edit their own canonical profile from the front end: display name, voice part(s), email, headshot upload (Featured Image), pronouns, years with the group, favorite piece/quote, bio (post content), phone — with per-field "visible to choir" privacy toggles and a Gemini **"Compose with AI"** bio-drafting button.
  * **Roster** — the singers in their group(s), showing only choir-visible fields.
  * **Calendar** — embedded Google Calendars for their group(s) plus one-click Google/iCal subscribe links.
  * **Season Materials** — the current season's brief, then one sub-tab per project they can see; every material of the project is listed as a compact row with its tag chips — open it, download it, or tick several and take them all as one .zip. Files stay hosted in Drive; downloads are fetched server-side so they work whether or not the singer has their own Google access. A **"Filter by tag"** control (All / None + one toggle per tag, everything selected by default) lets each singer narrow the list live — e.g. just "Soprano" + "Video".
  * **Past Projects** — archived projects, read-only.
* Lightweight per-project **RSVP** (yes / maybe / no).

**For the Artistic Director and Personnel Manager** (roles `artistic_director`, `personnel_manager`):

* A top-level **Singers Portal** dashboard in wp-admin: counts, quick links, Projects, Announcements, Seasons, Groups, Roster, Calendars and Settings.
* **Projects** (`ans_project`) with dates, venue, description, brief link, status, group + season assignment, and a repeatable **Materials** table: type, title, URL (Drive/video/file), note and **unlimited free-form tags** per material (voice parts, content types, deadlines — anything), entered as removable chips with a suggestions palette. Tags organise; they never hide anything.
* Group-scoped **Announcements** and opt-in **email notifications** to affected groups when a project is published or updated.
* Roster management on singer profiles: link a user account, assign groups, edit bio fields, set privacy, send portal invites (creates the account and emails a set-password link).
* **Offboarding** without deletion: deactivate a singer from the Users screen — their access is removed and their profile hidden, nothing is deleted.

**Visibility model (since 1.2.0):** the whole portal is login-gated, and that is the only gate on materials — every portal member sees every material in a project they can access. Projects stay lightly scoped by their group tags (an untagged project is visible to everyone; a tagged one to its groups plus staff). Announcements remain group-scoped. Material tags are organisation, not access control.

== Installation ==

1. Upload the `ars-nova-singers-portal` folder to `/wp-content/plugins/`, or install the ZIP via Plugins → Add New → Upload.
2. Activate the plugin. Activation creates the three roles, seeds the four default Groups (Main, Small, Friday, Special Guests) and creates a `/portal/` page containing `[ans_singers_portal]` if one doesn't already exist.
3. Open **Singers Portal → Settings** and pick the current Season (create Seasons first under Singers Portal → Seasons).
4. Open **Singers Portal → Calendars** and paste the Google Calendar IDs for each group.
5. On each singer profile (Roster), assign Groups, fill the Email field, then use **Send / resend portal invite** to create + link their account.

== Frequently Asked Questions ==

= Does it require ACF or any other plugin? =

No. All meta boxes and fields are native.

= What happens to the old "Ars Nova Singer Directory" plugin? =

Since 1.1.0 the Portal fully absorbs it: same `singer` post type, same meta keys, same public bio pages — no data migration. While the old plugin is still active the Portal's absorbed pieces stay dormant (nothing registers twice); once you deactivate the old plugin, the Portal takes over automatically. You can then delete the old plugin.

= Where are material files stored? =

In Google Drive (or wherever the pasted URL points). The plugin stores links only. Since 1.14.0 nothing is embedded in the page: each material is a row with Open and Download, and Download (single or bulk .zip) is fetched through the site using the Google Connector's service-account token — so a singer gets their music whether or not they are signed in to Google.

= How do singers log in? =

Via the standard WordPress login. Singers are redirected to `/portal/` after login and are blocked from wp-admin entirely.

= Does deactivating or deleting the plugin remove data? =

Deactivation removes nothing. Uninstall also keeps everything by default; define `ANSP_REMOVE_DATA_ON_UNINSTALL` as `true` in wp-config.php before deleting the plugin to remove its options and roles.

= Can a "Special Guest" without a group see a material? =

Since 1.2.0 there are no per-material grants: any logged-in portal member sees every material in a project they can access. Give the guest a portal account (Roster → Send portal invite) and, if the project is group-tagged, add them to that group (e.g. Special Guests).

== Changelog ==

= Unreleased =
* **Materials are a list, not a gallery.** Every inline preview is gone — the Google Drive/Docs iframe, the video embed, the audio player and the inline image. A project's materials are now one compact row each: the title leads, and the content type, tags and the Open/Download buttons share the bottom line — buttons left, type and tags right-justified. Twelve materials used to run several screens deep because four of them rendered as 460–940px iframes; they now fit on one screen, which is what a singer opened the page for.
* **Bulk download.** Tick any number of materials and take them as a single .zip. Select all / Select none respect the tag filter, so narrowing to "Soprano" and hitting Select all gives you the soprano files and nothing else.
* **Downloads are fetched server-side.** Materials are shared with the site's Google service account, not necessarily with each singer's own Google identity, so a link pointing straight at Drive worked for some people and not others — and failed like a broken link rather than a permissions problem. Single and bulk downloads now stream through the site using the Ars Nova Google Connector's token: what a singer can see in the portal is exactly what they can download.
* Google-native Docs, Sheets and Slides have no raw bytes, so they are exported to PDF rather than skipped — the rehearsal doc is usually the most useful thing in the project.
* Anything that could not be fetched is named in a NOT-INCLUDED.txt inside the archive. A singer who thinks they have all their music and does not is worse off than one who can see what is missing.
* A material that is a link rather than a file (YouTube, an external page) gets no checkbox at all, so nobody selects something that would quietly fail to arrive.
* The submitted list of material ids is never trusted: both download handlers re-derive the caller's visible set through the permissions engine and intersect, so a hand-crafted request cannot reach another group's materials. Bytes are streamed to disk rather than held in memory, so archive size is bounded by the limits (40 files, 100MB per file, 300MB total — all filterable) and not by PHP's memory_limit.
* **Materials group under a piece.** A material can carry an optional piece label, and the singer-facing list renders one heading per piece with its score and rehearsal tracks beneath it, instead of a flat run of files. Zahnay asked for the parent; this is it. A project where nothing carries a piece renders exactly the flat list it did before, with no headings at all, so adopting pieces is opt-in per project and existing projects are untouched.
* The piece is a free-text label, not a pointer at another material row. Row ids are uniqid()-based and project-scoped with no referential integrity, so a parent/child scheme would silently orphan children when a parent row was deleted. A label also lets a piece exist with rehearsal tracks and no score yet, or with two scores, which is how the music actually arrives.
* **A piece never gates.** Groups remain the only access control, exactly as tags already work. A material with no piece is always shown, under "Other materials" — nothing is hidden for lacking a label.
* The admin Materials box offers the pieces already used on that project, so a project's labels stay self-consistent without imposing a naming scheme on anyone. Labels match case-insensitively and ignore surrounding whitespace; the first spelling used wins.
* The tag filter now hides a piece heading whose every material has been filtered out, rather than leaving a heading standing over nothing. Hiding a material inside a piece still clears its checkbox, so a filtered-out file stays out of the .zip.
* Both write paths accept the new field. ANSP_Materials::save() and the REST save_materials() sanitise this array independently, and a field added to only one of them is silently stripped on the other.

= 1.13.4 =
* The singers REST read now returns the profile photo itself — id, url, filename and pixel dimensions — not just a yes/no. 1.13.3 made the photo writable but left the read unable to say whether the photo was any good, and a roster audit on 2026-08-25 turned up six profiles restored from a 2021 shoot at 260x260 — below every crop the site generates, and visibly soft on the public grid. Answering "who is below our standard?" took three extra API calls per singer; it is now one call for the whole roster, which is the question asked after every photoshoot.
* That read also accepts `id` to narrow to a single profile, so setting one singer's photo no longer echoes the entire roster back.

= 1.13.3 =
* POST /portal/singers accepts photo_id, so a singer profile's featured image - the photo the public Meet the Singers grid renders - can be set by command. 0 clears it. The id must be a real image attachment; pointing a profile at a PDF or a dead id is an error rather than a silently broken card.

= 1.13.2 =
* The public Meet the Singers grid now shows name and voice part only. Pronouns and profession are no longer printed there - they remain on the singer's own profile and on the portal roster, which is where the per-field privacy toggles actually apply.
* Singers with no headshot sort to the end of the grid instead of scattering through it, alphabetical within each half. Their card carries an ans-singer--no-photo class so the gap can be styled rather than merely tolerated.

= 1.13.1 =
* POST /portal/groups can now CREATE a group, with an optional parent, so the tree can be built by command rather than by hand. Creating requires an explicit create=true — a typo in a slug should be an error, not a silent new group, because group names are singer-facing tab labels.
* That route is now covered by the production guard like every other write.

= 1.13.0 =
* Groups are now a tree, and only TOP-LEVEL groups become Materials tabs. Nest Ensemble Singers and High School Apprentice under Ars Nova Singers and their members open the Ars Nova Singers tab, where they find the full chorus's projects plus anything tagged for their own subgroup. The rule is "has no parent", not "has children" — Chamber Singers has no children and still gets its own tab.
* Fixes the bug that made this necessary: a singer tagged only with a subgroup was HIDDEN from their own ensemble's music, because every access check intersects the material's groups against the singer's and the parent was never in that list. A singer's groups now include every ancestor. Inheritance runs one way — being in the full chorus does not grant Ensemble Singers material.
* New "Do not create a tab" checkbox on each group. The hierarchy still decides; the box can only ever suppress a tab, never create one. It is there for a top-level group that scopes projects without naming an ensemble — Board Member, whose materials belong in the Board Portal rather than in front of singers.
* A top-level group with no projects anywhere beneath it still makes no tab, which is what keeps a group someone created by accident from appearing in front of the choir before anyone notices.
* The REST group routes now read and write the tree: parent, parent_slug, is_top_level and no_tab.

= 1.12.0 =
* New admin-only REST surface covering everything the portal owns: status, groups, seasons, projects, materials, announcements, singers, settings, access codes, and trash. The portal's content types are deliberately not public in the REST API, which meant nobody could read or repair portal data by API — only by clicking through wp-admin. This closes that gap without opening any of it to the public.
* Routes live under ars-nova/v1 with a /portal/ prefix rather than a namespace of their own, so they are reachable by the same connector everything else uses.
* Every route requires the ansp_manage_portal capability (or manage_options), and every write against the production site must be resent with confirm_production=true. Writes to materials append by default, so a one-row call cannot wipe a season. Trash moves a post to the trash and never deletes it. Access codes come back masked and the code string itself cannot be set by API. The Gemini key reports only whether it is set.

= 1.11.0 =
* Materials is now one tab per group. A singer in the full choir sees one tab named for their ensemble; someone in both the full choir and Chamber Singers sees both, because the two rehearse separately and their music has always lived in different places. Tab labels come from the group's own name, so renaming a group in wp-admin renames the tab.
* Each Materials tab is scoped to its group's projects, so a Chamber Singers tab can never show a full-choir project.
* A viewer with no group still gets one unscoped Materials tab, so a singer who registers before anyone assigns them is not met with an empty portal.
* The portal heading now names the audience rather than repeating the page title: "Singers Portal", or "Board Portal" for the ans_board role. New ansp_portal_name filter.


= 1.10.0 =
* Access codes now carry a GROUP. Anyone registering with a code is added to
  that group automatically — the Personnel Manager no longer edits every new
  singer's record by hand.
* Codes can be added freely, one per group, instead of the two hardcoded ones.
  A stored role that does not exist now falls back to `singer` rather than
  producing an account with no role at all.
* Headshots are square and image-forward on My Bio, the portal Roster and the
  public Singers grid, cropped to the upper third so faces are not cut off.
* Roster and the public grid link through to each singer's own bio page.
* Document and Drive previews span the full materials grid at a readable height.
* Past Projects tab and the per-project RSVP form are hidden behind the new
  `ansp_portal_tabs` and `ansp_show_project_rsvp` filters. Nothing was removed.


= 1.2.0 =
* **Access-control on materials replaced by a tag + filter model.** Materials are no longer hidden from anyone: the whole portal is already login-gated, so every portal member now sees every material in a project they can access. The per-material permission UI (ALL / group checkboxes, voice-part checkboxes, email grants) is gone.
* **Unlimited free-form tags per material.** The Materials meta box now has a tags input rendered as removable chips — type anything (voice parts, "Video", "Rehearsal Note", "due Oct 3", …) and press Enter or comma. A suggestions palette offers the seven voice parts and common content types (Video, Audio / MP3, Sheet Music, Rehearsal Note, Rehearsal Date, Image, Link), but tags are never limited to it.
* **Singer-side "Filter by tag".** Each project's materials list gains a filter built from the union of the project's tags, with All / None buttons and one toggle per tag. Everything is selected by default; a material shows when any of its tags is selected (or when it has no tags at all). The selection is per-visit only — nothing is stored.
* **Automatic content-type tags.** Each material's type contributes an effective tag (video_link → "Video", recording → "Audio", sheet_music → "Sheet Music", image → "Image", document → "Document", drive_link → "Link", rehearsal_note → "Rehearsal Note", rehearsal_date → "Rehearsal Date"), so content type is filterable even when no tag was typed.
* **Two new material types:** "Rehearsal Note" and "Rehearsal Date".
* Project visibility is unchanged: projects stay lightly scoped by their group tags; untagged projects show for everyone. Announcements stay group-scoped. Legacy per-material `permission` data on old rows is ignored harmlessly (and dropped on next save); the now-meaningless "Default material permission" setting was removed.

= 1.1.0 =
* **Consolidation: the Portal absorbs the "Ars Nova Singer Directory" plugin.** The `singer` custom post type, its meta (`parts`, `years_with_group`, `favorite_piece`, `favorite_quote`, `pronouns`, `profession`, legacy `voice_part`), the "Singer Profile Details" admin meta box, the Voice Part admin column and the public bio page (with its styles) are now built in — with the SAME slugs, meta keys and field names, so no data migration is needed. A transition guard keeps everything dormant while the old Directory plugin is still active; deactivate it and the Portal takes over seamlessly.
* **Removed all duplicate Portal profile fields** (voice part, years, favorite piece/quote, pronouns, bio, headshot URL). The singer edit screen now has exactly one profile-details form plus the portal account/groups/contact/invite box. Bio = post content; headshot = Featured Image.
* **Front-end My Bio editor now writes the canonical fields**: bio → post content, headshot → Featured Image, voice part(s) → `parts` checkboxes (7 options), years → `years_with_group`, favorites → `favorite_piece`/`favorite_quote`, pronouns → `pronouns`.
* **Voice-part material permissions**: each material can additionally be targeted at specific voice parts (Soprano, Mezzo-Soprano, Alto, Countertenor, Tenor, Baritone, Bass) — e.g. a "Soprano part" file only for the sopranos within a project. Leaving the voice-part boxes unchecked keeps exact v1.0 behaviour.
* **"Compose with AI"**: a Gemini-powered button on the My Bio tab drafts a warm 2–4 sentence bio from the singer's notes (model filterable via `ansp_gemini_model`, default `gemini-2.0-flash`). Configure the API key under Singers Portal → Settings.

= 1.0.0 =
* Initial release: portal shortcode with six tabs, ans_project CPT, ans_group + ans_season taxonomies, materials with per-item permissions, visibility engine, roster with per-field privacy, Google Calendar embeds, group-scoped announcements, email notifications, RSVPs, front-end bio editor, portal dashboard, invites and offboarding.

== Upgrade Notice ==

= Unreleased =
Materials no longer preview inline. Each one is a row with Open and Download, plus checkboxes and a "Download selected (.zip)" button. Downloads are fetched through the site with the Google service-account token, so they no longer depend on each singer's own Drive access. Requires the Ars Nova Google Connector to be active for Drive-hosted materials. Materials can also be grouped under a piece: set the Piece field on a material and the singer-facing list gains a heading per piece. Nothing changes for a project until someone fills that field in, and the field never affects who can see what. Note for anyone calling the REST materials endpoint with replace=true: echo the piece back with each row, or it is cleared — the same is already true of note, tags and groups.

= 1.2.0 =
Per-material permissions are replaced by unlimited free-form tags plus a singer-side "Filter by tag" control. Nothing is gated at the material level any more — every portal member sees every material in a project they can access. Old permission data is ignored; no migration needed.

= 1.1.0 =
The Portal now absorbs the "Ars Nova Singer Directory" plugin (same slugs and meta keys — no migration) and removes its own duplicate profile fields. After updating, deactivate the old Directory plugin. Adds voice-part material permissions and a Gemini "Compose with AI" bio button.

= 1.0.0 =
Initial release.
