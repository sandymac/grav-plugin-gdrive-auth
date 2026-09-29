## Troubleshooting

Find the error code the **Test** button (or the plugin's log) showed, then
follow its fix. Each entry starts with the code exactly as it appears.

| Code | In short |
|---|---|
| [`storageQuotaExceeded`](#storage-quota-exceeded) | A service account tried to write outside a Shared Drive. |
| [`notFound`](#not-found) | The folder isn't shared with the account, or the ID is wrong. |
| [`accessNotConfigured`](#access-not-configured) | The Google Drive API isn't turned on in the project. |
| [`redirect_uri_mismatch`](#redirect-uri-mismatch) | The client's redirect URI doesn't match this site. |
| [`invalid_grant`](#invalid-grant) | The connection expired or was revoked. Reconnect (and publish the app). |
| [`scope_not_granted`](#scope-not-granted) | A plugin needs a permission you haven't approved. Reconnect. |
| [`insufficientPermissions`](#insufficient-permissions) | The account can see the file but isn't allowed to change it. |
| [`not_connected`](#not-connected) | The OAuth account was never connected. Click Connect. |
| [`rateLimitExceeded`](#rate-limit-exceeded) | Google asked the site to slow down. |
| [Key creation is blocked](#org-policy-key-creation) | An organisation policy stops you creating a service-account key. |
| [`bad_credential`](#bad-credential) | The uploaded file isn't the kind of file expected. |
| [`bad_state`](#bad-state) | The Connect window was stale or used twice. |
| [`unknown_account`](#unknown-account) | A plugin names an account that doesn't exist here. |
| [`access_denied`](#access-denied) | Connect was cancelled on Google's screen. |
| [`invalid_client`](#invalid-client) | Google doesn't recognise the OAuth client or its secret. |
| [`admin_policy_enforced`](#admin-policy-enforced) | A Workspace administrator blocks the app. |
| [`PERMISSION_DENIED`](#permission-denied) | Google refused, for a reason given in the message. |
| [`transport`](#transport) | The site couldn't reach Google at all. |
| [`io`](#io) | The site couldn't write its own files. |
| [`backendError`](#backend-error) | A temporary problem on Google's side. |
| [Anything else](#other) | How to find out more. |

---

<a id="storage-quota-exceeded"></a>

### `storageQuotaExceeded`: the service account has no storage

**Cause.** A service account tried to create a file in a normal My Drive folder
(yours, shared with it). Service accounts have no Drive storage of their own, so
anything they create outside a Shared Drive is refused. It's also the error when
an OAuth account's own Drive is full.

**Fix.**

- For a service account: give it a **Shared Drive** instead. Add its email as
  **Content manager** of the Shared Drive and point the plugin at the Shared
  Drive or a folder inside it. See step 6 of the
  [Service account guide](service-account.md). No Shared Drive (a personal Gmail
  account)? Use an OAuth account ([OAuth guide](oauth.md)).
- For an OAuth account: free up space, or buy more, at
  [Google One storage](https://one.google.com/storage).

<a id="not-found"></a>

### `notFound`: the file or folder isn't visible to the account

**Cause.** Drive says "File not found" both when an ID is wrong and when the
account simply can't see the file. Almost always it's the second: the folder
hasn't been shared with the service account, or an OAuth account using
`drive.file` can only see files it created itself.

**Fix.**

- Service account: share the folder (Viewer) or the Shared Drive (Content
  manager) with the service account's email: {{sa_emails}}
- Check the folder ID: it's the last part of the folder's address in Drive,
  after `/folders/`.
- OAuth with `drive.file`: that scope can't see folders you made yourself in
  Drive. Let the plugin create its folder, or use a plugin setting that needs
  `drive` / `drive.readonly` and **Reconnect**.

<a id="access-not-configured"></a>
<a id="service-disabled"></a>

### `accessNotConfigured`: the Drive API is off

**Cause.** The Google Drive API isn't enabled in the Google Cloud project the
credential belongs to. Google's message names the project number.

**Fix.** Open the [Google Drive API page](https://console.cloud.google.com/apis/library/drive.googleapis.com),
make sure the right project is selected at the top (the number in the error
message identifies it, or add `?project=<number>` to the address), and click
**Enable**. Wait a minute, then click **Test** again.

<a id="redirect-uri-mismatch"></a>

### `redirect_uri_mismatch`: Google refused the redirect URI

**Cause.** Google shows "Error 400: redirect_uri_mismatch" in the Connect window
when the address the site asked it to return to isn't listed on the OAuth
client. This site's address is:

```
{{redirect_uri}}
```

**Fix.** Open [Clients](https://console.cloud.google.com/auth/clients), click
the client, and under **Authorized redirect URIs** add that exact address
(same `https://`, same `www.` or not, no trailing slash). Click **Save**, wait a
minute or two for Google to pick it up, and click **Connect** again.

If the address above starts with `http://` or shows the wrong host name, the
site doesn't know its public address (common behind a proxy or CDN). Fix Grav's
URL settings first (`system.custom_base_url`, or your proxy's
`X-Forwarded-Proto` header). Google only accepts `https://` here, except for
`localhost`.

<a id="invalid-grant"></a>

### `invalid_grant`: the connection expired or was revoked

**Cause.** Google refused the stored connection. The usual reasons:

1. **The app is still in Testing** (External audience). Google expires Testing
   connections after **7 days**.
2. Access was revoked, in [Google Account → Third-party connections](https://myaccount.google.com/connections)
   or by removing the client.
3. The connection wasn't used for about **six months**.
4. The OAuth client's secret was reset or deleted.

For a service account the same code means the key was deleted or disabled, or
the server's clock is far off.

**Fix.** On the [Audience page](https://console.cloud.google.com/auth/audience),
click **Publish app** so it reads **In production** (or make it **Internal** on
Workspace). Then click **Reconnect** on the **Accounts** tab. For a service
account, create a new key and upload it, and check that the server's clock is
right.

<a id="scope-not-granted"></a>

### `scope_not_granted`: a permission hasn't been approved

**Cause.** A plugin needs a scope the OAuth account wasn't granted. Either the
plugin was installed after you connected, or a box was left unticked on
Google's consent screen.

**Fix.** Make sure the scope is on the [Data Access page](https://console.cloud.google.com/auth/scopes)
(see step 4 of the [OAuth guide](oauth.md)), then click **Reconnect** and tick
every box. The **Accounts** tab highlights the scopes that are still missing.

<a id="insufficient-permissions"></a>
<a id="insufficient-file-permissions"></a>

### `insufficientPermissions` / `insufficientFilePermissions`: allowed to see, not to change

**Cause.** The account can see the file or folder but its sharing role doesn't
allow the change (for example a backup plugin writing to a folder shared as
**Viewer**). Also shown when the access token lacks the scope for the call.

**Fix.** Share it with a stronger role: **Content manager** on a Shared Drive,
or **Editor** on a folder. For OAuth accounts, **Reconnect** so the latest
scopes are granted.

<a id="not-connected"></a>

### `not_connected`: nothing to sign in with yet

**Cause.** An OAuth account has its client uploaded but was never connected (or
was disconnected), or an account has no key or client file yet.

**Fix.** On the **Accounts** tab, upload the file if it's missing, then click
**Connect** for OAuth accounts.

<a id="rate-limit-exceeded"></a>
<a id="user-rate-limit-exceeded"></a>

### `rateLimitExceeded` / `userRateLimitExceeded`: too many requests

**Cause.** Google limits how fast one project and one user may call Drive.

**Fix.** Nothing, usually: the site already waits and retries automatically. If
it keeps happening, make the plugin sync less often, or check for another
program using the same Google project. Quotas are listed on the
[Drive API quotas page](https://console.cloud.google.com/apis/api/drive.googleapis.com/quotas).

<a id="org-policy-key-creation"></a>

### Key creation is blocked by an organisation policy

**Cause.** Creating a key shows "Service account key creation is disabled" and
names the policy `iam.disableServiceAccountKeyCreation` (or
`iam.managed.disableServiceAccountKeyCreation`). Google Workspace and Cloud
organisations created since 2024 enforce it by default.

**Fix.** Someone with the **Organization Policy Administrator** role (usually
the Workspace super administrator) allows keys for just this project:

1. Select **this project** in the picker at the top of the console.
2. Open [IAM & Admin → Organization policies](https://console.cloud.google.com/iam-admin/orgpolicies).
3. Search for `disableServiceAccountKeyCreation` and open the policy. If both
   the legacy and the managed ("iam.managed.…") versions are listed, do this
   for each one that's enforced.
4. Click **Manage policy**, choose **Override parent's policy**, add a rule with
   **Enforcement: Off**, and click **Set policy**.
5. Wait a minute, then create the key again (step 4 of the
   [Service account guide](service-account.md)).

Project owners can't do this themselves: the role has to be granted at the
organisation. If the organisation won't allow keys, use an
[OAuth account](oauth.md) instead.

<a id="bad-credential"></a>

### `bad_credential`: not the file expected

**Cause.** The uploaded file isn't what the account type needs. The message says
which kind was expected. Common mix-ups:

- an **OAuth client** file uploaded as a service account, or a service-account
  key uploaded as OAuth: pick the other type, or the other file;
- a **Desktop app** OAuth client: create a **Web application** client instead
  (step 5 of the [OAuth guide](oauth.md));
- a file that was edited or re-saved: upload the file Google gave you, unchanged;
- an account name with capitals or spaces: use lowercase letters, digits, `-`
  and `_`.

**Fix.** Upload the right file under the right type on the **Accounts** tab.

<a id="bad-state"></a>
<a id="consent"></a>

### `bad_state`: the Connect window was stale

**Cause.** The Google window came back more than 10 minutes after **Connect**
was clicked, was used twice (a refresh or the back button), or Google returned
an error instead of a sign-in. The window only says "could not be connected";
the site's log has the reason.

**Fix.** Close the window and click **Connect** again, and finish within 10
minutes.

<a id="unknown-account"></a>

### `unknown_account`: no account by that name

**Cause.** A plugin is set to use an account name that doesn't exist here, often
the default `site` before any account has been added.

**Fix.** Add an account with that name on the **Accounts** tab, or change the
plugin's account setting to one that exists. **Who uses what** on the
**Start here** tab lists what each plugin expects.

<a id="access-denied"></a>

### `access_denied`: Connect was cancelled

**Cause.** **Cancel** was clicked on Google's screen, or the account isn't a
**Test user** of an app still in Testing.

**Fix.** Click **Connect** again and approve. If the app is in Testing, either
publish it (recommended; see [`invalid_grant`](#invalid-grant)) or add your
account under **Test users** on the [Audience page](https://console.cloud.google.com/auth/audience).

<a id="invalid-client"></a>
<a id="unauthorized-client"></a>
<a id="deleted-client"></a>

### `invalid_client` / `unauthorized_client`: the client isn't recognised

**Cause.** The OAuth client was deleted, its secret was reset, or the uploaded
file is from another project.

**Fix.** Check the client still exists under [Clients](https://console.cloud.google.com/auth/clients).
Download its JSON again (add a new secret if needed), upload it on the
**Accounts** tab under the same name, and click **Connect**.

<a id="admin-policy-enforced"></a>
<a id="org-internal"></a>

### `admin_policy_enforced` / `org_internal`: blocked by Workspace settings

**Cause.** `admin_policy_enforced`: the Workspace administrator restricts which
third-party apps may access Drive. `org_internal`: the app's audience is
**Internal** but you signed in with an account outside the organisation.

**Fix.** For the first, the Workspace administrator marks the app as trusted in
the [Admin console](https://admin.google.com/) under **Security → Access and
data control → API controls → Manage third-party app access** (search by the
client ID). For the second, sign in with an account in the organisation, or
change the audience to **External** and publish it.

<a id="permission-denied"></a>
<a id="forbidden"></a>
<a id="unauthenticated"></a>
<a id="auth-error"></a>

### `PERMISSION_DENIED` / `forbidden` / `UNAUTHENTICATED` / `authError`

**Cause.** Google refused the call and the message says why: often the API is
disabled ([`accessNotConfigured`](#access-not-configured)), the file isn't
shared ([`notFound`](#not-found)), or a token was revoked
([`invalid_grant`](#invalid-grant)).

**Fix.** Read the message on the **Accounts** tab and follow the entry it points
to. Click **Test** afterwards.

<a id="transport"></a>

### `transport`: Google couldn't be reached

**Cause.** The server couldn't connect to Google at all: DNS, a firewall that
blocks outgoing HTTPS, an outdated CA certificate bundle, or a timeout.

**Fix.** Check that the server can reach `https://oauth2.googleapis.com` and
`https://www.googleapis.com` (for example `curl -I https://www.googleapis.com`
from the server). Some hosts block outgoing connections by default; ask yours to
allow them.

<a id="io"></a>

### `io`: the site couldn't write its own files

**Cause.** The web server can't write to `user/data/gdrive/` (credentials) or
`user/config/plugins/gdrive.yaml` (the account list).

**Fix.** Make both writable by the web server's user, the same way the rest of
`user/` is.

<a id="backend-error"></a>
<a id="internal-error"></a>

### `backendError` / `internalError`: a problem at Google

**Cause.** A temporary failure on Google's side.

**Fix.** The site already retries. Try again in a few minutes, and check the
[Google Workspace status page](https://www.google.com/appsstatus/dashboard/) if
it persists.

<a id="other"></a>

### Anything else

The full error is in the message next to the account, and in Grav's log
(`logs/grav.log`, lines starting with `gdrive:`). Google's own list of Drive
errors is at [Resolve errors](https://developers.google.com/workspace/drive/api/guides/handle-errors).
If a plugin shows an error with no code, click **Test** on the account it uses
first. That tells you whether the account itself works.
