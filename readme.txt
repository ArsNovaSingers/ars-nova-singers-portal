=== Ars Nova Singers Portal ===
Contributors: arsnovasingers
Tags: members, portal, choir, private, materials
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.22.1
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

= 1.22.1 =
* **A tab never advertises another group's credential.** 1.22.0 was looser than it should have been: a tab whose projects named no mirror folder, or named two, fell back to the root of the tree, and a group without its own username borrowed the only configured one. Against the real staging data that meant the **Ars Nova Singers tab — the full choir — would have shown a Chamber Singers username**, because its projects sit in `ensemble-singers` and `main` rather than in one folder.
* The panel now appears only where the projects name exactly one mirror folder **and** that folder has a username of its own. Everywhere else it is absent. A tab spanning two folders genuinely has no single address to give, and saying nothing is the honest answer.

= 1.22.0 =
* **The WebDAV address is on the page.** Under each group's projects there is now a collapsed "Sync this music to your tablet" panel carrying the server address and username, with Copy buttons. A singer with a WebDAV-capable file app can mount the season's folder and pull every score in one action, then re-sync later and have only what changed come down.
* **The password is deliberately not on the page.** The credential is shared per group today, and a shared secret printed into a page is a shared secret in every browser cache and every screenshot. The panel says so rather than leaving a blank.
* **The panel points at the folder the projects actually name**, read from each project's mirror address — not derived from the WordPress group slug. Those are different names (`cs` here, `chamber-singers` in the mirror), and assuming they matched is what made 1.15.0 find nothing at all. (Projects that disagree fell back to the root of the tree in this version — corrected in 1.22.1, which shows nothing instead.)
* **Nothing renders unless it would work.** No worker configured, switched off, or no username for the group, and the panel is absent rather than showing an address that cannot be logged into. Clearing a username removes the panel with it.
* Configurable over REST at `ars-nova/v1 portal/dav` (GET/POST), like the mirror settings in 1.16.0 — no wp-admin needed. The read reports what a singer would actually see, not just what was set.
* Collapsed and below the music on purpose. Nearly every singer taps a file and reads it; this is the route for the few who want the whole season on a tablet, and it should not be what anyone scrolls past to reach their part.

= 1.21.0 =
* **One title at the top of the portal instead of two.** The WordPress page was called "Singers Portal" and the portal printed its own heading saying the same thing, so the first screen — the part a singer sees before scrolling — was largely spent saying it twice.
* The theme's page title is now hidden on `/portal/` and the portal's own heading is the page's title, reading **"Ars Nova Portal"**. It is a real `<h1>` now rather than an `<h2>`, and it is sized as a working page's heading rather than a hero, so the space the duplicate was taking goes back to the music.
* Board members still get "Board Portal" on the same page, and the `ansp_portal_name` filter still overrides it — one page, several audiences, which is why this stayed a variable.
* ⚠️ **Upgrading elsewhere needs one setting.** The hidden page title is a per-page Kadence setting, not something the plugin does. On any site where `/portal/` still shows its page title, set that page's title to hidden — otherwise this version shows the old duplicate with a new name.

= 1.20.1 =
* **The player is roughly twice as wide.** Moving it beside the title in 1.20.0 shrank it to 17rem, which traded away the thing it is for: the scrubber is the control's width minus its buttons, so width *is* seeking resolution. On a six-minute movement 17rem is about 1.4 seconds per pixel — finding one phrase is a guess. It now runs from 24rem on a small laptop up to 38rem on a wide screen, around 600px at 1440, and the drag lands where you aim it.
* It stacks under the title below 900px rather than 680px. A control this size beside a title would break the title into three-word lines, and a stacked player is **wider still** — nearly 800px on a 880px window — so nothing is given up by the change.
* Row height is unchanged where the player sits beside the title.

= 1.20.0 =
* **The player can be scrubbed.** A recording used to start, run to the end of whatever the browser had buffered, and stop — and the progress bar could not be dragged at all. The endpoint was answering every request with the whole file from byte zero, and a browser that cannot ask for "the bytes around 4:12" cannot seek to 4:12. It now serves byte ranges, so the bar drags and a movement can be re-heard from the middle without reloading it.
* The audio is **cached on the server the first time it is played**, outside the web root where it cannot be reached by guessing a URL. Twenty seeks within one movement now cost one fetch from Drive instead of twenty — which is what makes scrubbing feel instant rather than merely possible.
* **The player moved to the right of the title**, into the whitespace that was sitting empty there, instead of taking a line of its own between the title and the buttons. A recording row drops from 144px to 110px — on a project of nineteen movements that is most of a screen of scrolling recovered. Rows with no recording are laid out exactly as before. On a phone, where there is no whitespace beside a title to reclaim, the player stays under it at full width.
* **The tag filter's All and None buttons are gone.** With content type no longer a tag (1.19.0) the chips are voice parts, and "All" was the state the page already arrives in. The chips themselves are unchanged: tick nothing and you are where None used to put you. The **Select all / Select none** buttons above the .zip download are a different pair and are untouched.

