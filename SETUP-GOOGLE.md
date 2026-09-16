# Google side setup (one time, about 20 minutes)

The app writes to the ten Google Calendars through a **service account**: a robot identity that Google creates
inside a Cloud project. You share each calendar with that robot's email address, exactly as you would share it with a
person, and the app signs in as the robot with a key file. Nothing expires, nobody has to click "allow", and no
password is stored anywhere.

Do all of this signed in as the **company Gmail account that owns the calendars**.

## 1. Create the Cloud project

1. Open https://console.cloud.google.com/ and accept the terms if asked (no billing is needed for this).
2. Top bar > project picker > **New project**. Name: `LSP PTO Calendar`. Location: leave "No organization". Create,
   then make sure the picker shows the new project.
3. Menu > **IAM & Admin > IAM** > Grant access: add your own address (chris@lightsaberpromotions.com) with role
   **Owner**, so two people can get into the project.

## 2. Enable the Calendar API

Menu > **APIs & Services > Library** > search "Google Calendar API" > **Enable**.

## 3. Create the service account and its key

1. Menu > **IAM & Admin > Service Accounts** > **Create service account**.
   Name: `lsp-pto-sync`. Description: "Writes PTO, birthday and event calendars for pto.lightsaberpromotions.com".
   Create and continue. Grant it **no roles** (skip both optional steps). Done.
2. Click the new account > tab **Keys** > **Add key > Create new key > JSON** > Create. A file downloads
   (`lsp-pto-calendar-xxxx.json`). Keep it private: it is the robot's password.
3. Note the account's email; it looks like `lsp-pto-sync@lsp-pto-calendar.iam.gserviceaccount.com`. The app also shows
   it on Admin > Calendars once the key file is in place.

If Google refuses to create the key with a message about an organization policy, tell me: that only happens for
Workspace organizations and there is a fallback path.

## 4. Share every calendar with the service account

In Google Calendar (web), for each of the ten calendars below: hover the calendar in the left list > three dots >
**Settings and sharing** > **Share with specific people or groups** > **Add people and groups** > paste the service
account email > permission **"Make changes to events"** > Send.

| Group | Calendar | ID starts with |
|---|---|---|
| Lightsaber Promotions | PTO / Vacation | 0ec35890 |
| Lightsaber Promotions | Birthdays | 8579146e |
| Lightsaber Promotions | Company Events & Holidays (Any Additional Events) | 4df8b9e4 |
| Lightsaber Promotions | Factory Closings | 470bb84a |
| Lightsaber Promotions | End of Month Sales | e96813af |
| Bright Bird Design | PTO | 65b050c4 |
| Bright Bird Design | Birthdays | cc0e9abc |
| Bright Bird Design | Events (Any Additional Events) | b7e4ad95 |
| Bright Bird Design | Office Closings (Factory Closings) | b734044e |
| Bright Bird Design | the fifth calendar on the Manila page (unknown purpose) | e2961e29 |

While you are in each calendar's settings, also add a second person under "Make changes and manage sharing" so the
calendars never depend on one login. Do not change the "Access permissions for events" (public) setting: the viewer
pages' calendar grids rely on it.

## 5. Put the key on the server

Upload the JSON file with cPanel File Manager to `/home/CPUSER/pto_data/google-service-account.json` and set its
permissions to 600. On your PC for local testing, copy it to `C:\Users\chris\Documents\CODE\PTO-Tracker\pto_data\`
under the same name; locally the app only ever reads from Google (it can list events and run Preview), it never
writes, whatever the settings say.

## 6. Check it

Admin > Calendars shows "Key file present, client_email ..., token OK" and each calendar's **Test connection** passes
(on the server it inserts and deletes a throwaway event dated 2000-01-01; locally it lists events instead).

Then follow the cutover order in RECOMMENDATION.md section 7 ("One-time cutover"): rehearse against throwaway
calendars, silence the Apps Script, re-import, adopt the existing events, preview, go live.
