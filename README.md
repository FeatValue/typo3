# FeatValue for TYPO3

Embeds the [FeatValue](https://www.featvalue.com/typo3) client portal in a TYPO3 site.

FeatValue is the client portal for agencies and service providers. Your team keeps working in the tools you already use (Jira, Asana, ClickUp, Trello, Basecamp and others). Clients follow tasks, leave feedback and approve work on your website.

## Requirements

- TYPO3 13.4 or 14, PHP 8.2 or later
- A [FeatValue account](https://www.featvalue.com/typo3) (a free plan is available)

Nothing is sent to FeatValue until an administrator clicks Connect.

## Installation

From the TYPO3 project root:

```
composer require featvalue/typo3
```

Or install `featvalue` from the [TYPO3 Extension Repository](https://extensions.typo3.org/extension/featvalue). Activate the extension and the site set `featvalue/dashboard` on the site that should show the portal. Then open **Web → FeatValue** and click **Connect with FeatValue**.

## Configuration

- Content element **FeatValue dashboard**, with a height setting.
- After setup, **Tasks** is available under **Web → FeatValue**.
- Optional: sign-in with TYPO3 accounts (backend and frontend users stay separate), and a mail relay via the Scheduler. A webhook with `mail_pending` pulls immediately.

## External services

After you connect, this extension talks to [FeatValue](https://www.featvalue.com/) so the client portal can run on your site.

FeatValue may then receive the site address and name, whether the site is connected, which pages show the portal, and, if you turn the options on, the name, email and user ID of signed-in TYPO3 users, or mail recipients, subject and message.

Privacy: https://www.iubenda.com/privacy-policy/16160214

Support: hello@featvalue.io