= 1.19.0 =
* **The tag filter narrows again.** Ticking Audio and Tenor together used to leave every recording on the page. The content type was being added to each material's tags behind the scenes, so "Audio" sat on all nineteen rows of a project and matched all of them — and because the filter shows anything matching any ticked tag, the broadest choice won and the narrow ones did nothing.
* Content type is no longer a tag. Ticking **Tenor** now hides the other three parts and keeps everything that belongs to nobody in particular — the movements, the score. Ticking Tenor and Bass shows both. A material with no tags on it stays visible whatever is ticked, which is what "everyone's material" should mean.
* **Content types are now collapsible sections instead.** A piece holding both a score and rehearsal tracks shows them under Sheet Music and Audio headings, with a count, either of which can be folded away. Scores come first.
* Those sections sit **inside** a piece, so a work and everything belonging to it stay together — a score is never in one place and its recordings in another. A piece holding only one kind of thing is left exactly as it was, with no heading it does not need.
* Sections start open. Nothing is hidden from a singer by default.

= 1.18.0 =
* **Rehearsal recordings play on the page.** Every recording now has a small player in its row, so working through eleven movements no longer means eleven trips to a new browser tab and back. Open and Download are still there beside it.
* Nothing is fetched until you press play. Opening a project with nineteen tracks does not quietly download nineteen files for music nobody asked to hear; the browser then streams a track as it arrives, so it starts almost immediately rather than waiting for the whole file, and remembers it so a second listen is instant.
* This reverses part of 1.14.0, which stripped inline previews from the materials list. That change was about previews being page-sized embeds that turned twelve materials into several screens of scrolling. A small audio control is not that, and the list stays a list.
* Scores and web links are unaffected: a player appears only where there is actually audio to play.

= 1.17.0 =
* **Sheet-music links no longer expire — and this fixes a real failure, not a theoretical one.** Clicking Open on a score returned a page of raw XML reading "the provided token has expired". The mirror signs each file's address for fifteen minutes; the page held that address in the link and then sat open in a tab while the clock ran. A singer opens the Hub, reads the rehearsal note, makes a cup of tea, clicks — that is the ordinary way anyone uses a web page, and it failed.
* The link on each score is now an ordinary link on this site that never goes stale. Bookmark it, email it, come back tomorrow. The mirror's short-lived address is fetched at the moment of the click and never appears in the page at all.
* **Access is now checked when a link is used rather than when the page was drawn.** The old links were, in effect, a key anyone holding them could use for fifteen minutes whether or not they were signed in. Every click is now checked against the same permissions the page itself uses — not a second copy of the rules that could drift from the first.
* **Sheet music can be downloaded again.** Because the link lives on this site, scores are recognised as files rather than as web links: the Download button is back, and so are the tick-boxes that put them in a bulk .zip along with everything else on the project. The small dash whose tooltip described a singer's own sheet music as "a link, not a file" is gone with them.
* If the mirror cannot be reached, a singer now gets a plain sentence saying so and that nothing has been removed, rather than a screen of XML.

= 1.16.0 =
* **The sheet-music mirror can be configured without opening wp-admin.** Every step needed to make published music appear was reachable only by a human at the WordPress admin screens, because the project post type is not exposed over REST and the mirror address is not a registered meta key - so the generic tools failed, and failed by reporting that the project did not exist. Three routes now cover it: the worker URL and token, one project's mirror address, and the whole mapping in a single call.
* **The token can be written and can never be read back.** A read reports whether one is set, whether it came from a wp-config constant or the database, and a short fingerprint of it - enough to confirm two sites are using the same token, useless to anyone who intercepts the response. A worker URL that is not https is refused outright, because a bearer token travels over it, and an empty token is refused rather than silently stored - clearing one is a separate, deliberate flag.
* Writes on the live site require an explicit confirmation flag, using the same production test the rest of the plugin already uses rather than a second copy of it that could drift.
* Reading a project's mirror address also returns every address its groups actually hold, so a wrong address hands back the right one instead of an empty list. That is the difference between "this is broken" and "you want chamber-singers/26-27 CS".
* Changing the worker URL or token now clears the cached answers immediately. Without that, ten minutes of results fetched with the old token look exactly like a fix that did not work, which is how a correct fix gets undone.

