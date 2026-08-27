# Security policy

Security fixes target the latest `0.1.x` source until a newer release line exists.

Do not open a public issue containing credentials, private Elementor JSON, database content, or exploit details that could endanger a live site.

Security boundaries: administrator-only GitHub Device Flow; encrypted server-side tokens; custom capability plus `edit_post`; bounded JSON validation; repository identity + GitHub SHA + local canonical SHA-256; no remote WordPress-title mutation; integrity-checked snapshots; Elementor API save + readback + rollback; fixed GitHub HTTPS endpoints; no public inbound webhook; unexpected internal exceptions are not returned verbatim.

Rotating WordPress salts intentionally invalidates stored GitHub credentials and requires reconnecting GitHub. Production remains staging-first for site-specific Elementor/Pro/add-on behavior.
