## OAuth guide

An OAuth account lets the site act as **you**, a normal Google account (a
personal Gmail account, or Workspace), with the permissions you approve on
Google's consent screen.
Files it creates are yours and live in your My Drive. This works for anyone,
with no Shared Drive needed.

You create your own small "app" in Google Cloud for this. Nothing goes through a
third party: the app is yours, and only your site uses it. It takes about ten
minutes.

<!-- only: gmail -->
> **Before you start: the 7-day trap.** A new Google app starts in **Testing**.
> For an **External** app in Testing, Google makes the connection expire after
> **7 days**, and the site then fails with
> [`invalid_grant`](troubleshooting.md#invalid-grant). Step 3 fixes this: click
> **Publish app** so the status is **In production**. Don't skip it.
<!-- /only -->

<!-- only: workspace -->
> **On Google Workspace?** Choose **Internal** in step 3: there's no Testing
> status, no 7-day expiry and no warning screen.
<!-- /only -->

### 1. Create or pick a Google Cloud project

1. Open the [Google Cloud console](https://console.cloud.google.com/) and sign
   in with the Google account that should own the app. It doesn't have to be the
   account whose Drive you connect.
2. Use the project picker at the top. Pick an existing project, or click
   **New project** ([direct link](https://console.cloud.google.com/projectcreate)),
   name it (for example "My website") and click **Create**.
3. Make sure the project is selected in the picker before you go on.

### 2. Turn on the Google Drive API

1. Open the [Google Drive API page](https://console.cloud.google.com/apis/library/drive.googleapis.com).
2. Click **Enable**. If it says **Manage**, it's already on.

### 3. Set up Google Auth Platform (the consent screen)

"Google Auth Platform" is the part of the console that used to be called the
"OAuth consent screen".

1. Open [Google Auth Platform](https://console.cloud.google.com/auth/overview).
   If the project has never used it, click **Get started**:
   - **App information:** an app name you'll recognise on the consent screen
     (for example "{{site}}"), and your email as the support email.
   - **Audience:** see the choice below.
   - **Contact information:** your email.
   - Tick the agreement and click **Create**.
2. **Choose the audience** on the [Audience page](https://console.cloud.google.com/auth/audience):
   <!-- only: gmail -->
   - **External**: for Gmail and other personal Google accounts, or a
     Workspace account outside the project's organisation. **Then click
     Publish app** under *Publishing status* and confirm, so the status reads
     **In production**.

     Publishing doesn't make anything public. It only means Google stops
     expiring your connection every 7 days. Google may say the app needs
     verification. You don't need it for your own site; see step 7.

     If you'd rather stay in Testing for now, add your Google account under
     **Test users** on the same page, and expect to reconnect weekly.
   <!-- /only -->
   <!-- only: workspace -->
   - **Internal** (Google Workspace): if you will connect an account in the
     project's own organisation. There's no Testing status, no 7-day expiry,
     and no warning screen. This is the easiest choice when available.
   <!-- /only -->
3. Optionally, on the [Branding page](https://console.cloud.google.com/auth/branding),
   check the app name and support email. A logo isn't needed, and adding one
   can trigger a verification request, so leave it empty.

### 4. Add the scopes (Data Access)

Scopes are the permissions the app may ask for. Add the ones the plugins on this
site need:

{{scopes}}

1. Open the [Data Access page](https://console.cloud.google.com/auth/scopes).
2. Click **Add or remove scopes**.
3. Under **Manually add scopes**, paste the scope URLs above, one per line.
   Click **Add to table**, make sure they're ticked, and click **Update**.
4. Click **Save**.

What they mean: `drive.file` only lets the app see files it created itself
(Google calls it "non-sensitive"), which suits backups. `drive.readonly` can read
all your Drive files and `drive` can change them; Google calls these
"restricted".
<!-- only: gmail -->
That's why step 7's warning appears.
<!-- /only -->

If you install another compatible Drive plugin later, add its scopes here too and click
**Reconnect** on the **Accounts** tab.

### 5. Create the OAuth client (Web application)

1. Open [Clients](https://console.cloud.google.com/auth/clients) and click
   **Create client**.
2. **Application type: Web application.** Not "Desktop app": only a web client
   can send you back to your site, and the site refuses a desktop client's file.
3. Name it, for example "{{site}}".
4. Leave **Authorized JavaScript origins** empty.
5. Under **Authorized redirect URIs**, click **Add URI** and paste exactly:

   ```
   {{redirect_uri}}
   ```

   It must match character for character, including `https://`, `www.` or not,
   and no trailing slash. Google only accepts **HTTPS** redirect URIs (plain
   `http://` works for `localhost` only), so the site needs a certificate. If
   this address is wrong (say the site sits behind a proxy and shows `http://`),
   fix the site's URL first; Connect will otherwise fail with
   [`redirect_uri_mismatch`](troubleshooting.md#redirect-uri-mismatch). The address
   comes from `system.custom_base_url` when that is set, otherwise from the
   request that rendered this page, so set `custom_base_url` on a site behind a
   proxy or CDN.
6. Click **Create**.
7. In the dialog that opens, click **Download JSON**. Google now shows a new
   client's secret only at this point, so download it before closing the dialog.
   If you missed it, open the client, add a new secret, and download the JSON
   again. The file name starts with `client_secret_` and it holds
   `{"web": {"client_id": …, "client_secret": …}}`.

Treat this file like a password: don't email it or commit it anywhere.

### 6. Upload the client JSON here and click Connect

1. Open the [Accounts](#accounts_tab) tab on this page.
2. Under **Add account**, type a name (for example `personal`; other plugins
   refer to the account by it), choose **OAuth**, choose the `client_secret_…json`
   file (or paste its contents), and click **Add account**.
3. Click **Connect** on the new account. A Google window opens (allow pop-ups
   for this site if nothing appears).
4. Choose the Google account whose Drive the site should use.
   <!-- only: gmail -->
5. If Google says **"Google hasn't verified this app"**, see step 7.
   <!-- /only -->
6. If Google shows permissions with boxes, tick every box. Then click
   **Continue** or **Allow**. A box left empty is a permission this site won't
   have, and the plugin that needs it fails with
   [`scope_not_granted`](troubleshooting.md#scope-not-granted).
7. The window says **Connected as you@…** and closes (close it yourself if it
   doesn't). The Accounts tab updates on its own and shows the Google account
   you chose.

Then click **Test**, and set each compatible Drive plugin to this account's name.

<!-- only: gmail -->
### 7. "Google hasn't verified this app"

When an app asks for restricted scopes (`drive.readonly` or `drive`) and Google
hasn't reviewed it, Google shows a warning before the consent screen. For your
own client on your own site that's expected:

1. Click **Advanced** (bottom left of the warning).
2. Click **Go to {{site}} (unsafe)**, or whatever you named the app.
3. Continue with the consent screen as above.

It's safe here because you are both the developer and the only user: the app's
only redirect URI is your own site, and the credentials never leave it. Google's
review exists to protect people from *other* people's apps. An unverified app is
limited to 100 users in total, which a single site never gets near. You don't
need to apply for verification.
<!-- /only -->

### Reconnecting, disconnecting and removing

- **Reconnect** (on the **Accounts** tab) runs the consent screen again. Use it
  after adding a plugin that needs a new scope, or after an `invalid_grant`.
- **Remove** revokes the site's access at Google and deletes the client and
  token files from this site.
- You can also revoke access yourself at any time under
  [Google Account → Third-party connections](https://myaccount.google.com/connections).
  The site then needs a **Reconnect**.
- Google also drops a connection that hasn't been used for about six months, or
  when you change the Google account's password and the app holds Gmail
  scopes (these plugins don't use any).
