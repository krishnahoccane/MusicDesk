# Add-on sources

Each folder here is one LabelDesk add-on (see ../docs/plugins.md). They are not part of the LabelDesk
release package: build a signed zip with `php scripts/build-plugin.php addons/<slug> --key=<signing key>`
and publish it on MusicDesk.tech.

| Folder | Add-on code | Status |
|---|---|---|
| bulk-upload | bulk_upload | moved out of core |
| rest-api | rest_api | new |
| incoming-webhooks | incoming_webhooks | new |
| sso | sso | new |
| media-storage | media_storage | moved out of core (4.3.4) |
