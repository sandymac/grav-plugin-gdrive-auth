## Start here

This plugin connects your Grav site to Google Drive for other plugins, such as
galleries that show photos from a Drive folder and backups that upload to Drive.
You set up one or more **accounts** here, and each of those plugins picks one.

There are two kinds of account. Pick one with this table, follow its guide, and
then add the account on the **Accounts** tab.

|  | Service account | OAuth (your Google account) |
|---|---|---|
| **Works with** | Google Workspace, with a Shared Drive or a folder shared with it | Any Gmail or Workspace account |
| **Who owns the files it creates** | The Shared Drive | You, and they count against your storage (15 GB free) |
| **Keeps working unattended** | Yes, it never expires | Yes, once the Google client is **In production** (or **Internal**) |
| **Can upload into My Drive** | **No**: service accounts have no storage | Yes |
| **Can read a folder you share with it** | Yes | Yes |
| **Setup time** | About 5 minutes, plus sharing a folder | About 10 minutes, plus clicking **Connect** |
| **Guide** | [Service account guide](service-account.md) | [OAuth guide](oauth.md) |

**If you have a Shared Drive, use a service account. Otherwise use OAuth.**

A few things that are true whichever kind you pick:

- **You bring your own Google Cloud project.** Nothing goes through a third
  party. Your site talks straight to Google with credentials you create. Both
  guides start by creating a project (or reusing one) and turning on the Google
  Drive API in it. Setup is free, and so is normal Drive API use.
- **Credentials never go in config files.** The key or client file you upload is
  checked, then stored in `user/data/gdrive/` with owner-only permissions. The
  settings page never shows it again, only the account's email and status.
- **Make sure your web server refuses `user/data/`.** Grav's standard
  `.htaccess` and nginx configs already do. If yours are custom, check that
  `https://{{site}}/user/data/` gives a 403 or 404.
- **Plugins declare what they need.** Each plugin that uses Google Drive says
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
