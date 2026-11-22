# Single sign-on (add-on `sso`)

Lets staff sign in to LabelDesk with their work account through OpenID Connect:
Microsoft Entra ID, Google Workspace, Okta or any provider that publishes
`<issuer>/.well-known/openid-configuration`.

Settings → **Single sign-on** (needs `settings.manage`).

## Providers

| Type | What the admin enters | Issuer used |
|---|---|---|
| Microsoft Entra ID | Directory (tenant) ID (a GUID; `common`/`organizations` are refused) | `https://login.microsoftonline.com/<tenant>/v2.0` |
| Google Workspace | – | `https://accounts.google.com` (with one allowed domain, `hd=<domain>` is sent) |
| Okta | Issuer URL, e.g. `https://your-org.okta.com/oauth2/default` | as entered |
| Other OpenID Connect | Issuer or discovery URL (https only) | as entered |

Plus client ID, client secret (stored encrypted, never sent back to the browser), scopes
(default `openid email profile`) and allowed email domains (empty = any). The redirect URI to
register at the provider is shown in the form: `https://<labeldesk>/sso/<slug>/callback`
(the slug is fixed when the provider is first saved). A provider is only enabled when its
discovery document can be loaded and reports the configured issuer.

**Entra ID:** add the optional ID-token claims `email` and `xms_edov` to the app registration;
LabelDesk only trusts an email address the provider vouches for (`email_verified` or `xms_edov`).

## Sign-in flow

Authorization code flow with PKCE (S256), `state` (bound to the browser session, single use,
10 minutes) and `nonce`. The ID token signature is checked against the provider's JWKS with
OpenSSL (RS256/384/512, ES256/384/512; `none` and `HS*` are refused; the key set is cached for
an hour and re-read at most once a minute when a token uses an unknown key id), as are `iss`,
`aud`/`azp`, `exp`/`iat`/`nbf` (2 minutes leeway) and `nonce`. The client authenticates with
`client_secret_basic` (or `client_secret_post` when that is the only method offered).

Matching a LabelDesk account:

1. a user already linked to the provider's subject (`sub`);
2. else an existing user with the same **verified** email address (the link is stored in
   `sso_identities`);
3. else, only when "create accounts" is on for the provider, a new active user with the chosen
   role, if the email domain is in the allowed list (required for this option) and a license
   seat is free. Super Admin and Artist roles are never assigned this way.

Deactivated, deleted and unknown accounts get the same message. On success the session is
regenerated, `last_login_at` is recorded and the activity log gets `auth.login`
(`method: sso`). Failures are logged as `sso.failed` with a reason code only (no tokens, codes or
secrets). The core license lock applies to these routes like to every other page.

## Require single sign-on

Policy card in the settings page. When on (and at least one provider is enabled), password
sign-in is refused for every account except **Super Admins** (break-glass); artist accounts only
when "Also for artist accounts" is ticked. The check runs after the password was verified, so it
reveals nothing to someone without the password. Turning it on ends "Keep me signed in" cookies of
the affected accounts. The last enabled provider cannot be disabled or removed while it is on.
If the add-on is deactivated or its license key lapses, password sign-in works again for everyone.

## Tables

`sso_providers`, `sso_identities` (deleting a provider removes its links). Data is kept when the
add-on is deactivated or deleted.

## Tests

```
php vendor/bin/phpunit addons/sso/tests
```

The provider is faked with `Http::fake()`; `tests/keys/*.pem` are throw-away test keys (not
packaged).
