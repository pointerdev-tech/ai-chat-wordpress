# PointerDev AI Chat for WordPress

Official PointerAI WordPress plugin for embedding AI chat on your site.

## Installation

- Copy this plugin folder to `wp-content/plugins/pointerdev-ai-chat/` and activate it in WordPress admin.
- Or install/upload the plugin zip through **Plugins > Add New > Upload Plugin**.

## Configuration

Go to **Settings > PointerDev AI Chat** and configure:

- API Base URL
- Project ID
- Publishable Key (`pk_...`)
- Secret Key (`sk_...`) for login-required mode

## Usage

Add shortcode to any page/post:

```text
[pointerai_chat]
```

## Auth Modes

- Guest mode: set auth mode to `anonymous` (or `auto` for anonymous visitors).
- Login-required mode: set auth mode to `end_user` (or `auto` for logged-in users).
  - In this mode the plugin can mint end-user tokens server-side for logged-in users.

## Filters

- `pointerai_end_user_token`
- `pointerai_end_user_subject_seed`

## Notes

- Publishable keys (`pk_...`) are browser-safe.
- Secret keys (`sk_...`) must remain server-side only.

## Development

Run PHP syntax lint locally:

```bash
php -l pointerdev-ai-chat.php
php -l includes/class-pointerai-client.php
php -l includes/class-pointerai-plugin.php
```

## License

MIT
