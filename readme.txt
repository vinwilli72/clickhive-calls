=== Click Hive Calls ===
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 1.7.0

Call inbox for phone systems managed by Click Hive Marketing.

== What it does ==

Every call to this site's business number appears in the Call Inbox when it ends:
answered, voicemail, missed, or asked for a text. Staff press Play to hear a
voicemail, call the person back from the number, and set a follow-up status.

* Voicemail audio stays with the phone provider. When someone presses Play, the
  site checks they are signed in and allowed, fetches the audio, and plays it.
  No playable link is ever put in the page or in email, and every play is logged.
* Calls can only be added by the Click Hive phone system, signed with this
  site's key, and only for the numbers listed in Settings.
* Wording is editable: inbox title, status choices, and an optional category.
* Line Settings (under the inbox menu): the business chooses who answers (Caller 1
  and an optional Caller 2) and sets vacation dates, one tab per Business number.
  Changes are saved straight to the phone system and used on the next call. Hours
  and holidays are shown here and changed by Click Hive. Administrators and the
  Client role from Click Hive Utility can use it; Call Managers can't.

== Updates ==

New versions are published as GitHub releases. WordPress checks about twice a day; to check right away, go to
Dashboard > Updates and click "Check again".

== Setup ==

1. Upload and activate the plugin.
2. Settings > Click Hive Calls: paste the site key from Click Hive and list this
   site's business number(s). Save.
3. Users > Add New: give front-desk staff the "Call Manager" role. They can see
   and work the inbox and nothing else in the admin. Each person needs their own login.
4. For practices that take patient calls, turn on Minimum-PHI mode and install a
   two-factor login plugin (for example, Two Factor) for Call Managers.

== Notes ==

* Call records are kept after deactivation. Older records are removed after the
  number of days set in Settings (default 365).
* Requires pretty permalinks (any setting except "Plain") so /wp-json/ works.

== Changelog ==

= 1.7.0 =
* New Line Settings page: change Caller 1 and Caller 2 and set vacation dates for each business number, without contacting Click Hive. Business hours and holidays are shown read-only. Needs the phone system's /line-settings service (clickhive-voice, 2026-11-12 or later).

= 1.6.1 =
* Users with the Client role from Click Hive Utility can open the call inbox, play voicemails and set call status. Deleting calls and the settings page stay with administrators.

= 1.6.0 =
* The phone system's health check uses its own signed ping, which answers with a normal success. Before, each check produced an error response, and SiteGround's anti-bot could start challenging the phone system's requests.

= 1.5.0 =
* Call details only say "Rang" when a phone actually rang. Calls where the caller never chose an option that rings say so.
* Administrators can delete a call from its details (for test calls). The recording stays with the phone provider.

= 1.4.0 =
* A caller who asks for a text and then leaves a voicemail shows as a Voicemail tagged "Text sent", so nobody texts them twice.

= 1.3.2 =
* Click Hive icon in the admin menu and on the Updates screen.

= 1.3.1 =
* Call Inbox and Settings links on the Plugins screen.

= 1.3.0 =
* Automatic updates: new versions appear under Plugins with "Update now". Settings and calls are kept.

= 1.2.0 =
* Quick tabs with counts: All, Needs follow-up, Unheard voicemails, Voicemails, Missed.
* Unheard voicemails are marked until someone plays them.
* Sort by Received (newest or oldest first) and by Length.
* Search calls by phone number.

= 1.1.0 =
* Call details: click Details on any call to see the line called, business hours,
  the numbers it rang, who answered, where the notice was emailed, and history.
* Sites with more than one number get a Line column and a line filter.
* Version shown on the settings page.

= 1.0.0 =
* First release: call inbox, secure voicemail playback, editable statuses and categories.
