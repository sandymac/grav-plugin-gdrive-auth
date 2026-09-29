## Service account guide

A service account is a Google identity that belongs to your Google Cloud project
instead of to a person. Your site signs in as it with a key file. It never
expires and needs no clicking through consent screens, which makes it the best
fit for a site that runs unattended.

It has one big limit: **a service account has no Drive storage of its own.** It
can read anything you share with it, but it can only *create* files inside a
**Shared Drive** (a Google Workspace feature). If you want backups in a personal
My Drive, use the [OAuth guide](oauth.md) instead.

<!-- only: gmail -->
**With a personal Google account** (Gmail, or a personal account on your own
email address) you have no Shared Drive, so a service account can't write
anything to your Drive: it can only read folders you share with it. That
suits a gallery; backups need an [OAuth account](oauth.md).
<!-- /only -->

You need a Google account that can create a Google Cloud project. The whole
thing takes about five minutes.

### 1. Create or pick a Google Cloud project

1. Open the [Google Cloud console](https://console.cloud.google.com/) and sign
   in.
2. Use the project picker at the top of the page. Pick an existing project, or
   click **New project** ([direct link](https://console.cloud.google.com/projectcreate)),
   give it a name such as "My website", and click **Create**.
3. Make sure the new project is selected in the picker before you go on. Every
   link below opens in whichever project is selected.

<!-- only: project -->
The console links below already open the project you named.
<!-- /only -->

### 2. Turn on the Google Drive API

1. Open the [Google Drive API page](https://console.cloud.google.com/apis/library/drive.googleapis.com).
2. Click **Enable**. If the button says **Manage**, it's already on.

If you skip this, **Test** fails with
[`accessNotConfigured`](troubleshooting.md#access-not-configured).

### 3. Create the service account

1. Open [IAM & Admin → Service accounts](https://console.cloud.google.com/iam-admin/serviceaccounts).
2. Click **Create service account**.
3. Give it a name, for example `grav-site`. The ID and email fill themselves in.
   The email looks like `grav-site@your-project.iam.gserviceaccount.com`.
4. Click **Create and continue**.
5. **Skip the "Grant this service account access to project" step: it needs no
   roles.** Click **Continue**, then **Done**. Drive access doesn't come from
   Cloud IAM roles. It comes from sharing folders with the account in Drive
   (step 6). Adding roles such as Owner or Editor only gives a leaked key more
   power over your Cloud project.

### 4. Create a JSON key

1. In the service-accounts list, click the account's email.
2. Open the **Keys** tab.
3. Click **Add key → Create new key**, choose **JSON**, and click **Create**.
4. Your browser downloads a `.json` file. **This file is the password.** Keep it
   somewhere safe, don't email it, and never commit it to a repository. Google
   can't show it again; if you lose it, delete the key and create a new one.

<!-- only: workspace -->
> **"Service account key creation is disabled"?** Your Google Workspace
> organisation has the policy `iam.disableServiceAccountKeyCreation` (or its
> newer managed form, `iam.managed.disableServiceAccountKeyCreation`) turned on.
> Organisations created since 2024 have it on by default.
<!-- /only -->
<!-- only: admin -->
>
> See [Key creation is blocked](troubleshooting.md#org-policy-key-creation) for
> how an organisation administrator allows keys for just this one project.
<!-- /only -->
<!-- only: workspace+not-admin -->
>
> **Not the administrator?** Then ask your Workspace administrator to allow
> service-account keys for this project: with the project selected, override
> the organisation policy `iam.disableServiceAccountKeyCreation` (and
> `iam.managed.disableServiceAccountKeyCreation`, if it's enforced) with
> **Enforcement: Off**. The steps are under
> [Key creation is blocked](troubleshooting.md#org-policy-key-creation). If
> they won't, use an [OAuth account](oauth.md) instead.
<!-- /only -->

### 5. Upload the key here

1. Open the **Accounts** tab on this page.
2. Under **Add account**, type a name (for example `site`; it's how other
   plugins refer to this account), choose **Service account**, and choose the
   `.json` file (or paste its contents).
3. Click **Add account**.

The site checks that the file really is a service-account key before storing
it. The account then shows the service account's email.

### 6. Share your folder or Shared Drive with the service account

The service account can only see what you share with it, exactly like a
person. Use its email: {{sa_emails}}

<!-- only: gallery -->
- **For a gallery (read only):** in [Google Drive](https://drive.google.com/),
  right-click the folder → **Share** → **Share**, paste the service account's
  email, choose **Viewer**, untick **Notify people** (nobody reads that inbox),
  and click **Share**.
<!-- /only -->
<!-- only: backup -->
- **For backups (writing):** use a **Shared Drive**. Open the Shared Drive,
  click its name at the top → **Manage members**, add the service account's
  email as **Content manager**, and click **Send**. Content manager lets it add,
  change and remove the files it manages, without being able to delete the
  Shared Drive itself.

Why not a folder in My Drive for backups? Files a service account creates in
your My Drive would belong to it, and it has no storage quota, so Google refuses
with [`storageQuotaExceeded`](troubleshooting.md#storage-quota-exceeded).
<!-- /only -->

### 7. Click Test

On the **Accounts** tab, click **Test** next to the account. It signs in as the
service account and asks Google Drive who it is. You should see ✔ and the
service account's email. If not, the result links to the matching
[Troubleshooting](troubleshooting.md) entry.

Then set each plugin that uses Drive (for example the gallery or backup plugin)
to this account's name, and give it the folder or Shared Drive you shared.

### Rotating or revoking the key

- **To rotate:** create a new key (step 4), upload it under the same account
  name (step 5; it replaces the old one), click **Test**, then delete the old
  key on the service account's **Keys** tab in the console.
- **If a key leaks:** delete it on the **Keys** tab right away. It stops working
  within minutes. Then create and upload a new one.
- **Removing** the account on the **Accounts** tab deletes the key file from
  this site. The key itself stays valid at Google until you delete it there.
