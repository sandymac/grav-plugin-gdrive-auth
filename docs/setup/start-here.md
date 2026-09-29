## Start here

This plugin connects your Grav site to Google Drive for other plugins built on
it, such as galleries that show photos from a Drive folder and backups that upload to Drive.
You set up one or more **accounts** here, and each of those plugins picks one.

There are two kinds of account. Pick one with this table, follow its guide, and
then add the account on the **Accounts** tab.

|  | OAuth (your Google account) | Service account |
|---|---|---|
| **Works with** | Any Gmail or Workspace account | Google Workspace, with a Shared Drive or a folder shared with it |
| **Who owns the files it creates** | You, and they count against your storage (15 GB free) | The Shared Drive |
| **Keeps working unattended** | Yes, once the Google client is **In production** (or **Internal**) | Yes, it never expires |
| **Can upload into My Drive** | Yes | **No**: service accounts have no storage |
| **Can read a folder you share with it** | Only with `drive` or `drive.readonly` access. With `drive.file` (what an empty backup folder uses), it sees only files it created. | Yes |
| **Setup time** | About 10 minutes, plus clicking **Connect** | About 5 minutes, plus sharing a folder |
| **Guide** | [OAuth guide](oauth.md) | [Service account guide](service-account.md) |

**Most people: use OAuth** with your Google account. A service account is an
alternative for Google Workspace sites with a Shared Drive that want a
credential no person owns.

A few things that are true whichever kind you pick:

- **You bring your own Google Cloud project.** Nothing goes through a third
  party. Your site talks straight to Google with credentials you create. Both
  guides start by creating a project (or reusing one) and turning on the Google
  Drive API in it. Setup is free, and so is normal Drive API use.
- **Credentials never go in config files.** The key or client file you upload is
  checked, then stored in `user/data/gdrive/auth/` with owner-only permissions. The
  settings page never shows it again, only the account's email and status.
  Grav's own backups include `user/data/gdrive/auth/`, so treat backup zips like
  passwords, or exclude that folder in Configuration → Backups (you'll then
  reconnect after a restore).
- **Make sure your web server refuses `user/data/`.** Grav's standard
  `.htaccess` and nginx configs already do. If yours are custom, check that
  `https://{{site}}/user/data/` gives a 403 or 404.
- **Plugins declare what they need.** Each plugin built on Google Drive Auth says
  which account it uses and which permissions ("scopes") it needs. The
  **Who uses what** table below shows them. For OAuth accounts, **Connect**
  asks Google for all of them at once.
- **When something goes wrong,** the **Test** button on the **Accounts** tab
  links straight to the right entry in [Troubleshooting](troubleshooting.md).

### Names used in the guides

- **Your site:** `{{site}}`
- **OAuth redirect URI** (only OAuth accounts use it):

  ```
  {{redirect_uri}}
  ```

- **Service-account email(s):** {{sa_emails}}
- **Scopes your installed plugins need:**

{{scopes}}
