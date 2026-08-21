=== Ars Nova Singers Portal ===
Contributors: arsnovasingers
Tags: members, portal, choir, private, materials
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.13.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A login-gated members portal for the Ars Nova Singers choir: season projects, materials with unlimited free-form tags + a singer-side tag filter, roster, calendars, announcements, RSVPs and front-end singer bios.

== Description ==

Ars Nova Singers Portal adds a private, mobile-first member area to your existing WordPress site (built and tested alongside the Kadence theme). It has no plugin dependencies — no ACF required — and integrates with your site's existing `singer` profile post type.

**For singers** (role `singer`, no wp-admin access):

* A tabbed portal at `/portal/` (shortcode `[ans_singers_portal]`) with:
  * **Home / Announcements** — announcements scoped to their group(s).
  * **My Bio** — edit their own canonical profile from the front end: display name, voice part(s), email, headshot upload (Featured Image), pronouns, years with the group, favorite piece/quote, bio (post content), phone — with per-field "visible to choir" privacy toggles and a Gemini **"Compose with AI"** bio-drafting button.
  * **Roster** — the singers in their group(s), showing only choir-visible fields.
  * **Calendar** — embedded Google Calendars for their group(s) plus one-click Google/iCal subscribe links.
  * **Season Materials** — the current season's brief, then one sub-tab per project they can see; every material of the project is listed with its tag chips, previews inline (Google Drive previews, video embeds, images, audio) and stays hosted in Drive. A **"Filter by tag"** control (All / None + one toggle per tag, everything selected by default) lets each singer narrow the list live — e.g. just "Soprano" + "Video".
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

In Google Drive (or wherever the pasted URL points). The plugin stores links only, and previews Drive files inline via Drive's `/preview` embed.

= How do singers log in? =

Via the standard WordPress login. Singers are redirected to `/portal/` after login and are blocked from wp-admin entirely.

= Does deactivating or deleting the plugin remove data? =

Deactivation removes nothing. Uninstall also keeps everything by default; define `ANSP_REMOVE_DATA_ON_UNINSTALL` as `true` in wp-config.php before deleting the plugin to remove its options and roles.

= Can a "Special Guest" without a group see a material? =

Since 1.2.0 there are no per-material grants: any logged-in portal member sees every material in a project they can access. Give the guest a portal account (Roster → Send portal invite) and, if the project is group-tagged, add them to that group (e.g. Special Guests).

== Changelog ==

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

= 1.2.0 =
Per-material permissions are replaced by unlimited free-form tags plus a singer-side "Filter by tag" control. Nothing is gated at the material level any more — every portal member sees every material in a project they can access. Old permission data is ignored; no migration needed.

= 1.1.0 =
The Portal now absorbs the "Ars Nova Singer Directory" plugin (same slugs and meta keys — no migration) and removes its own duplicate profile fields. After updating, deactivate the old Directory plugin. Adds voice-part material permissions and a Gemini "Compose with AI" bio button.

= 1.0.0 =
Initial release.
