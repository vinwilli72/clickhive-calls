=== Click Hive Calls ===
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 1.4.0

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
