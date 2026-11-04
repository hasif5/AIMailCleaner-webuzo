# Changelog

## 1.1.0 — 2026-11-04

- **Stop a check:** a **Stop and review what is done** button while a check, re-check or folder suggestion runs. Everything finished so far is kept and opens for review; the rest is left for the next check. A job that has not started yet is simply cancelled.
- **Know before you start:** the mailbox page shows how many messages have not been checked yet, and **Check now** has a "the newest [N] messages" box (default 500; empty = all).
- **Faster checks of old mail:** messages without a SpamAssassin header are scored three at a time (spamd keeps two workers free for incoming mail). About 2.5× faster on Webuzo's default spamd.
- **Fewer newsletters marked as spam:** the AI now treats newsletters, promotions and event invitations from real, identifiable companies as not spam (tagged "newsletter"). Spam means cold pitches from strangers, deception and junk with no real organisation behind it.
- **Choose the main AI:** the admin page has a **Which AI decides** section. Gemini stays required and is the default, but the server owner can switch the main AI to Claude (once a Claude key is saved) and back at any time. The other AI gives the second opinion and acts as fallback. If the Claude key is removed, Gemini takes over automatically.
- **Fix (important): clean mail was marked "certainly spam".** Exim, as used by Webuzo, writes `X-Spam-Score` as the score times ten (`$spam_score_int`), so a clean 2.3 was read as 23. The score now comes from `X-Spam-Status: score=`, and a bare integer `X-Spam-Score` is divided by ten. Checks made with 1.0.x on Exim servers over-flagged mail whose SpamAssassin score came from the headers.
- **One-click actions on every row:** **Spam · Keep · Archive**, the **AI's pick**, and **↻ folders already used in this check**. A folder box with autocomplete lets you type any folder, new or existing, and press Enter. A new folder name, once used, appears as a ↻ button on every row. **Archive** uses the mailbox's Archive folder, or creates one on Apply.
- The bulk bar has the same buttons: *Selected: Spam | Keep | Archive | AI's pick | [folder name] Move*.
- **Bulk changes on the review page** for checks with hundreds or thousands of messages:
  - A checkbox on every row, with shift-click to select a range, "Select all on this page", then **"Select all N matching"**.
  - **Set selected to:** Spam folder, Leave where it is, the AI's suggestion, any of your folders, or **+ New folder…** with a name you type (for example "Clients/Acme").
  - **Filters:** search by sender or subject, and filter by "Set to" or by what the AI suggested, each with counts. Click a sender to see only their mail. 100, 250 or 500 messages per page.
  - **Put back selected** in the Done tab, for ticked messages or every match.
- The message list scrolls inside its own box, so the selection bar and Apply stay within reach.
- Pages stay light with large checks: one shared folder list feeds every row's folder box.

## 1.0.1 — 2026-11-01

- Fix: a long-running check inherited the runner's lock. That blocked checks for other mailboxes until it finished, and made the health check report a stalled runner. Lock files are now opened close-on-exec.

## 1.0.0 — 2026-11-01

First public release.

- **Panel pages** for hosting users (Email → AI Mail Cleaner) and the server owner (admin panel), built into the Webuzo frame. They use the Webuzo session login, CSRF protection on every form, a root cron runner and detached workers.
- **Per-mailbox settings:** any mailbox on the user's own domains can be set up. Each has folders to check, a spam folder, SpamAssassin levels, an AI confidence bar, a never-move list and automatic mode.
- **AI:**
  - Google Gemini is the main AI, and its key is required (default `gemini-3.8-flash`).
  - Anthropic Claude is an optional second opinion (default `claude-haiku-5-5`, effort high).
  - Model and effort/thinking level are selectable. Keys are entered on the admin page.
  - There are monthly spend caps, a usage table and test buttons.
  - Calls always go out over IPv4, so keys with an IP allow-list work.
- **Review page:**
  - Each message has one choice: Spam folder, Leave where it is, or one of your folders.
  - Tabs: Spam · Unsure · Looks fine · Done. "Set all to" changes a whole tab.
  - One **Apply** button, with a plain-language summary of what it will do. Or **Close without changes**.
- **Suggest folders (AI):** the AI picks an existing folder for each message not going to spam, or proposes a new one (created only on Apply). Sent / Drafts / Trash / Junk / Spam, the Inbox and the spam folder are never offered.
- **Check again (AI):** re-asks the AI about a check's spam verdicts. If SpamAssassin was sure it is spam but the AI says fine, the message goes to Unsure.
- **Undo:** **Put back** one message, or **Undo all** for a check. Nothing is ever deleted.
- **Automatic mode** (list only / move), new mail only, with an optional summary email.
- **Mail handling:** all mail is accessed through doveadm. Moves are verified, and messages are tracked by Dovecot GUID.
