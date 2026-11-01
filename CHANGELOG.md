# Changelog

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
