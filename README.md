# AI Mail Cleaner for Webuzo

**Powered by [Digitalbkk Co., Ltd.](https://www.digitalbkk.com) — developed by the Digitalbkk team. Author: Hasif Ahmed <hasif5@gmail.com>**

A free, open-source Webuzo v4 plugin that finds spam and phishing in your mailboxes, and helps you file everything else into the right folder. It uses SpamAssassin plus AI (Google Gemini, with Anthropic Claude as an optional second opinion).

- **Nothing is ever deleted.** Spam goes to a folder of its own, and every move can be undone.
- **You decide.** A check builds a list. Nothing moves until you press **Apply**, unless you switch on automatic moving.
- **It files mail too.** The AI can suggest which of your existing folders each message belongs in, or propose a new folder.
- **It lives inside the panel.** It runs in the Webuzo frame with the panel's own styling: users go to **Email → AI Mail Cleaner**, the server owner to the **admin panel → AI Mail Cleaner**.

---

## How it works

1. **Check.** The owner presses **Check now**. Every new message is scored by SpamAssassin (the score already in its headers, or `spamc` when there is none).
   - At or above *certainly spam* (default 8): marked as spam, no AI call.
   - Below *unsure* (default 5): looks fine, no AI call.
   - In between: Gemini reads a short digest of the message and decides. Optionally, Claude gives a second opinion when Gemini is unsure, says phishing, or fails.
2. **Review.** Messages are shown in four tabs: **Spam · Unsure · Looks fine · Done**. Each message has one choice: **Spam folder**, **Leave where it is**, or **one of your folders**. Spam is pre-selected, and "Set all to…" changes a whole tab at once.
3. **AI tools (optional).**
   - **Suggest folders:** the AI picks an existing folder for each message that is not going to spam (for example a Microsoft notice into `Microsoft`, an AWS support case into `Amazon Web Services/AWS Support`), or proposes a new folder. Its picks are pre-selected for you to confirm. A new folder is only created when you press Apply.
   - **Check again:** asks the AI again about the spam verdicts, for example after adding a key.
4. **Apply.** Messages are moved with `doveadm`, so webmail and mail apps see the change at once. Or press **Close without changes**.
5. **Undo.** Use **Put back** on one message, or **Undo all** for a whole check.

Safety rules:
- Sent, Drafts, Trash, Junk/Spam, the Inbox itself and the spam folder are never offered as filing targets.
- If SpamAssassin was sure a message is spam but the AI says it is fine, the message goes to **Unsure** for you to decide.
- With two AI answers, a message is only marked as spam if **both** agree.

### What the AI sees

Only a digest of each message: From / Reply-To / Return-Path / To / Subject / Date, the authentication results, whether a List-Unsubscribe header exists, the links, attachment **names**, and the first 1,500 characters of the text (adjustable). Whole messages and attachments never leave the server.

Email content is passed to the AI as untrusted data. Text inside an email that tries to instruct the classifier is treated as a spam signal. Gemini requests are sent with `store: false`.

Both APIs are called with plain HTTPS from PHP's curl. No SDKs or other third-party code ship with the plugin.

### Automatic mode

Each mailbox can use one of three modes:
- **Off**
- **List it for me:** checks new mail every N minutes, with an optional summary email.
- **Move automatically:** confident spam moves without asking. Every run can still be undone.

Automatic checks only look at mail that arrives after they are switched on.

---

## Requirements

- Webuzo v4 with Dovecot (`doveadm`) and the bundled EMPS PHP (curl, pdo_sqlite)
- `spamc` (SpamAssassin), for messages without a SpamAssassin header
- A **Google Gemini API key** (required). Use a key from a Google Cloud project **with billing enabled**: on the free tier Google may use what you send to improve its products.
- An **Anthropic API key** (optional, for the second opinion)

Cost: about $0.002 per message the AI reads with `gemini-3.8-flash`. Most mail never reaches the AI because SpamAssassin is already sure about it. The admin page has a monthly spending cap per provider and a usage table.

**IP-restricted keys:** the plugin always calls the AI providers over **IPv4**. If your key has an IP allow-list, add the server's IPv4 address.

## Install

Download the latest release from the [Releases page](https://github.com/hasif5/AIMailCleaner-webuzo/releases), then:

```bash
tar xzf mailcleaner-1.0.0.tar.gz
sudo ./mailcleaner/install.sh
```

Or install straight from git:

```bash
sudo git clone https://github.com/hasif5/AIMailCleaner-webuzo.git /usr/local/mailcleaner
sudo /usr/local/mailcleaner/install.sh
```

Then:

1. Open the **admin panel → AI Mail Cleaner**. Paste the Gemini key (and the Claude key if you want the second opinion), save, and press **Test Gemini**.
2. In the **user panel → Email → AI Mail Cleaner**, set up a mailbox and press **Check now**.

The installer:
- registers the plugin in Webuzo (`plugins.json`, the `mailcleaner` feature in every plan, and two nginx location drop-ins);
- creates the root-only state directory `/var/lib/mailcleaner`;
- adds a cron entry (`/etc/cron.d/mailcleaner`) and log rotation.

It uses only Webuzo's documented extension points and never edits Webuzo's own files.

```bash
/usr/local/mailcleaner/bin/check.sh             # health check
/usr/local/mailcleaner/install.sh --repair      # re-assert links, permissions, cron
/usr/local/mailcleaner/uninstall.sh [--purge]   # mail already moved is never touched
```

## Architecture

| Part | Path |
|---|---|
| Code | `/usr/local/mailcleaner` |
| State (root-only, 0700) | `/var/lib/mailcleaner`: settings and API keys, per-mailbox settings and status, SQLite history, logs |
| Panel pages | `web/enduser/index.php` (users) and `web/admin/index.php` (root), on EMPS's root PHP-FPM pool (PHP 7.4), behind the Webuzo session SDK, with CSRF protection on every POST |
| Runner | `bin/runner.php`: cron every minute, starts one detached `bin/worker.php` per job (up to 3 mailboxes at once) |
| Mail access | `doveadm` only (`fetch`, `move`, `mailbox create`) as root with `-u <mailbox>`. Messages are tracked by Dovecot GUID, so undo is exact |
| AI | `lib/ai.php`: Gemini Interactions API and Anthropic Messages API, concurrent requests with retry/back-off, spend caps |

Access control:
- A hosting user only ever sees mailboxes on their own domains whose mail home is inside their own home directory. This is decided from Webuzo's account record and Dovecot's user database, never from the request.
- API keys are stored root-only, shown masked, and never sent to a browser.

## Contributing

Issues and pull requests are welcome. Plugin PHP must stay compatible with PHP 7.4 (the panel's EMPS PHP). No user-supplied string ever reaches a shell: every external command is run with an argv array.

## License

[Apache License 2.0](LICENSE). See [NOTICE](NOTICE).

Not affiliated with or endorsed by Webuzo/Softaculous, Google or Anthropic. All product names are trademarks of their owners.