= 1.15.1 =
* **Fixes 1.15.0, which could not find anything.** The mirror stores two coordinates - published objects live at `scores/<group>/<project>/<file>` - and 1.15.0 could only be told about one of them. It derived the group from the WordPress group slug, which is wrong by construction: the group id is free text handed to the sync worker when a folder is first scanned, and the worker compares it exactly. Chamber Singers is `cs` in WordPress and `chamber-singers` in the mirror, so every lookup returned nothing, logged nothing and showed nothing.
* One field on the project now carries the whole address. Enter `chamber-singers/26-27 CS` to name both halves; enter `26-27 CS` to name only the project and let the group come from the project's own groups; leave it empty and the project title is used, which is a guess and should be expected to be wrong more often than right. The two systems were named by different people for different reasons and nothing obliges them to agree.
* A second, latent bug went with it: the group was being run through WordPress's slugifier before being sent. A group scanned as "Full Group" would have been asked for as "full-group" and matched nothing, in exactly the same silence, the first time anyone published for an ensemble other than Chamber. The group is now sent verbatim; only characters that would break the URL path or let a value escape its own folder are removed.
* **Singers Portal -> Sheet-Music Mirror** now prints the actual `group/project` strings the worker holds, with a count for each, so setting up a project is copy-and-paste rather than spelling from memory. It asks about every mirror folder already named on a project as well as every WordPress group. When every name comes back empty it says so and explains what that means, because a column of zeroes on its own is not a diagnosis.
* The screen is also honest about its limit: the worker has no endpoint that lists its groups, so a folder nobody has named in WordPress cannot appear in that table.

= 1.15.0 =
* **Published sheet music reaches singers.** The device-sync worker has been publishing scores into the private mirror under frozen filenames since Phase 1, and until now not one singer could reach them. Scores published for a project's groups now appear in that project's materials list alongside the hand-entered rows, under the same piece headings, with the same Open and Download buttons.
* Scores are merged at render time and are never written into the project's materials. The mirror stays the single source of truth: there is no second copy to drift, nothing to re-sync, and removing a score from the mirror removes it from the page without an admin touching anything.
* **It fails open.** Unconfigured, unreachable, slow, or a malformed response - every path returns the materials list exactly as it arrived. Nothing this feature does can remove music a singer already had, which matters when the page is being opened in a rehearsal room.
* The worker token never reaches the browser. All calls are made server-side and what lands on the page is a short-lived signed URL per score, which carries no credential. The token is read from a wp-config constant first, so on a properly configured site it need not be in the database at all.
* Scores match a project by name, compared case-insensitively with whitespace collapsed, and can be overridden per project by a field on the project edit screen for the cases where the folder and the project were never named the same thing. A project with an empty key matches nothing on purpose - showing a singer no mirror scores is always better than showing them another project's music.
* Group gating is unchanged. These rows carry no groups of their own; they were already scoped by the project's groups before they arrived, and the new filter is documented as never widening access. A work published to two groups a singer holds appears once.
* **Singers Portal -> Sheet-Music Mirror** holds the worker URL and token, and runs a live connection check that reports, per group, how many scores the worker returns and which project names it saw. "Saved" tells an admin nothing about whether singers will see anything; this tells them.

= 1.14.0 =
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

= 1.14.0 =
Materials no longer preview inline. Each one is a row with Open and Download, plus checkboxes and a "Download selected (.zip)" button. Downloads are fetched through the site with the Google service-account token, so they no longer depend on each singer's own Drive access. Requires the Ars Nova Google Connector to be active for Drive-hosted materials. Materials can also be grouped under a piece: set the Piece field on a material and the singer-facing list gains a heading per piece. Nothing changes for a project until someone fills that field in, and the field never affects who can see what. Note for anyone calling the REST materials endpoint with replace=true: echo the piece back with each row, or it is cleared — the same is already true of note, tags and groups.

= 1.2.0 =
Per-material permissions are replaced by unlimited free-form tags plus a singer-side "Filter by tag" control. Nothing is gated at the material level any more — every portal member sees every material in a project they can access. Old permission data is ignored; no migration needed.

= 1.1.0 =
The Portal now absorbs the "Ars Nova Singer Directory" plugin (same slugs and meta keys — no migration) and removes its own duplicate profile fields. After updating, deactivate the old Directory plugin. Adds voice-part material permissions and a Gemini "Compose with AI" bio button.

= 1.0.0 =
Initial release.
