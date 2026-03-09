=== PointerDev AI Chat ===
Contributors: pointerdev
Tags: chat, ai, support, pointerdevai
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Connect your WordPress site to PointerDev AI using your agent project credentials and embed the official PointerDev AI widget.

== Description ==

PointerDev AI Chat provides:

- Admin settings page under Settings > PointerDev AI Chat
- Frontend shortcode `[pointerdevai_chat]` that loads the widget bundle
- Guest mode and login-required mode support
- Server-side end-user token mint endpoint for logged-in WordPress users
- Runtime session token lifecycle support for server-side AJAX proxy flows (exchange, refresh, revoke)

== Installation ==

1. Upload the `pointerdev-ai-chat` folder to `/wp-content/plugins/`.
2. Activate the plugin through the WordPress admin.
3. Go to Settings > PointerDev AI Chat and save:
   - API Base URL
   - Project ID
   - Publishable Key
   - Secret Key (required for login_required mode)
4. Add shortcode `[pointerdevai_chat]` to any page.

== Frequently Asked Questions ==

= How does login_required mode work? =

When `auth_mode` is `end_user` (or `auto` with logged-in users), plugin mints end-user tokens server-side using your stored secret key and feeds them to the widget.

Primary filter `pointerdevai_end_user_token` can override the generated token.
Legacy filter `pointerai_end_user_token` is still available for custom overrides.
When this filter returns a token, it overrides built-in token minting for widget auth.
The same server-side minting path is used by the plugin AJAX proxy when needed.

Default `sub` derivation is multisite-aware (`blog_id:user_id`). If you need Laravel-style parity (`user_id` only), use filter `pointerdevai_end_user_subject_seed`.
Legacy filter `pointerai_end_user_subject_seed` still works.

= Can I use this for anonymous chat? =

Yes. Set auth mode to `anonymous` or `auto`.

== Changelog ==

= 0.1.0 =
* Initial release.
